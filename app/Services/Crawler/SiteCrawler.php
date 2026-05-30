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
use App\Services\Knowledge\ContentSufficiency;
use App\Services\Knowledge\DocumentProcessor;
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
        private readonly DocumentProcessor $processor,
        private readonly ContentSufficiency $sufficiency,
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
            $pagesSkippedNoContent = 0;
            $pagesDiscovered = 0;

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

                $cleanText = $this->processor->extractHtml($body);
                $title = $this->extractTitle($body) ?: $url;

                $attributes = [
                    'tenant_id' => $tenant->id,
                    'type' => 'webpage',
                    'url_normalized' => $normalized,
                ];
                $values = [
                    'title' => $title,
                    'source_url' => $url,
                    'content' => $cleanText,
                    'metadata' => [
                        'crawl_session_id' => $session->id,
                        'content_hash' => $contentHash,
                        'last_modified' => $headResult['last_modified'],
                        'etag' => $headResult['etag'],
                    ],
                ];

                if (! $this->sufficiency->isSufficient($cleanText, $body)) {
                    $values['status'] = KnowledgeItemStatus::SkippedNoContent;
                    $values['metadata']['skipped_reason'] = 'no_content';
                    $values['metadata']['skipped_at'] = now()->toIso8601String();
                    KnowledgeItem::updateOrCreate($attributes, $values);
                    $pagesSkippedNoContent++;
                    ($this->sleeper)($crawlDelay);

                    continue;
                }

                $values['status'] = KnowledgeItemStatus::Pending;
                $item = KnowledgeItem::updateOrCreate($attributes, $values);

                try {
                    ProcessKnowledgeItem::dispatch($item);
                } catch (\Throwable $e) {
                    // Under QUEUE_CONNECTION=sync, dispatch runs the job inline
                    // and bubbles its exceptions. The item is already persisted;
                    // its embedding can retry via KnowledgeItemWorkflow::retry.
                    // Don't kill the crawl loop over one bad page.
                    Log::warning('[SiteCrawler] (NO $) Processing dispatch failed; continuing crawl', [
                        'tenant_id' => $tenant->id,
                        'item_id' => $item->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                $pagesIndexed++;

                ($this->sleeper)($crawlDelay);
            }

            $session->update([
                'pages_discovered' => $pagesDiscovered,
                'pages_indexed' => $pagesIndexed,
                'pages_failed' => $pagesFailed,
                'pages_skipped_budget' => $pagesSkippedBudget,
                'pages_skipped_unchanged' => $pagesSkippedUnchanged,
                'pages_skipped_no_content' => $pagesSkippedNoContent,
            ]);

            $status = match (true) {
                $pagesSkippedBudget > 0 => CrawlSessionStatus::Partial,
                $pagesIndexed === 0 && $pagesSkippedNoContent > 0 => CrawlSessionStatus::Partial,
                $pagesIndexed === 0 && $pagesFailed > 0 => CrawlSessionStatus::Failed,
                $pagesFailed > 0 && $pagesIndexed > 0 => CrawlSessionStatus::Partial,
                $pagesSkippedNoContent > 0 && $pagesIndexed > 0 => CrawlSessionStatus::Partial,
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
    private function probeHeaders(string $url, KnowledgeItem $existing): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => RobotsTxtPolicy::USER_AGENT_HEADER])
                ->head($url);

            $lastModified = $response->header('Last-Modified') ?: null;
            $etag = $response->header('ETag') ?: null;

            $priorLm = $existing->metadata['last_modified'] ?? null;
            $priorEtag = $existing->metadata['etag'] ?? null;
            if (($priorLm !== null && $priorLm === $lastModified)
                || ($priorEtag !== null && $priorEtag === $etag)) {
                return ['skip' => true, 'last_modified' => $lastModified, 'etag' => $etag];
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
