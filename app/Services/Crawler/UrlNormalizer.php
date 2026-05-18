<?php

declare(strict_types=1);

namespace App\Services\Crawler;

class UrlNormalizer
{
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'ref', '_ga', 'mc_eid', 'mc_cid',
    ];

    public function normalize(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';

        if ($port !== null && ! self::isDefaultPort($scheme, (int) $port)) {
            $host .= ':'.$port;
        }

        if ($path === '/' || $path === '') {
            $path = '';
        }

        $queryString = '';
        if ($query !== '') {
            parse_str($query, $params);
            $filtered = array_filter(
                $params,
                fn (int|string $key) => ! in_array($key, self::TRACKING_PARAMS, true),
                ARRAY_FILTER_USE_KEY,
            );
            ksort($filtered);
            if ($filtered !== []) {
                $queryString = '?'.http_build_query($filtered);
            }
        }

        return "{$scheme}://{$host}{$path}{$queryString}";
    }

    /**
     * Returns true if $a and $b refer to the same host (case-insensitive, www-stripped).
     * Used by the crawler to filter same-host links.
     */
    public function sameHost(string $a, string $b): bool
    {
        return self::canonicalHost($a) === self::canonicalHost($b);
    }

    private static function canonicalHost(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host)) {
            return null;
        }
        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private static function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    }
}
