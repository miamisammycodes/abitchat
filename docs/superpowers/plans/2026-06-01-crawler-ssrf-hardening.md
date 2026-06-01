# Crawler SSRF Hardening + Render Robustness — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the crawler's HTTP-fetch SSRF (redirect + DNS-rebinding) in-app across all five Guzzle fetchers, make the Chromium render path rebinding-safe via a Node validate-and-pin egress proxy with a fail-closed interlock, harden the `SafeExternalUrl` denylist, and fix two bundled robustness bugs.

**Architecture:** One PHP IP-classification primitive (`SafeExternalUrl`) feeds a shared `GuardedHttpClient` (manual redirect loop + `CURLOPT_RESOLVE` pin) and is mirrored once into JS (`private-cidr.mjs`, parity-tested) for a localhost-bound CONNECT proxy that Chromium is forced through. Sequenced so the pure-PHP live-vuln fix lands as an independently-green commit first.

**Tech Stack:** Laravel 13 / PHP 8.3, Guzzle 7.10 (PHP curl handler), Pest, Spatie Browsershot + puppeteer 25, Node 22 (`node:test`), pnpm.

**Spec:** `docs/superpowers/specs/2026-06-01-crawler-ssrf-hardening-design.md`

---

## File Structure

| File | Responsibility |
|------|----------------|
| `app/Rules/SafeExternalUrl.php` (mod) | IP/URL classification primitive: `isSafeIp`, `resolvePublicIps`, hardened `isPrivateIp` |
| `app/Exceptions/BlockedAddressException.php` (new) | Thrown when a fetch target/hop resolves to a non-public address |
| `app/Services/Crawler/GuardedHttpClient.php` (new) | Shared guarded GET/HEAD: manual redirect loop + per-hop validate + `CURLOPT_RESOLVE` pin |
| `app/Services/Crawler/SiteCrawler.php` (mod) | Route fetchBody/probeHeaders; healCandidate reason; render budget |
| `app/Services/Crawler/RobotsTxtPolicy.php` (mod) | Route fetchFor (was unguarded) |
| `app/Services/Crawler/SitemapDiscoverer.php` (mod) | Route fetchSitemap/extractLinks |
| `app/Services/Crawler/PageRenderer.php` (mod) | `enabled()` interlock; proxy wiring |
| `app/Services/Crawler/RenderOnFallback.php` (mod) | `resolve()` `$allowRender` param |
| `app/Jobs/ProcessKnowledgeItem.php` (mod) | Distinct `no_chunks` skip reason |
| `resources/node/private-cidr.mjs` (new) | JS mirror of `isPrivateIp` (incl. normalization) |
| `resources/node/egress-proxy.mjs` (new) | localhost CONNECT/forward proxy: resolve→validate→connect-to-pinned-IP |
| `resources/node/*.test.mjs` (new) | `node:test` coverage for classifier + proxy |
| `tests/fixtures/ssrf-ip-cases.json` (new) | Shared adversarial IP table (PHP + JS + parity) |
| `config/services.php` (mod) | `egress_proxy`, `render_budget` keys |

---

## Task 0: CURLOPT_RESOLVE + resolver-consistency probe — ✅ DONE (2026-06-01)

Verification-only; results recorded here so downstream tasks rely on verified behavior, not assumption.

- [x] `CURLOPT_RESOLVE` is honored through `Http::withOptions(['curl' => [CURLOPT_RESOLVE => [...]]])` — pin→real IP returned 200; pin→`192.0.2.1` (TEST-NET blackhole) threw `Illuminate\Http\Client\ConnectionException`.
- [x] `Http::withOptions(['allow_redirects' => false])` returns the 3xx with the `Location` header (`http://github.com` → 301 `https://github.com/`).
- [x] No curl-handle stickiness on this stack: a second pin to the same `host:port` in-process was honored (fresh handle per request). The evict-then-set form (`['-host:port', 'host:port:ip']`) also works (→200) and is kept as defense.
- [x] `dns_get_record(host, DNS_A | DNS_AAAA)` returns A-only for IPv4 hosts and AAAA-only for IPv6-only hosts → `resolvePublicIps` must merge both record types.

**Decision locked:** pin format `host:port:ip1,ip2,…` (comma-joined A+AAAA), emitted with a leading `-host:port` evict entry. No code change required from this task.

---

## Task 1: Harden `SafeExternalUrl` + adversarial fixture

**Files:**
- Modify: `app/Rules/SafeExternalUrl.php`
- Create: `tests/fixtures/ssrf-ip-cases.json`
- Test: `tests/Unit/Rules/SafeExternalUrlTest.php` (extend)

- [ ] **Step 1: Write the shared adversarial fixture**

Create `tests/fixtures/ssrf-ip-cases.json` — `{ "<ip>": <isPrivateExpected> }`. Consumed by this task's PHP test, the JS classifier test (Task 4), and the parity test (Task 4).

```json
{
  "1.1.1.1": false,
  "104.20.23.154": false,
  "8.8.8.8": false,
  "0.0.0.0": true,
  "127.0.0.1": true,
  "127.0.0.53": true,
  "10.0.0.5": true,
  "172.16.0.1": true,
  "192.168.1.1": true,
  "169.254.169.254": true,
  "169.254.0.1": true,
  "100.64.0.1": true,
  "100.127.255.255": true,
  "100.128.0.1": false,
  "224.0.0.1": true,
  "239.255.255.255": true,
  "240.0.0.1": true,
  "::1": true,
  "fc00::1": true,
  "fd12:3456::1": true,
  "fe80::1": true,
  "ff02::1": true,
  "2404:6800:4002:817::200e": false,
  "2606:4700:4700::1111": false,
  "::ffff:127.0.0.1": true,
  "::ffff:10.0.0.1": true,
  "::ffff:1.1.1.1": false,
  "64:ff9b::a00:1": true,
  "64:ff9b::1.1.1.1": false
}
```

- [ ] **Step 2: Write the failing test (fixture-driven + new helpers)**

Append to `tests/Unit/Rules/SafeExternalUrlTest.php`:

```php
test('isSafeIp matches the shared adversarial fixture', function () {
    $cases = json_decode(file_get_contents(base_path('tests/fixtures/ssrf-ip-cases.json')), true);

    foreach ($cases as $ip => $expectedPrivate) {
        expect(\App\Rules\SafeExternalUrl::isSafeIp($ip))
            ->toBe(! $expectedPrivate, "isSafeIp({$ip}) should be ".($expectedPrivate ? 'false' : 'true'));
    }
});

test('resolvePublicIps returns the validated set for a literal public IP', function () {
    expect(\App\Rules\SafeExternalUrl::resolvePublicIps('1.1.1.1'))->toBe(['1.1.1.1']);
});

test('resolvePublicIps fails closed for a literal private IP', function () {
    expect(\App\Rules\SafeExternalUrl::resolvePublicIps('127.0.0.1'))->toBe([]);
    expect(\App\Rules\SafeExternalUrl::resolvePublicIps('169.254.169.254'))->toBe([]);
});

test('resolvePublicIps fails closed for an unresolvable host', function () {
    expect(\App\Rules\SafeExternalUrl::resolvePublicIps('no-such-host.invalid'))->toBe([]);
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=SafeExternalUrlTest`
Expected: FAIL — `isSafeIp`/`resolvePublicIps` undefined; several CGNAT/multicast/NAT64 cases fail.

- [ ] **Step 4: Implement the hardening**

In `app/Rules/SafeExternalUrl.php`, add the two public methods, the CIDR denylist + helper, and NAT64 normalization. Replace the `isPrivateIp` body and the docstring at line 25-28.

```php
    /** Extra ranges filter_var's NO_PRIV_RANGE|NO_RES_RANGE does NOT cover. */
    private const EXTRA_DENY_CIDRS = [
        '100.64.0.0/10',  // CGNAT — reachable internal in many clouds
        '224.0.0.0/4',    // IPv4 multicast
        '240.0.0.0/4',    // IPv4 reserved
        'ff00::/8',       // IPv6 multicast
    ];

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

        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip === null) {
                continue;
            }
            if (self::isPrivateIp($ip)) {
                return [];
            }
            $ips[] = $ip;
        }

        return array_values(array_unique($ips));
    }
```

Replace the existing `isPrivateIp` with the normalization + denylist version:

```php
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
```

Update the class docstring at lines 25-28: delete "Re-callable at fetch time to defeat DNS rebinding." Replace with:

```php
    /**
     * Returns true if $url has a parseable host that resolves to public IPs only.
     * NOTE: this validates the NAME only — curl re-resolves at connect, so this
     * does NOT by itself close DNS rebinding. Rebinding is closed by pinning the
     * connection to resolvePublicIps()'s set (see GuardedHttpClient).
     */
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=SafeExternalUrlTest`
Expected: PASS (all cases). Note: the two `dns_get_record`-backed tests need network; if the sandbox blocks DNS, they degrade to fail-closed (`[]`) which still satisfies the private-host assertions but NOT the literal-IP ones (those need no DNS and must pass).

- [ ] **Step 6: Run the full suite + PHPStan**

Run: `php artisan test && ./vendor/bin/phpstan analyse`
Expected: green, 0 errors.

- [ ] **Step 7: Commit**

```bash
git add app/Rules/SafeExternalUrl.php tests/Unit/Rules/SafeExternalUrlTest.php tests/fixtures/ssrf-ip-cases.json
git commit -m "feat(ssrf): harden SafeExternalUrl denylist + expose isSafeIp/resolvePublicIps"
```

---

## Task 2: `GuardedHttpClient` + `BlockedAddressException`

**Files:**
- Create: `app/Exceptions/BlockedAddressException.php`
- Create: `app/Services/Crawler/GuardedHttpClient.php`
- Test: `tests/Unit/Services/Crawler/GuardedHttpClientTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Crawler/GuardedHttpClientTest.php`. Uses IP-literal hosts so `resolvePublicIps` takes the no-DNS literal branch.

```php
<?php

use App\Exceptions\BlockedAddressException;
use App\Services\Crawler\GuardedHttpClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->client = new GuardedHttpClient;
});

test('returns a successful response for a public target', function () {
    Http::fake(['http://1.1.1.1/page' => Http::response('hello', 200)]);

    $response = $this->client->get('http://1.1.1.1/page');

    expect($response->successful())->toBeTrue();
    expect($response->body())->toBe('hello');
});

test('follows a redirect to a public target', function () {
    Http::fake([
        'http://1.1.1.1/start' => Http::response('', 301, ['Location' => 'http://1.0.0.1/end']),
        'http://1.0.0.1/end' => Http::response('done', 200),
    ]);

    $response = $this->client->get('http://1.1.1.1/start');

    expect($response->body())->toBe('done');
    Http::assertSentCount(2);
});

test('blocks and never sends a redirect hop to a private address', function () {
    Http::fake([
        'http://1.1.1.1/start' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest']),
        'http://169.254.169.254/*' => Http::response('SECRET', 200),
    ]);

    expect(fn () => $this->client->get('http://1.1.1.1/start'))
        ->toThrow(BlockedAddressException::class);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
});

test('rejects a private initial URL before any request', function () {
    Http::fake();

    expect(fn () => $this->client->get('http://127.0.0.1/'))
        ->toThrow(BlockedAddressException::class);

    Http::assertNothingSent();
});

test('rejects a disallowed scheme', function () {
    Http::fake();

    expect(fn () => $this->client->get('file:///etc/passwd'))
        ->toThrow(BlockedAddressException::class);

    Http::assertNothingSent();
});

test('aborts after exceeding the redirect cap', function () {
    Http::fake([
        'http://1.1.1.1/*' => Http::response('', 301, ['Location' => 'http://1.1.1.1/next']),
    ]);

    expect(fn () => $this->client->get('http://1.1.1.1/loop'))
        ->toThrow(BlockedAddressException::class);
});

test('head issues a HEAD request', function () {
    Http::fake(['http://1.1.1.1/' => Http::response('', 200, ['ETag' => 'abc'])]);

    $response = $this->client->head('http://1.1.1.1/');

    expect($response->header('ETag'))->toBe('abc');
    Http::assertSent(fn ($request) => $request->method() === 'HEAD');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=GuardedHttpClientTest`
Expected: FAIL — classes not defined.

- [ ] **Step 3: Implement `BlockedAddressException`**

Create `app/Exceptions/BlockedAddressException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Thrown when a crawl fetch target or redirect hop resolves to a non-public address. */
class BlockedAddressException extends RuntimeException {}
```

- [ ] **Step 4: Implement `GuardedHttpClient`**

Create `app/Services/Crawler/GuardedHttpClient.php`:

```php
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
            if ($location === '' || $location === null) {
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
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=GuardedHttpClientTest`
Expected: PASS (all 7).

- [ ] **Step 6: Commit**

```bash
git add app/Exceptions/BlockedAddressException.php app/Services/Crawler/GuardedHttpClient.php tests/Unit/Services/Crawler/GuardedHttpClientTest.php
git commit -m "feat(ssrf): GuardedHttpClient — manual redirect loop + CURLOPT_RESOLVE pin"
```

---

## Task 3: Route all 5 Guzzle fetchers through `GuardedHttpClient` ← live-vuln closed

**Files:**
- Modify: `app/Services/Crawler/SiteCrawler.php` (fetchBody, probeHeaders, remove line-104 isSafe)
- Modify: `app/Services/Crawler/RobotsTxtPolicy.php` (fetchFor)
- Modify: `app/Services/Crawler/SitemapDiscoverer.php` (fetchSitemap, extractLinks, remove isSafe at 69/148)
- Test: existing `tests/Unit/Services/Crawler/{SiteCrawlerTest,RobotsTxtPolicyTest,SitemapDiscovererTest}.php`

- [ ] **Step 1: Write the failing test (robots.txt redirect-to-private is blocked)**

This is the headline live-vuln. Add to `tests/Unit/Services/Crawler/RobotsTxtPolicyTest.php`:

```php
test('fetchFor never follows a redirect to a private address', function () {
    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake([
        'http://1.1.1.1/robots.txt' => Illuminate\Support\Facades\Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        'http://169.254.169.254/*' => Illuminate\Support\Facades\Http::response('SECRET', 200),
    ]);

    $policy = app(App\Services\Crawler\RobotsTxtPolicy::class)->fetchFor('http://1.1.1.1/');

    // Blocked hop is swallowed → permissive policy, and the metadata endpoint was never hit.
    expect($policy->crawlDelaySeconds())->toBe(1);
    Illuminate\Support\Facades\Http::assertNotSent(fn ($r) => str_contains($r->url(), '169.254.169.254'));
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RobotsTxtPolicyTest`
Expected: FAIL — current `fetchFor` uses raw `Http` and follows the redirect (the assertNotSent fails).

- [ ] **Step 3: Route `RobotsTxtPolicy::fetchFor`**

In `app/Services/Crawler/RobotsTxtPolicy.php`: add constructor injection and replace the `Http::` call.

```php
    public function __construct(private GuardedHttpClient $http) {}
```

Replace lines 29-46 (`try { $response = Http::timeout(5)... }`) with:

```php
        try {
            $response = $this->http->get($host.'/robots.txt', ['User-Agent' => self::USER_AGENT_HEADER], 5);

            if (! $response->successful()) {
                return $this->cache[$host] = $this->permissivePolicy();
            }

            return $this->cache[$host] = $this->parse($response->body());
        } catch (\Throwable $e) {
            Log::debug('[RobotsTxt] (NO $) Fetch failed/blocked; using permissive policy', [
                'root' => $rootUrl,
                'error' => $e->getMessage(),
            ]);

            return $this->cache[$host] = $this->permissivePolicy();
        }
```

Remove the now-unused `use Illuminate\Support\Facades\Http;`. Add `use App\Services\Crawler\GuardedHttpClient;` (same namespace — no import needed). The `scoped` binding in `AppServiceProvider:36` auto-resolves the new ctor dep (no closure change needed).

- [ ] **Step 4: Run the robots test to verify it passes**

Run: `php artisan test --filter=RobotsTxtPolicyTest`
Expected: PASS.

- [ ] **Step 5: Route `SiteCrawler` (fetchBody + probeHeaders) and remove the line-104 guard**

In `app/Services/Crawler/SiteCrawler.php`:

Add to the constructor (after `RenderOnFallback $resolver,`):
```php
        private readonly GuardedHttpClient $http,
```

Remove the standalone guard at lines 104-108 (`if (! SafeExternalUrl::isSafe($url)) { $pagesFailed++; continue; }`) — validation now lives in the client. Keep the `use App\Rules\SafeExternalUrl;` import ONLY if still referenced elsewhere; otherwise remove it.

Replace `probeHeaders` body (lines 260-263) — swap `Http::timeout(10)->withHeaders(...)->head($url)` for:
```php
            $response = $this->http->head($url, ['User-Agent' => RobotsTxtPolicy::USER_AGENT_HEADER], 10);
```

Replace `fetchBody` body (lines 283-298) — swap the `Http::timeout(...)->...->get($url)` block for:
```php
        try {
            $response = $this->http->get($url, ['User-Agent' => RobotsTxtPolicy::USER_AGENT_HEADER], self::REQUEST_TIMEOUT_SECONDS);

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
```

Remove the now-unused `use Illuminate\Support\Facades\Http;` if no other `Http::` calls remain in the file.

- [ ] **Step 6: Route `SitemapDiscoverer` (fetchSitemap + extractLinks) and remove isSafe at 69/148**

In `app/Services/Crawler/SitemapDiscoverer.php`:

Constructor → add `private readonly GuardedHttpClient $http`:
```php
    public function __construct(
        private readonly RobotsTxtPolicy $robotsTxt,
        private readonly UrlNormalizer $normalizer,
        private readonly GuardedHttpClient $http,
    ) {}
```

`fetchSitemap` — remove the `if (! SafeExternalUrl::isSafe($sitemapUrl)) { return; }` at lines 69-71 and replace `Http::timeout(10)->...->get($sitemapUrl)` (lines 74-76) with:
```php
        try {
            $response = $this->http->get($sitemapUrl, ['User-Agent' => RobotsTxtPolicy::USER_AGENT_HEADER], 10);
            if (! $response->successful()) {
                return;
            }
```

`extractLinks` — remove the `if (! SafeExternalUrl::isSafe($url)) { return []; }` at lines 148-150 and replace `Http::timeout(10)->...->get($url)` (lines 153-155) with:
```php
        try {
            $response = $this->http->get($url, ['User-Agent' => RobotsTxtPolicy::USER_AGENT_HEADER], 10);
            if (! $response->successful()) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }
```

Remove `use App\Rules\SafeExternalUrl;` and `use Illuminate\Support\Facades\Http;` (no longer referenced).

- [ ] **Step 7: Run the full crawler suite**

Run: `php artisan test --filter='SiteCrawler|SitemapDiscoverer|RobotsTxtPolicy'`
Expected: PASS. Existing tests that `Http::fake` public IP literals continue to pass (the client forwards them); fix any that asserted the old `isSafe` short-circuit by pointing them at the new client behavior (a private target now throws inside the client and is caught → same skip/failed outcome).

- [ ] **Step 8: Run the full suite + PHPStan**

Run: `php artisan test && ./vendor/bin/phpstan analyse`
Expected: green, 0 errors. **This is the independently-green live-vuln commit.**

- [ ] **Step 9: Commit**

```bash
git add app/Services/Crawler/SiteCrawler.php app/Services/Crawler/RobotsTxtPolicy.php app/Services/Crawler/SitemapDiscoverer.php tests/Unit/Services/Crawler
git commit -m "feat(ssrf): route all 5 crawler fetchers through GuardedHttpClient

Closes redirect-SSRF + DNS-rebinding on fetchBody/probeHeaders/robots/
sitemap/extractLinks; robots.txt fetch is now guarded for the first time."
```

---

## Task 4: JS classifier `private-cidr.mjs` + parity test

**Files:**
- Create: `resources/node/private-cidr.mjs`
- Create: `resources/node/private-cidr.test.mjs`
- Test: `tests/Unit/Security/SsrfParityTest.php`

- [ ] **Step 1: Write the failing JS test (fixture-driven)**

Create `resources/node/private-cidr.test.mjs`:

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { isPrivateIp } from './private-cidr.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const cases = JSON.parse(readFileSync(join(here, '../../tests/fixtures/ssrf-ip-cases.json'), 'utf8'));

test('isPrivateIp matches the shared adversarial fixture', () => {
  for (const [ip, expected] of Object.entries(cases)) {
    assert.equal(isPrivateIp(ip), expected, `isPrivateIp(${ip}) should be ${expected}`);
  }
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `node --test resources/node/private-cidr.test.mjs`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement `private-cidr.mjs`**

Create `resources/node/private-cidr.mjs`. Uses Node 22's `net.BlockList` for CIDR matching + a byte expander mirroring PHP's `inet_pton` normalization.

```js
import net from 'node:net';

// Mirror of app/Rules/SafeExternalUrl::isPrivateIp. Kept in lockstep by
// tests/Unit/Security/SsrfParityTest.php — change both or the parity test fails.
const blocks = new net.BlockList();
blocks.addSubnet('0.0.0.0', 8, 'ipv4');
blocks.addSubnet('10.0.0.0', 8, 'ipv4');
blocks.addSubnet('100.64.0.0', 10, 'ipv4');
blocks.addSubnet('127.0.0.0', 8, 'ipv4');
blocks.addSubnet('169.254.0.0', 16, 'ipv4');
blocks.addSubnet('172.16.0.0', 12, 'ipv4');
blocks.addSubnet('192.0.0.0', 24, 'ipv4');
blocks.addSubnet('192.168.0.0', 16, 'ipv4');
blocks.addSubnet('198.18.0.0', 15, 'ipv4');
blocks.addSubnet('224.0.0.0', 4, 'ipv4');
blocks.addSubnet('240.0.0.0', 4, 'ipv4');
blocks.addAddress('::1', 'ipv6');
blocks.addSubnet('fc00::', 7, 'ipv6');
blocks.addSubnet('fe80::', 10, 'ipv6');
blocks.addSubnet('ff00::', 8, 'ipv6');

/** Expand an IPv6 string to its 16 bytes, or null if not IPv6. */
function v6Bytes(ip) {
  if (!net.isIPv6(ip)) return null;
  let [head, tail] = ip.split('::');
  const headGroups = head ? head.split(':') : [];
  const tailGroups = tail !== undefined ? (tail ? tail.split(':') : []) : null;

  // An embedded dotted IPv4 in the last group (e.g. ::ffff:1.2.3.4) → two hextets.
  const toHextets = (groups) => {
    const out = [];
    for (const g of groups) {
      if (g.includes('.')) {
        const o = g.split('.').map(Number);
        out.push(((o[0] << 8) | o[1]) >>> 0, ((o[2] << 8) | o[3]) >>> 0);
      } else {
        out.push(parseInt(g || '0', 16));
      }
    }
    return out;
  };

  let hextets;
  if (tailGroups === null) {
    hextets = toHextets(headGroups);
  } else {
    const h = toHextets(headGroups);
    const t = toHextets(tailGroups);
    const fill = 8 - h.length - t.length;
    hextets = [...h, ...Array(fill).fill(0), ...t];
  }

  const bytes = [];
  for (const x of hextets) {
    bytes.push((x >> 8) & 0xff, x & 0xff);
  }
  return bytes.length === 16 ? bytes : null;
}

/** Normalize IPv4-mapped (::ffff:/96) and NAT64 (64:ff9b::/96) to the embedded IPv4. */
function normalize(ip) {
  if (net.isIPv4(ip)) return ip;
  const b = v6Bytes(ip);
  if (b === null) return ip; // not normalizable here

  const isMapped = b.slice(0, 10).every((x) => x === 0) && b[10] === 0xff && b[11] === 0xff;
  const isNat64 = b[0] === 0x00 && b[1] === 0x64 && b[2] === 0xff && b[3] === 0x9b
    && b.slice(4, 12).every((x) => x === 0);
  if (isMapped || isNat64) {
    return `${b[12]}.${b[13]}.${b[14]}.${b[15]}`;
  }
  return ip;
}

export function isPrivateIp(ip) {
  if (ip === '0.0.0.0' || ip === '::') return true;

  const norm = normalize(ip);
  if (net.isIPv4(norm)) return blocks.check(norm, 'ipv4');
  if (net.isIPv6(norm)) return blocks.check(norm, 'ipv6');
  return true; // unparseable → fail closed
}
```

- [ ] **Step 4: Run JS test to verify it passes**

Run: `node --test resources/node/private-cidr.test.mjs`
Expected: PASS (all fixture cases).

- [ ] **Step 5: Write the PHP parity test (failing first)**

Create `tests/Unit/Security/SsrfParityTest.php`. Shells `node` to classify every fixture IP via the JS module and asserts it matches `SafeExternalUrl::isSafeIp`.

```php
<?php

use App\Rules\SafeExternalUrl;

test('JS isPrivateIp matches PHP SafeExternalUrl for every fixture IP', function () {
    $fixture = base_path('tests/fixtures/ssrf-ip-cases.json');
    $module = base_path('resources/node/private-cidr.mjs');
    $cases = json_decode(file_get_contents($fixture), true);

    $script = sprintf(
        'import { isPrivateIp } from %s; '.
        'import { readFileSync } from "node:fs"; '.
        'const c = JSON.parse(readFileSync(%s, "utf8")); '.
        'const out = {}; for (const ip of Object.keys(c)) out[ip] = isPrivateIp(ip); '.
        'process.stdout.write(JSON.stringify(out));',
        json_encode($module),
        json_encode($fixture),
    );

    $result = Illuminate\Support\Facades\Process::run(['node', '--input-type=module', '-e', $script]);

    expect($result->successful())->toBeTrue($result->errorOutput());
    $jsVerdicts = json_decode($result->output(), true);

    foreach ($cases as $ip => $_) {
        expect($jsVerdicts[$ip])->toBe(
            ! SafeExternalUrl::isSafeIp($ip),
            "JS and PHP disagree on {$ip}",
        );
    }
})->skip(fn () => ! shell_exec('command -v node'), 'node not available');
```

- [ ] **Step 6: Run the parity test**

Run: `php artisan test --filter=SsrfParityTest`
Expected: PASS (or SKIP if node is not on PATH in this shell — it must pass in CI/dev where node exists).

- [ ] **Step 7: Commit**

```bash
git add resources/node/private-cidr.mjs resources/node/private-cidr.test.mjs tests/Unit/Security/SsrfParityTest.php
git commit -m "feat(ssrf): JS private-IP classifier mirroring SafeExternalUrl + parity test"
```

---

## Task 5: Node egress proxy `egress-proxy.mjs`

**Files:**
- Create: `resources/node/egress-proxy.mjs`
- Create: `resources/node/egress-proxy.test.mjs`

- [ ] **Step 1: Write the failing JS test (reject-at-connect)**

Create `resources/node/egress-proxy.test.mjs`. Starts the proxy, asserts a CONNECT to a private target is refused with 403 and a literal-public target reaches the connect attempt.

```js
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import net from 'node:net';
import { startProxy } from './egress-proxy.mjs';

let proxy;
let port;

before(async () => {
  proxy = await startProxy(0); // ephemeral port
  port = proxy.address().port;
});

after(() => proxy.close());

function connectThrough(target) {
  return new Promise((resolve, reject) => {
    const sock = net.connect(port, '127.0.0.1', () => {
      sock.write(`CONNECT ${target} HTTP/1.1\r\nHost: ${target}\r\n\r\n`);
    });
    let buf = '';
    sock.on('data', (d) => {
      buf += d.toString();
      if (buf.includes('\r\n\r\n')) { sock.end(); resolve(buf.split('\r\n')[0]); }
    });
    sock.on('error', reject);
    sock.setTimeout(3000, () => { sock.destroy(); reject(new Error('timeout')); });
  });
}

test('refuses CONNECT to a private literal IP', async () => {
  const statusLine = await connectThrough('127.0.0.1:443');
  assert.match(statusLine, /403/);
});

test('refuses CONNECT to the cloud metadata IP', async () => {
  const statusLine = await connectThrough('169.254.169.254:80');
  assert.match(statusLine, /403/);
});

test('refuses CONNECT to a host that resolves to a private IP', async () => {
  // localhost resolves to 127.0.0.1 → must be rejected by resolve-and-validate.
  const statusLine = await connectThrough('localhost:443');
  assert.match(statusLine, /403/);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `node --test resources/node/egress-proxy.test.mjs`
Expected: FAIL — module/`startProxy` not defined.

- [ ] **Step 3: Implement `egress-proxy.mjs`**

Create `resources/node/egress-proxy.mjs`:

```js
import net from 'node:net';
import http from 'node:http';
import dns from 'node:dns/promises';
import { isPrivateIp } from './private-cidr.mjs';

const BIND = '127.0.0.1';

/**
 * Resolve a hostname and return its validated public IPs, or null (fail closed)
 * if it is unresolvable or ANY address is private/reserved. Literal IPs are
 * validated directly. The caller connects to the returned IP — so the IP
 * validated is the IP connected to (no rebinding TOCTOU).
 */
async function resolvePublicIps(hostname) {
  if (net.isIP(hostname)) {
    return isPrivateIp(hostname) ? null : [hostname];
  }
  let addrs;
  try {
    addrs = await dns.lookup(hostname, { all: true });
  } catch {
    return null;
  }
  if (!addrs.length) return null;
  const ips = addrs.map((a) => a.address);
  if (ips.some(isPrivateIp)) return null;
  return ips;
}

function reject(socket) {
  socket.write('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n');
  socket.end();
}

export function startProxy(port = Number(process.env.CRAWLER_EGRESS_PROXY_PORT) || 8118) {
  const server = http.createServer(async (req, res) => {
    // Plain-HTTP forward: Chromium sends an absolute-form request URI.
    let target;
    try {
      target = new URL(req.url);
    } catch {
      res.writeHead(400).end();
      return;
    }
    const ips = await resolvePublicIps(target.hostname);
    if (!ips) {
      res.writeHead(403).end();
      return;
    }
    const upstream = http.request(
      {
        host: ips[0], // pin to validated IP
        port: target.port || 80,
        path: target.pathname + target.search,
        method: req.method,
        headers: { ...req.headers, host: target.host },
      },
      (up) => { res.writeHead(up.statusCode || 502, up.headers); up.pipe(res); },
    );
    upstream.on('error', () => res.writeHead(502).end());
    req.pipe(upstream);
  });

  server.on('connect', async (req, clientSocket, head) => {
    const [hostname, portStr] = req.url.split(':');
    const port = Number(portStr) || 443;

    const ips = await resolvePublicIps(hostname);
    if (!ips) {
      reject(clientSocket);
      return;
    }

    const serverSocket = net.connect(port, ips[0], () => {
      clientSocket.write('HTTP/1.1 200 Connection Established\r\n\r\n');
      serverSocket.write(head);
      serverSocket.pipe(clientSocket);
      clientSocket.pipe(serverSocket);
    });
    serverSocket.on('error', () => clientSocket.end());
    clientSocket.on('error', () => serverSocket.end());
  });

  return new Promise((resolve) => {
    server.listen(port, BIND, () => {
      // eslint-disable-next-line no-console
      console.log(`[egress-proxy] listening ${BIND}:${server.address().port}`);
      resolve(server);
    });
  });
}

// Run standalone when invoked directly (node resources/node/egress-proxy.mjs [port]).
if (import.meta.url === `file://${process.argv[1]}`) {
  startProxy(Number(process.argv[2]) || undefined);
}
```

- [ ] **Step 4: Run JS test to verify it passes**

Run: `node --test resources/node/egress-proxy.test.mjs`
Expected: PASS (all 3 reject cases).

- [ ] **Step 5: Commit**

```bash
git add resources/node/egress-proxy.mjs resources/node/egress-proxy.test.mjs
git commit -m "feat(ssrf): Node validate-and-pin egress proxy for the Chromium render path"
```

---

## Task 6: `PageRenderer` interlock + proxy wiring + config + composer dev

**Files:**
- Modify: `config/services.php` (crawler block)
- Modify: `app/Services/Crawler/PageRenderer.php` (enabled() interlock + proxy args)
- Modify: `composer.json` (dev script)
- Modify: `.env.example`
- Test: `tests/Unit/Services/Crawler/PageRendererTest.php`

- [ ] **Step 1: Write the failing interlock test**

In `tests/Unit/Services/Crawler/PageRendererTest.php`:

```php
test('enabled() is false when js_rendering is on but no egress proxy is configured', function () {
    config(['services.crawler.js_rendering' => true, 'services.crawler.egress_proxy' => null]);
    expect((new App\Services\Crawler\PageRenderer)->enabled())->toBeFalse();
});

test('enabled() is true only when js_rendering and egress proxy are both set', function () {
    config(['services.crawler.js_rendering' => true, 'services.crawler.egress_proxy' => '127.0.0.1:8118']);
    expect((new App\Services\Crawler\PageRenderer)->enabled())->toBeTrue();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=PageRendererTest`
Expected: FAIL — `enabled()` ignores the proxy; first test returns true.

- [ ] **Step 3: Add config keys**

In `config/services.php`, extend the `crawler` block (after `chrome_path`):
```php
        'egress_proxy' => env('CRAWLER_EGRESS_PROXY'),
        'render_budget' => (int) env('CRAWLER_RENDER_BUDGET', 25),
```

- [ ] **Step 4: Implement the interlock + proxy wiring in `PageRenderer`**

Replace `enabled()`:
```php
    public function enabled(): bool
    {
        return (bool) config('services.crawler.js_rendering', false)
            && filled(config('services.crawler.egress_proxy'));
    }
```

In `render()`, after `->timeout(...)` and before the binary `if` blocks, add the proxy wiring:
```php
            $shot->setProxyServer((string) config('services.crawler.egress_proxy'))
                ->addChromiumArguments(['proxy-bypass-list' => '<-loopback>']);
```

(The `enabled()` gate guarantees `egress_proxy` is non-empty whenever `render()` runs.)

- [ ] **Step 5: Run the PageRenderer test to verify it passes**

Run: `php artisan test --filter=PageRendererTest`
Expected: PASS. Update any pre-existing test that set only `js_rendering=true` and expected `enabled()` true — it must now also set `egress_proxy`.

- [ ] **Step 6: Wire the proxy into `composer dev` and `.env.example`**

In `composer.json`, the `dev` script runs `concurrently`; add the proxy process to its command list, e.g. add this entry to the concurrently args:
```
"node resources/node/egress-proxy.mjs 8118"
```
(Match the existing array formatting in the `dev` script — add it alongside the queue/vite/serve entries with a label like `--names '...,proxy'`.)

In `.env.example`, under the crawler block (near `CRAWLER_JS_RENDERING`):
```
# Egress proxy for headless rendering (REQUIRED to enable CRAWLER_JS_RENDERING).
# Render is fail-closed: rendering is OFF unless this points at the running
# validate-and-pin proxy (resources/node/egress-proxy.mjs).
CRAWLER_EGRESS_PROXY=127.0.0.1:8118
# Max headless renders per crawl session (0 = unlimited).
CRAWLER_RENDER_BUDGET=25
```

- [ ] **Step 7: Run the full suite + PHPStan**

Run: `php artisan test && ./vendor/bin/phpstan analyse`
Expected: green, 0 errors.

- [ ] **Step 8: Commit**

```bash
git add config/services.php app/Services/Crawler/PageRenderer.php composer.json .env.example tests/Unit/Services/Crawler/PageRendererTest.php
git commit -m "feat(ssrf): fail-closed render interlock + egress-proxy wiring (<-loopback>)"
```

---

## Task 7: Empty-chunk heal-loop fix

**Files:**
- Modify: `app/Jobs/ProcessKnowledgeItem.php` (distinct `no_chunks` reason)
- Modify: `app/Services/Crawler/SiteCrawler.php` (healCandidate excludes non-heal reasons)
- Test: `tests/Unit/Services/Crawler/SiteCrawlerTest.php` + a ProcessKnowledgeItem test

- [ ] **Step 1: Write the failing test (zero-chunk page is not a heal candidate)**

Add to `tests/Unit/Services/Crawler/SiteCrawlerTest.php` (follow the file's existing fixture/helper style):

```php
test('a page skipped for no_chunks is not re-fetched as a heal candidate', function () {
    config(['services.crawler.js_rendering' => true, 'services.crawler.egress_proxy' => '127.0.0.1:8118']);

    $tenant = makeTenantWithSite();   // existing helper in this test file
    $session = makeCrawlSession($tenant);

    // An existing item previously skipped because it chunked to [] (not insufficient).
    App\Models\KnowledgeItem::factory()->for($tenant)->create([
        'type' => 'webpage',
        'url_normalized' => 'https://1.1.1.1/empty',
        'status' => App\Enums\KnowledgeItemStatus::SkippedNoContent,
        'metadata' => ['skipped_reason' => 'no_chunks', 'render_attempted_at' => null],
    ]);

    Illuminate\Support\Facades\Http::preventStrayRequests();
    Illuminate\Support\Facades\Http::fake([
        'https://1.1.1.1/empty' => Illuminate\Support\Facades\Http::response('', 304),
    ]);
    // sitemap returns the one URL — reuse the file's sitemap helper.

    app(App\Services\Crawler\SiteCrawler::class)->crawl($tenant, $session);

    // It must NOT be re-fetched as a heal candidate (GET never issued for /empty).
    Illuminate\Support\Facades\Http::assertNotSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/empty'));
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter='SiteCrawlerTest'`
Expected: FAIL — current `healCandidate` only checks status + `render_attempted_at`, so the `no_chunks` page is treated as a heal candidate and re-fetched.

- [ ] **Step 3: Implement the distinct skip reason**

In `app/Jobs/ProcessKnowledgeItem.php`, change `markSkipped` to accept a reason:
```php
    private function markSkipped(KnowledgeItemWorkflow $workflow, string $reason = 'no_content'): void
    {
        $this->item->chunks()->delete();
        $this->item->forceFill([
            'metadata' => array_merge((array) $this->item->metadata, [
                'skipped_reason' => $reason,
                'skipped_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $workflow->markSkippedNoContent($this->item);

        Log::debug('[Knowledge] (NO $) Item skipped', ['item_id' => $this->item->id, 'reason' => $reason]);
    }
```

Update the two call sites: insufficient (line 47) stays `$this->markSkipped($workflow)`; the zero-chunk branch (line 59) becomes `$this->markSkipped($workflow, 'no_chunks');`.

- [ ] **Step 4: Implement the healCandidate exclusion**

In `app/Services/Crawler/SiteCrawler.php`, change the `$healCandidate` definition (lines 120-123) to require a heal-eligible reason (only the content-insufficient case can be helped by rendering):
```php
                $healCandidate = $this->resolver->renderingEnabled()
                    && $existing !== null
                    && $existing->status === KnowledgeItemStatus::SkippedNoContent
                    && ($existing->metadata['skipped_reason'] ?? 'no_content') === 'no_content'
                    && empty($existing->metadata['render_attempted_at'] ?? null);
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test --filter='SiteCrawlerTest|ProcessKnowledgeItem'`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/ProcessKnowledgeItem.php app/Services/Crawler/SiteCrawler.php tests/Unit/Services/Crawler/SiteCrawlerTest.php
git commit -m "fix(crawler): zero-chunk pages get a non-heal skip reason (stop re-fetch loop)"
```

---

## Task 8: Per-crawl render budget

**Files:**
- Modify: `app/Services/Crawler/RenderOnFallback.php` (`$allowRender` param)
- Modify: `app/Services/Crawler/SiteCrawler.php` (count renders, gate on budget)
- Test: `tests/Unit/Services/Crawler/RenderOnFallbackTest.php` + `SiteCrawlerTest.php`

- [ ] **Step 1: Write the failing test (resolve honors $allowRender=false)**

Add to `tests/Unit/Services/Crawler/RenderOnFallbackTest.php` (uses the file's Mockery `PageRenderer` injection style):

```php
test('resolve does not render when allowRender is false', function () {
    $renderer = Mockery::mock(App\Services\Crawler\PageRenderer::class);
    $renderer->shouldReceive('enabled')->andReturn(true);
    $renderer->shouldNotReceive('render');   // budget exhausted → never render

    $resolver = new App\Services\Crawler\RenderOnFallback(
        app(App\Services\Knowledge\DocumentProcessor::class),
        app(App\Services\Knowledge\ContentSufficiency::class),
        $renderer,
    );

    $result = $resolver->resolve('https://1.1.1.1/x', '<html><body>hi</body></html>', allowRender: false);

    expect($result['rendered'])->toBeFalse();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=RenderOnFallbackTest`
Expected: FAIL — `resolve()` has no `$allowRender` parameter.

- [ ] **Step 3: Add the `$allowRender` parameter**

In `app/Services/Crawler/RenderOnFallback.php`, change the signature and short-circuit:
```php
    public function resolve(string $url, string $httpBody, bool $allowRender = true): array
    {
        $text = $this->processor->extractHtml($httpBody);

        if ($this->gate->isSufficient($text, $httpBody)) {
            return ['text' => $text, 'sufficient' => true, 'rendered' => false];
        }

        if (! $allowRender) {
            return ['text' => $text, 'sufficient' => false, 'rendered' => false];
        }

        $rendered = $this->renderer->render($url);
        // ... unchanged below ...
```

- [ ] **Step 4: Gate the budget in `SiteCrawler::crawl`**

Add a counter near the other `$pages*` counters (after line 78):
```php
            $rendersUsed = 0;
            $renderBudget = (int) config('services.crawler.render_budget', 25);
```

Change the `resolve` call (line 158) to pass the budget gate:
```php
                $allowRender = $renderBudget === 0 || $rendersUsed < $renderBudget;
                $resolution = $this->resolver->resolve($url, $body, $allowRender);
```

After the `render_attempted_at` stamp block (after line 186), increment the counter when a render actually executed:
```php
                if ($resolution['rendered']) {
                    $rendersUsed++;
                }
```

- [ ] **Step 5: Write + run the budget cap test**

Add to `tests/Unit/Services/Crawler/SiteCrawlerTest.php`: a crawl with `render_budget=1` and two SPA-shell pages asserts at most one render executes (assert via the rendered-stamp count or a mocked renderer's call count, matching the file's existing mocking approach). Run:

Run: `php artisan test --filter='RenderOnFallbackTest|SiteCrawlerTest'`
Expected: PASS.

- [ ] **Step 6: Run the full suite + PHPStan**

Run: `php artisan test && ./vendor/bin/phpstan analyse`
Expected: green, 0 errors.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Crawler/RenderOnFallback.php app/Services/Crawler/SiteCrawler.php tests/Unit/Services/Crawler
git commit -m "feat(crawler): per-crawl render budget (CRAWLER_RENDER_BUDGET)"
```

---

## Task 9: Docs — ops egress firewall + deploy steps

**Files:**
- Modify: `.env.example` (already done in Task 6 — verify)
- Create/Modify: a short ops note in the PR description / `docs/` (deploy steps)

- [ ] **Step 1: Document the recommended OS egress firewall (defense-in-depth)**

Add a section to the PR description (and optionally `docs/superpowers/specs/...` appendix) stating: the validate-and-pin proxy + `<-loopback>` is the rebinding-complete in-app control; the OS-level egress firewall scoped to a dedicated Chromium uid/netns (drop `127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, 100.64/10, ::1, fc00::/7, fe80::/10`) is recommended additional defense — NOT a hard prerequisite for the flag now that the proxy closes rebinding. Must be scoped to the Chromium child only (the worker itself needs private-IP DB/Redis).

- [ ] **Step 2: Document the deploy/runtime requirements**

Deploy steps (for the PR body):
1. Run the egress proxy as a supervised process bound to localhost: `node resources/node/egress-proxy.mjs <port>` (or via the process manager).
2. Set `CRAWLER_EGRESS_PROXY=127.0.0.1:<port>`.
3. Only then may `CRAWLER_JS_RENDERING=true` be set (interlock keeps render off until the proxy is configured).
4. `node --test resources/node/` must pass in CI.

- [ ] **Step 3: Commit**

```bash
git add .env.example docs
git commit -m "docs(ssrf): ops egress-firewall note + render-proxy deploy steps"
```

---

## Final verification (before PR)

- [ ] **Full PHP suite:** `php artisan test` — all green.
- [ ] **PHPStan:** `./vendor/bin/phpstan analyse` — 0 errors.
- [ ] **JS tests:** `node --test resources/node/` — all green.
- [ ] **Pint:** `./vendor/bin/pint --test` (scope to touched files); fix + recommit if flagged.
- [ ] **Browser smoke:** with the proxy running + `CRAWLER_JS_RENDERING=true` + `CRAWLER_EGRESS_PROXY` set locally, crawl a known SPA tenant and confirm it still indexes (proxy does not break legitimate rendering); confirm a `localhost`/private `website_url` is refused.
- [ ] **`/simplify` → Pint → `/simplify` → Pint** per project process.

---

## Self-review notes

- **Spec coverage:** A→Task 1; B→Tasks 2-3; C→Tasks 4-6; D→Task 7; E→Task 8; failure-behavior→Tasks 2-3 (throw caught as skip); Task 0→done; out-of-scope respected (no firewall provisioning, flag stays off).
- **Type consistency:** `resolvePublicIps(): list<string>`, `isSafeIp(): bool`, `GuardedHttpClient::get/head(string,array,int): Response`, `BlockedAddressException`, `RenderOnFallback::resolve(string,string,bool)`, `markSkipped(KnowledgeItemWorkflow,string)`, JS `isPrivateIp(ip): boolean` / `startProxy(port): Promise<Server>` — referenced consistently across tasks.
- **Sequencing:** Tasks 1-3 are pure PHP and leave main green at Task 3 (live vuln closed) before any JS/proxy work.
