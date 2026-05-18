<?php

declare(strict_types=1);

namespace App\Services\Crawler;

final class RobotsPolicy
{
    /**
     * @param  list<string>  $disallowPaths
     * @param  list<string>  $allowPaths
     * @param  list<string>  $sitemapUrls
     */
    public function __construct(
        private readonly array $disallowPaths,
        private readonly array $allowPaths,
        private readonly int $crawlDelaySeconds,
        private readonly array $sitemapUrls,
    ) {}

    public function isAllowed(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        $longestMatch = '';
        $allowed = true;

        foreach ($this->disallowPaths as $disallow) {
            if ($disallow !== '' && str_starts_with($path, $disallow) && strlen($disallow) > strlen($longestMatch)) {
                $longestMatch = $disallow;
                $allowed = false;
            }
        }

        foreach ($this->allowPaths as $allow) {
            if ($allow !== '' && str_starts_with($path, $allow) && strlen($allow) > strlen($longestMatch)) {
                $longestMatch = $allow;
                $allowed = true;
            }
        }

        return $allowed;
    }

    public function crawlDelaySeconds(): int
    {
        return $this->crawlDelaySeconds;
    }

    /** @return list<string> */
    public function sitemapUrls(): array
    {
        return $this->sitemapUrls;
    }
}
