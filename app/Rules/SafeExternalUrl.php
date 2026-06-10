<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeExternalUrl implements ValidationRule
{
    /** Extra ranges filter_var's NO_PRIV_RANGE|NO_RES_RANGE does NOT cover. */
    private const EXTRA_DENY_CIDRS = [
        '100.64.0.0/10',  // CGNAT — reachable internal in many clouds
        '224.0.0.0/4',    // IPv4 multicast
        '240.0.0.0/4',    // IPv4 reserved
        'ff00::/8',       // IPv6 multicast
    ];

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
     * NOTE: this validates the NAME only — curl re-resolves at connect, so this
     * does NOT by itself close DNS rebinding. Rebinding is closed by pinning the
     * connection to resolvePublicIps()'s set (see GuardedHttpClient).
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

    public static function isSafeIp(string $ip): bool
    {
        return ! self::isPrivateIp($ip);
    }

    /**
     * Resolve all A/AAAA records once and return the validated public IP set.
     * Fails closed (returns []) when the host is unresolvable or ANY record is
     * private/reserved. Callers pin the connection to this exact set, so the
     * IP validated is the IP connected to — closing the DNS-rebinding TOCTOU.
     *
     * @return list<string>
     */
    public static function resolvePublicIps(string $host): array
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPrivateIp($host) ? [] : [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            return [];
        }

        $ips = self::publicIpsFromRecords($records);

        return $ips === null ? [] : array_values(array_unique($ips));
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

        return self::publicIpsFromRecords($records) === null;
    }

    /**
     * Classify a dns_get_record result: return the resolved IPs, or null if ANY
     * record points at a private/reserved address. The single fail-closed
     * classifier shared by isPrivateHost and resolvePublicIps so the two paths
     * cannot drift.
     *
     * @param  array<int, array<string, mixed>>  $records
     * @return list<string>|null
     */
    private static function publicIpsFromRecords(array $records): ?array
    {
        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip === null) {
                continue;
            }
            if (self::isPrivateIp($ip)) {
                return null;
            }
            $ips[] = $ip;
        }

        return $ips;
    }

    /**
     * Determines whether $ip points at a private/reserved address.
     * Handles IPv4-mapped IPv6 (any textual form) by normalizing via
     * inet_pton, so DNS-resolved AAAA records in alternate notations
     * cannot bypass the check.
     */
    private static function isPrivateIp(string $ip): bool
    {
        if ($ip === '0.0.0.0' || $ip === '::') {
            return true;
        }

        $packed = @inet_pton($ip);
        if ($packed !== false && strlen($packed) === 16) {
            // IPv4-mapped IPv6 (::ffff:x.x.x.x and every textual variant).
            if (substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
                $embedded = inet_ntop(substr($packed, 12));
                if ($embedded !== false) {
                    return self::isPrivateIp($embedded);
                }
            }
            // NAT64 well-known prefix 64:ff9b::/96 embeds an IPv4 in the last 4 bytes.
            if (substr($packed, 0, 12) === "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00") {
                $embedded = inet_ntop(substr($packed, 12));
                if ($embedded !== false) {
                    return self::isPrivateIp($embedded);
                }
            }
        }

        foreach (self::EXTRA_DENY_CIDRS as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return ! filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $maskBits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maskBits = (int) $maskBits;
        $fullBytes = intdiv($maskBits, 8);
        $remainder = $maskBits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }
        if ($remainder > 0) {
            $mask = 0xFF << (8 - $remainder) & 0xFF;
            if ((ord($ipBin[$fullBytes]) & $mask) !== (ord($subnetBin[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}
