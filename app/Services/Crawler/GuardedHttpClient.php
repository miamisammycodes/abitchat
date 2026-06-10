<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\Exceptions\BlockedAddressException;
use App\Rules\SafeExternalUrl;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared SSRF-guarded HTTP client for the crawler. Drives redirects manually
 * (allow_redirects=>false) so EVERY hop is re-validated, and pins each request
 * to the host's pre-validated IP set via CURLOPT_RESOLVE — closing both the
 * redirect-SSRF and the DNS-rebinding TOCTOU. Throws BlockedAddressException on
 * any non-public hop; all call sites already catch \Throwable and treat it as a
 * fetch failure (skip + count failed).
 */
class GuardedHttpClient
{
    private const MAX_REDIRECTS = 5;

    private const ALLOWED_SCHEMES = ['http', 'https'];

    /** @param  array<string, string>  $headers */
    public function get(string $url, array $headers = [], int $timeout = 30): Response
    {
        return $this->send('GET', $url, $headers, $timeout);
    }

    /** @param  array<string, string>  $headers */
    public function head(string $url, array $headers = [], int $timeout = 10): Response
    {
        return $this->send('HEAD', $url, $headers, $timeout);
    }

    /** @param  array<string, string>  $headers */
    private function send(string $method, string $url, array $headers, int $timeout): Response
    {
        $current = $url;
        $hops = 0;

        while (true) {
            $this->assertScheme($current);

            $host = parse_url($current, PHP_URL_HOST);
            if (! is_string($host) || $host === '') {
                throw new BlockedAddressException("Unparseable host: {$current}");
            }

            $scheme = strtolower((string) parse_url($current, PHP_URL_SCHEME));
            $port = parse_url($current, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80);

            $ips = SafeExternalUrl::resolvePublicIps($host);
            if ($ips === []) {
                Log::warning('[GuardedHttp] (NO $) Blocked non-public address', ['host' => $host, 'url' => $url]);
                throw new BlockedAddressException("Blocked non-public address: {$host}");
            }

            $resolveEntry = sprintf('%s:%d:%s', $host, $port, implode(',', $ips));

            $request = Http::withHeaders($headers)
                ->timeout($timeout)
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => ["-{$host}:{$port}", $resolveEntry]],
                ]);

            $response = $method === 'HEAD' ? $request->head($current) : $request->get($current);

            if (! $response->redirect()) {
                return $response;
            }

            $location = $response->header('Location');
            if ($location === '') {
                return $response;
            }

            if (++$hops > self::MAX_REDIRECTS) {
                throw new BlockedAddressException("Too many redirects from {$url}");
            }

            $current = $this->resolveLocation($current, $location);
        }
    }

    private function assertScheme(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new BlockedAddressException("Disallowed scheme: {$scheme}");
        }
    }

    private function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, (int) strrpos($path, '/') + 1) ?: '/';

        return $origin.$dir.$location;
    }
}
