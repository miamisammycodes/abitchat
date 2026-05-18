<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RobotsTxtPolicy
{
    private const USER_AGENT = 'ChatbotIndexer';

    public const USER_AGENT_HEADER = self::USER_AGENT.'/1.0';

    private const DEFAULT_CRAWL_DELAY_SECONDS = 1;

    /** @var array<string, RobotsPolicy> */
    private array $cache = [];

    public function fetchFor(string $rootUrl): RobotsPolicy
    {
        $host = parse_url($rootUrl, PHP_URL_SCHEME).'://'.parse_url($rootUrl, PHP_URL_HOST);

        if (isset($this->cache[$host])) {
            return $this->cache[$host];
        }

        try {
            $response = Http::timeout(5)
                ->withHeaders(['User-Agent' => self::USER_AGENT_HEADER])
                ->get($host.'/robots.txt');

            if (! $response->successful()) {
                return $this->cache[$host] = $this->permissivePolicy();
            }

            return $this->cache[$host] = $this->parse($response->body());
        } catch (\Throwable $e) {
            Log::debug('[RobotsTxt] (IS $) Fetch failed; using permissive policy', [
                'root' => $rootUrl,
                'error' => $e->getMessage(),
            ]);

            return $this->cache[$host] = $this->permissivePolicy();
        }
    }

    private function permissivePolicy(): RobotsPolicy
    {
        return new RobotsPolicy(
            disallowPaths: [],
            allowPaths: [],
            crawlDelaySeconds: self::DEFAULT_CRAWL_DELAY_SECONDS,
            sitemapUrls: [],
        );
    }

    private function parse(string $body): RobotsPolicy
    {
        $lines = preg_split('/\r\n|\r|\n/', $body);

        $specificDisallow = [];
        $specificAllow = [];
        $specificDelay = null;

        $wildcardDisallow = [];
        $wildcardAllow = [];
        $wildcardDelay = null;

        $sitemaps = [];

        $currentAgent = null;

        foreach ($lines as $rawLine) {
            $line = trim((string) preg_replace('/#.*$/', '', $rawLine));
            if ($line === '') {
                continue;
            }

            [$directive, $value] = array_pad(array_map('trim', explode(':', $line, 2)), 2, '');
            $directive = strtolower((string) $directive);
            $value = (string) $value;

            if ($directive === 'sitemap' && $value !== '') {
                $sitemaps[] = $value;

                continue;
            }

            if ($directive === 'user-agent') {
                $currentAgent = strtolower($value);

                continue;
            }

            $isSpecific = $currentAgent === strtolower(self::USER_AGENT);
            $isWildcard = $currentAgent === '*';

            if (! $isSpecific && ! $isWildcard) {
                continue;
            }

            switch ($directive) {
                case 'disallow':
                    if ($value !== '') {
                        $isSpecific ? $specificDisallow[] = $value : $wildcardDisallow[] = $value;
                    }
                    break;
                case 'allow':
                    if ($value !== '') {
                        $isSpecific ? $specificAllow[] = $value : $wildcardAllow[] = $value;
                    }
                    break;
                case 'crawl-delay':
                    $parsed = (int) $value;
                    if ($parsed > 0) {
                        $isSpecific ? $specificDelay = $parsed : $wildcardDelay = $parsed;
                    }
                    break;
            }
        }

        // When a specific UA section exists, merge wildcard + specific rules so that
        // specific Allow/Disallow entries override the wildcard baseline via longest-match.
        if ($specificDisallow !== [] || $specificAllow !== [] || $specificDelay !== null) {
            $disallow = array_merge($wildcardDisallow, $specificDisallow);
            $allow = array_merge($wildcardAllow, $specificAllow);
        } else {
            $disallow = $wildcardDisallow;
            $allow = $wildcardAllow;
        }
        $delay = $specificDelay ?? $wildcardDelay ?? self::DEFAULT_CRAWL_DELAY_SECONDS;

        return new RobotsPolicy(
            disallowPaths: $disallow,
            allowPaths: $allow,
            crawlDelaySeconds: $delay,
            sitemapUrls: $sitemaps,
        );
    }
}
