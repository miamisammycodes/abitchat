<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\Enums\CrawlSessionStatus;
use App\Enums\KnowledgeItemStatus;
use App\Jobs\ProcessKnowledgeItem;
use App\Models\CrawlSession;
use App\Models\CrawlUrlBlocklist;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Rules\SafeExternalUrl;
use App\Services\Usage\UsageTracker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SiteCrawler
{
    private const MAX_PAGES = 100;

    private const MAX_BYTES_PER_PAGE = 5 * 1024 * 1024;

    private const REQUEST_TIMEOUT_SECONDS = 30;

    /** @var \Closure(int): void */
    private \Closure $sleeper;

    public function __construct(
        private readonly SitemapDiscoverer $discoverer,
        private readonly RobotsTxtPolicy $robotsTxt,
        private readonly UrlNormalizer $normalizer,
        private readonly UsageTracker $usage,
    ) {
        $this->sleeper = static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Replace the sleep behaviour. Used in tests to keep crawl-delay loops fast.
     *
     * @param  \Closure(int): void  $sleeper
     */
    public function setSleeper(\Closure $sleeper): void
    {
        $this->sleeper = $sleeper;
    }

    public function crawl(Tenant $tenant, CrawlSession $session): void
    {
        $rootUrl = $tenant->website_url;
        if ($rootUrl === null || $rootUrl === '') {
            $this->finalize($session, CrawlSessionStatus::Failed, 'Tenant has no website_url');

            return;
        }

        $session->update([
            'status' => CrawlSessionStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $robots = $this->robotsTxt->fetchFor($rootUrl);
            $crawlDelay = max(1, $robots->crawlDelaySeconds());

            $blocklistCollection = CrawlUrlBlocklist::forTenant($tenant)
                ->pluck('url_normalized');

            $pagesIndexed = 0;
            $pagesFailed = 0;
            $pagesSkippedBudget = 0;
            $pagesSkippedUnchanged = 0;
            $pagesDiscovered = 0;
            $emptyExtractCount = 0;

            foreach ($this->discoverer->discover($rootUrl) as $url) {
                $pagesDiscovered++;
                if ($pagesDiscovered > self::MAX_PAGES) {
                    break;
                }

                $normalized = $this->normalizer->normalize($url);

                if ($blocklistCollection->contains($normalized)) {
                    continue;
                }

                if (! $robots->isAllowed($url)) {
                    $pagesFailed++;

                    continue;
                }

                if (! $this->usage->canRecordUsage($tenant, UsageTracker::TYPE_KNOWLEDGE_ITEMS)
                    || ! $this->usage->canRecordUsage($tenant, UsageTracker::TYPE_TOKENS)) {
                    $pagesSkippedBudget++;
                    break;
                }

                if (! SafeExternalUrl::isSafe($url)) {
                    $pagesFailed++;

                    continue;
                }

                $existing = KnowledgeItem::forTenant($tenant)
                    ->where('type', 'webpage')
                    ->where('url_normalized', $normalized)
                    ->first();

                $headResult = $existing !== null
                    ? $this->probeHeaders($url, $existing)
                    : ['skip' => false, 'last_modified' => null, 'etag' => null];

                if ($headResult['skip']) {
                    $pagesSkippedUnchanged++;

                    continue;
                }

                $body = $this->fetchBody($url);
                if ($body === null) {
                    $pagesFailed++;

                    continue;
                }
                if (trim(strip_tags($body)) === '') {
                    // Empty after tag-strip = JS-rendered site or no text content.
                    // Do NOT create a KnowledgeItem — ProcessKnowledgeItem would
                    // throw "No content could be extracted" and retry 3 times.
                    $emptyExtractCount++;
                    $pagesFailed++;

                    continue;
                }

                $contentHash = 'sha256:'.hash('sha256', $body);
                if ($existing && ($existing->metadata['content_hash'] ?? null) === $contentHash) {
                    $metadata = array_merge((array) $existing->metadata, [
                        'last_modified' => $headResult['last_modified'],
                        'etag' => $headResult['etag'],
                        'crawl_session_id' => $session->id,
                    ]);
                    $existing->update(['metadata' => $metadata]);
                    $pagesSkippedUnchanged++;

                    continue;
                }

                $title = $this->extractTitle($body) ?: $url;

                $item = KnowledgeItem::updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'type' => 'webpage',
                        'url_normalized' => $normalized,
                    ],
                    [
                        'title' => $title,
                        'source_url' => $url,
                        'content' => $body,
                        'status' => KnowledgeItemStatus::Pending,
                        'metadata' => [
                            'crawl_session_id' => $session->id,
                            'content_hash' => $contentHash,
                            'last_modified' => $headResult['last_modified'],
                            'etag' => $headResult['etag'],
                        ],
                    ],
                );

                ProcessKnowledgeItem::dispatch($item);
                $pagesIndexed++;

                ($this->sleeper)($crawlDelay);
            }

            $session->update([
                'pages_discovered' => $pagesDiscovered,
                'pages_indexed' => $pagesIndexed,
                'pages_failed' => $pagesFailed,
                'pages_skipped_budget' => $pagesSkippedBudget,
                'pages_skipped_unchanged' => $pagesSkippedUnchanged,
            ]);

            $status = match (true) {
                $pagesSkippedBudget > 0 => CrawlSessionStatus::Partial,
                $pagesIndexed === 0 && $emptyExtractCount > 0 => CrawlSessionStatus::Partial,
                $pagesFailed > 0 && $pagesIndexed > 0 => CrawlSessionStatus::Partial,
                $pagesIndexed === 0 && $pagesFailed > 0 => CrawlSessionStatus::Failed,
                default => CrawlSessionStatus::Completed,
            };

            $this->finalize($session, $status);
        } catch (\Throwable $e) {
            Log::error('[SiteCrawler] (IS $) Crawl failed', [
                'tenant_id' => $tenant->id,
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
            $this->finalize($session, CrawlSessionStatus::Failed, $e->getMessage());

            throw $e;
        }
    }

    /**
     * @return array{skip: bool, last_modified: ?string, etag: ?string}
     */
    private function probeHeaders(string $url, ?KnowledgeItem $existing): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => RobotsTxtPolicy::USER_AGENT_HEADER])
                ->head($url);

            $lastModified = $response->header('Last-Modified') ?: null;
            $etag = $response->header('ETag') ?: null;

            if ($existing !== null) {
                $priorLm = $existing->metadata['last_modified'] ?? null;
                $priorEtag = $existing->metadata['etag'] ?? null;
                if (($priorLm !== null && $priorLm === $lastModified)
                    || ($priorEtag !== null && $priorEtag === $etag)) {
                    return ['skip' => true, 'last_modified' => $lastModified, 'etag' => $etag];
                }
            }

            return ['skip' => false, 'last_modified' => $lastModified, 'etag' => $etag];
        } catch (\Throwable) {
            return ['skip' => false, 'last_modified' => null, 'etag' => null];
        }
    }

    private function fetchBody(string $url): ?string
    {
        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->withHeaders(['User-Agent' => RobotsTxtPolicy::USER_AGENT_HEADER])
                ->withOptions(['allow_redirects' => true])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();
            if (strlen($body) > self::MAX_BYTES_PER_PAGE) {
                return null;
            }

            return $body;
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractTitle(string $html): ?string
    {
        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $m)) {
            return trim(html_entity_decode(strip_tags($m[1])));
        }

        return null;
    }

    private function finalize(CrawlSession $session, CrawlSessionStatus $status, ?string $error = null): void
    {
        $session->update([
            'status' => $status,
            'completed_at' => now(),
            'error_message' => $error,
        ]);
    }
}
