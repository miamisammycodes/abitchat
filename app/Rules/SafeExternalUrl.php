<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeExternalUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        if (! self::isSafe($value)) {
            $fail('The :attribute points to a non-public address.');
        }
    }

    /**
     * Returns true if $url has a parseable host that resolves to public IPs only.
     * Re-callable at fetch time to defeat DNS rebinding.
     */
    public static function isSafe(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        // parse_url leaves brackets on IPv6 literals — strip them.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return ! self::isPrivateHost($host);
    }

    private static function isPrivateHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPrivateIp($host);
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false || $records === []) {
            return true;
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if ($ip !== null && self::isPrivateIp($ip)) {
                return true;
            }
        }

        return false;
    }

    private static function isPrivateIp(string $ip): bool
    {
        if ($ip === '0.0.0.0' || $ip === '::') {
            return true;
        }

        // IPv4-mapped IPv6: ::ffff:x.x.x.x — extract the embedded IPv4 and recheck.
        if (stripos($ip, '::ffff:') === 0) {
            $embedded = substr($ip, 7);
            if (filter_var($embedded, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return self::isPrivateIp($embedded);
            }
        }

        return ! filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
