<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\Rules\SafeExternalUrl;
use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SitemapDiscoverer
{
    private const MAX_BFS_DEPTH = 3;

    private const MAX_BFS_PAGES = 100;

    public function __construct(
        private readonly RobotsTxtPolicy $robotsTxt,
        private readonly UrlNormalizer $normalizer,
    ) {}

    /**
     * @return Generator<int, string>
     */
    public function discover(string $rootUrl): Generator
    {
        $robots = $this->robotsTxt->fetchFor($rootUrl);

        $sitemapUrls = $robots->sitemapUrls();
        if ($sitemapUrls === []) {
            $sitemapUrls = [rtrim($rootUrl, '/').'/sitemap.xml'];
        }

        $seen = [];
        $yielded = 0;

        foreach ($sitemapUrls as $sitemapUrl) {
            foreach ($this->fetchSitemap($sitemapUrl) as $url) {
                if (! $this->normalizer->sameHost($url, $rootUrl)) {
                    continue;
                }
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $yielded++;
                yield $url;

                if ($yielded >= self::MAX_BFS_PAGES) {
                    return;
                }
            }
        }

        if ($yielded > 0) {
            return;
        }

        // BFS fallback
        yield from $this->bfs($rootUrl);
    }

    /**
     * @return Generator<int, string>
     */
    private function fetchSitemap(string $sitemapUrl): Generator
    {
        if (! SafeExternalUrl::isSafe($sitemapUrl)) {
            return;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => RobotsTxtPolicy::USER_AGENT_HEADER])
                ->get($sitemapUrl);
            if (! $response->successful()) {
                return;
            }

            $xml = @simplexml_load_string($response->body());
            if ($xml === false) {
                return;
            }

            foreach ($xml->url ?? [] as $url) {
                $loc = (string) ($url->loc ?? '');
                if ($loc === '') {
                    continue;
                }
                // Some sites (e.g. laravel-news.com) list nested sitemaps inside
                // <url> instead of <sitemap>. Recurse on anything ending in .xml
                // so we don't try to index the sitemap itself as a page.
                if (self::looksLikeSitemap($loc)) {
                    yield from $this->fetchSitemap($loc);

                    continue;
                }
                yield $loc;
            }

            // Sitemap index (nested sitemaps — spec-compliant form)
            foreach ($xml->sitemap ?? [] as $sitemap) {
                $nested = (string) ($sitemap->loc ?? '');
                if ($nested !== '') {
                    yield from $this->fetchSitemap($nested);
                }
            }
        } catch (\Throwable $e) {
            Log::debug('[Sitemap] (IS $) Fetch failed', ['url' => $sitemapUrl, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return Generator<int, string>
     */
    private function bfs(string $rootUrl): Generator
    {
        $queue = [[$rootUrl, 0]];
        $seen = [$rootUrl => true];
        $yielded = 0;

        while ($queue !== [] && $yielded < self::MAX_BFS_PAGES) {
            [$current, $depth] = array_shift($queue);

            yield $current;
            $yielded++;

            if ($depth >= self::MAX_BFS_DEPTH) {
                continue;
            }

            foreach ($this->extractLinks($current, $rootUrl) as $link) {
                if (isset($seen[$link])) {
                    continue;
                }
                $seen[$link] = true;
                $queue[] = [$link, $depth + 1];
            }
        }
    }

    /**
     * @return list<string>
     */
    private function extractLinks(string $url, string $rootUrl): array
    {
        if (! SafeExternalUrl::isSafe($url)) {
            return [];
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => RobotsTxtPolicy::USER_AGENT_HEADER])
                ->get($url);
            if (! $response->successful()) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$response->body());
        libxml_clear_errors();

        $links = [];
        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = $anchor->getAttribute('href');
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                continue;
            }

            $resolved = $this->resolveUrl($href, $url);
            if ($resolved === null) {
                continue;
            }
            if (! $this->normalizer->sameHost($resolved, $rootUrl)) {
                continue;
            }

            $links[] = $resolved;
        }

        return $links;
    }

    private function resolveUrl(string $href, string $base): ?string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        $parts = parse_url($base);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'].'://'.$parts['host'];
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        return $origin.'/'.ltrim($href, '/');
    }

    private static function looksLikeSitemap(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && (str_ends_with($path, '.xml') || str_ends_with($path, '.xml.gz'));
    }
}
