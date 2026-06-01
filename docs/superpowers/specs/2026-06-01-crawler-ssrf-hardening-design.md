# Crawler SSRF Hardening + Render Robustness — Design

**Date:** 2026-06-01
**Status:** Approved (brainstorm complete)
**Author:** Sameer + Claude
**Related:** [[scraping-clean-extraction-pr41]] (PR #41), [[phase2-headless-render-pr42]] (PR #42)

---

## 1. Problem

The website crawler reaches tenant-controlled URLs from six egress points. Only the
*initial* URL is validated against private/reserved IPs, and that validation re-resolves
DNS independently of the actual connection. Concretely:

- **Redirect SSRF (live today):** all five Guzzle/`Http` fetchers follow redirects with no
  per-hop re-validation. A tenant site returning `302 → http://169.254.169.254/` (cloud
  metadata) or `http://127.0.0.1/` is fetched. `RobotsTxtPolicy::fetchFor` has **no**
  `SafeExternalUrl` check at all and is the *first* network touch of every crawl.
- **DNS-rebinding TOCTOU:** `SafeExternalUrl::isSafe()` resolves once for validation; curl
  re-resolves at connect. An attacker DNS answering public-then-private slips through. The
  current docstring claiming "Re-callable at fetch time to defeat DNS rebinding" is **false**.
- **Render path (gating `CRAWLER_JS_RENDERING`):** `PageRenderer` guards only the initial
  URL; Chromium then does its own DNS, follows redirects, and runs page JS (`fetch`/XHR/
  `<img>`) that can reach internal endpoints — entirely outside PHP's visibility.
- **`SafeExternalUrl` denylist gaps (empirically tested):** `filter_var` allows 100.64/10
  (CGNAT), 224/4 + `ff00::/8` (multicast), and NAT64 `64:ff9b::/96` embedding private IPv4.

Two adjacent crawler-robustness bugs are bundled into this PR (owner decision):

- **Empty-chunk heal-loop:** a page that passes the sufficiency gate but chunks to `[]` is
  marked `SkippedNoContent` / `skipped_reason='no_content'` with no `render_attempted_at`,
  so it stays a `healCandidate` and is **re-fetched every crawl forever** (render never runs
  because the raw body already passes the gate).
- **Unbounded render cost:** `RenderOnFallback::resolve` is called per page in the sequential
  crawl loop with no cap; N SPA pages × ~7–30s each = unbounded crawl wall-clock.

## 2. Goal / non-goal

**Goal:** Close the HTTP-fetch SSRF completely in-app (incl. rebinding), make the Chromium
render path rebinding-safe via a validate-and-pin egress proxy, and fix the two adjacent
robustness bugs — in **one PR**, sequenced so the pure-PHP live-vuln fix lands first as an
independently-green commit.

**Non-goal:** Provisioning the OS-level egress firewall (documented as recommended
defense-in-depth, not a code deliverable). Allowlist-based crawling. Flipping
`CRAWLER_JS_RENDERING=true` (stays off; this PR only makes it *safe* to flip).

## 3. Decisions (locked in brainstorm)

| # | Decision | Choice |
|---|----------|--------|
| 1 | Scope / depth | Both paths, full depth, **single PR** |
| 2 | Guzzle redirect mechanism | **Manual redirect loop** (`allow_redirects=>false`) + per-hop `resolvePublicIps` + `CURLOPT_RESOLVE` pin. Rejected: `on_redirect` (can't pin), per-hop `withMiddleware` (untestable via `Http::fake`, depends on unverified handler ordering) |
| 3 | Render control | **Node validate-and-pin egress proxy** + `setProxyServer` + `--proxy-bypass-list=<-loopback>` + fail-closed interlock. **No `browser.cjs` fork** (proxy covers dev+prod and is rebinding-complete; fork was redundant + upgrade-resync cost) |
| 4 | Proxy language | **Node** (`node:test` for coverage; shares CIDR module; no new dependency — rendering already requires Node) |
| 5 | Failure behavior | **Skip + log + count failed**; crawl continues (matches existing failure handling; callers already catch `\Throwable`) |
| 6 | Adjacent fixes | **Bundled**: empty-chunk heal-loop + per-crawl render budget |
| 7 | Render prod-enable gate | Proxy + `<-loopback>` makes the flag flippable from app; OS egress firewall stays *recommended* defense-in-depth, no longer a hard prerequisite |

## 4. Architecture

Two egress surfaces, **one shared IP-classification primitive** (`SafeExternalUrl`), mirrored
once into JS for the proxy with an enforced parity test.

```
                         ┌─────────────────────────────────────┐
                         │  SafeExternalUrl (PHP, primitive)    │
                         │  isSafeIp(ip) · resolvePublicIps(host)│
                         │  hardened isPrivateIp (CGNAT/mcast/   │
                         │  NAT64/metadata)                      │
                         └───────────────┬─────────────────────┘
                  ┌──────────────────────┴───────────────────────┐
                  │ (PHP)                                         │ (parity test)
       ┌──────────▼───────────┐                       ┌───────────▼──────────┐
       │ GuardedHttpClient    │                       │ private-cidr.mjs     │
       │ manual redirect loop │                       │ isPrivateIp(ip) (JS) │
       │ + CURLOPT_RESOLVE pin │                      └───────────┬──────────┘
       └──────────┬───────────┘                                   │
   routes all 5 Guzzle fetchers:                       ┌──────────▼───────────┐
   fetchBody · probeHeaders ·                          │ egress-proxy.mjs     │
   robots fetchFor · fetchSitemap ·                    │ resolve→validate→    │
   extractLinks                                        │ connect-to-pinned-IP │
                                                       │ CONNECT tunnel       │
                                                       └──────────┬───────────┘
                                              Browsershot setProxyServer +
                                              --proxy-bypass-list=<-loopback>
                                                       ┌──────────▼───────────┐
                                                       │ PageRenderer         │
                                                       │ enabled() = js_render │
                                                       │   && proxy configured │
                                                       └──────────────────────┘
```

## 5. Components

### A. `SafeExternalUrl` — shared primitive (`app/Rules/SafeExternalUrl.php`)

- **Expose** `public static function isSafeIp(string $ip): bool` (thin wrapper over the
  existing private `isPrivateIp`).
- **Add** `public static function resolvePublicIps(string $host): array` — resolve all A and
  AAAA records once and return the validated non-empty IP set. **Fail closed: return `[]` if
  the host is unresolvable OR if ANY record is private/reserved** (no app-exception coupling
  in the primitive — `GuardedHttpClient` translates `[]` into `BlockedAddressException`). This
  is the single resolve callers pin to — never validate the name and let curl re-resolve.
- **Harden** `isPrivateIp` with explicit CIDR checks for the tested gaps:
  `100.64.0.0/10` (CGNAT), `224.0.0.0/4` + `ff00::/8` (multicast), NAT64 `64:ff9b::/96`
  (normalize the embedded IPv4 like the existing `::ffff:` path, then re-check), and the
  cloud-metadata IP `169.254.169.254` (belt-and-suspenders; link-local already covered by
  `NO_RES_RANGE`).
- **Delete** the false "Re-callable at fetch time to defeat DNS rebinding" docstring; replace
  with an accurate note that rebinding is closed by the *pin*, not by re-calling `isSafe`.

### B. `GuardedHttpClient` — Guzzle path (`app/Services/Crawler/GuardedHttpClient.php`, new)

Single chokepoint, constructor-injected into the three HTTP services.

```php
public function get(string $url, array $headers = [], int $timeout = 30): Response;  // throws BlockedAddressException
public function head(string $url, array $headers = [], int $timeout = 10): Response;
```

**Manual redirect loop** (shared by both methods):
1. Enforce **http/https scheme allowlist** on the initial URL (reject `file://`, `gopher://`,
   etc. — Guzzle's protocol guard is gone once redirects are disabled).
2. `allow_redirects => false`. Cap **5 hops**; exceeding the cap is a fetch failure.
3. Per hop: parse host+port → `SafeExternalUrl::resolvePublicIps($host)` → pin via
   `Http::withOptions(['curl' => [CURLOPT_RESOLVE => ['-host:port', "host:port:ip1,ip2,…"]]])`
   (evict-then-set guards `queue:work` handle-reuse stickiness; port matches scheme:
   `:443:` https / `:80:` http) → issue the request (HEAD stays HEAD on `probeHeaders`).
4. On a 3xx: read `Location`, resolve **relative** Locations against the current URL,
   re-enforce scheme allowlist, loop.
5. On a blocked/unsafe hop: log `[GuardedHttp] (NO $) Blocked non-public address` and throw
   `BlockedAddressException`.

**Wiring** — route all five sites through it, **replacing** their `isSafe(url)` calls (do not
layer — the old `isSafe` re-resolves and would re-open the TOCTOU and split the source of truth):

| Site | File:line | Method |
|------|-----------|--------|
| `fetchBody` | `SiteCrawler.php:281` | `get` |
| `probeHeaders` | `SiteCrawler.php:258` | `head` |
| `fetchFor` (was unguarded) | `RobotsTxtPolicy.php:30` | `get` |
| `fetchSitemap` | `SitemapDiscoverer.php:74` | `get` |
| `extractLinks` | `SitemapDiscoverer.php:153` | `get` |

All five already wrap their request in `try/catch (\Throwable)`; `BlockedAddressException` is
absorbed into each one's existing failure path (skip + count failed). Standardize the
User-Agent on `RobotsTxtPolicy::USER_AGENT_HEADER` **without** changing
`DocumentProcessor::fetchUrl`'s no-UA request shape (its tests assert it; out of scope here).

> **Task 0 (blocks Task 1) — real-network probe, not `Http::fake`:**
> 1. Confirm `CURLOPT_RESOLVE` actually pins through Laravel's `Http::withOptions` on this
>    stack (guzzle 7.10.5 + PHP curl handler).
> 2. Confirm the handle-reuse stickiness behavior in a long-lived worker (does the
>    `['-host:port', …]` evict-then-set prepend matter here?).
> 3. **Resolver consistency:** PHP `dns_get_record` vs curl `getaddrinfo` can disagree
>    (CNAME chains, IPv6-only hosts, `/etc/hosts`, search domains). Probe a CNAME'd host and
>    an IPv6-only host — if PHP pins a set curl wouldn't produce, the pin **breaks legitimate
>    sites**. Decide the multi-IP pin strategy from the result.
> Update this spec + the plan before Task 1 if reality differs.

### C. Render-path egress proxy (Node) + `PageRenderer` interlock

**`resources/node/private-cidr.mjs`** — `isPrivateIp(ip): boolean`. The JS mirror of
`SafeExternalUrl::isPrivateIp`, including normalization (IPv4-mapped IPv6, NAT64
`64:ff9b::/96`), not just a flat CIDR list.

**`resources/node/egress-proxy.mjs`** — forward + CONNECT proxy, **bound to `127.0.0.1:PORT`**:
- For every request/CONNECT: resolve the target host → reject if **any** resolved IP is
  private (`isPrivateIp`) → **connect to the validated IP** (pin it; do not re-resolve at
  connect) → stream bytes. HTTPS uses `CONNECT` tunneling without MITM (validate before
  opening the tunnel, then blind-pipe; TLS terminates in Chromium).
- **Open-proxy hygiene:** it applies the same validation to *every* connection; the
  `127.0.0.1` bind + ephemeral port is the only thing keeping it private. Documented as such.

**Browsershot wiring** (`PageRenderer::render`):
- `->setProxyServer((string) config('services.crawler.egress_proxy'))`
- `->addChromiumArguments(['proxy-bypass-list' => '<-loopback>'])` — **mandatory**: without
  it Chromium implicitly bypasses the proxy for `127/8` and `169.254.169.254`.

**Fail-closed interlock** — in `enabled()`, **not** `render()`:
```php
public function enabled(): bool
{
    return (bool) config('services.crawler.js_rendering', false)
        && filled(config('services.crawler.egress_proxy'));
}
```
Putting it in `enabled()` makes `RenderOnFallback::renderingEnabled()` false when no proxy is
configured, so `SiteCrawler` does **not** treat the page as a `healCandidate` — avoiding a
churn loop identical to bug D. (This intentionally flips existing flag-only `PageRenderer`
tests; update them.)

**Lifecycle:**
- **Dev:** add the proxy to `composer dev`'s `concurrently` block so it runs alongside
  `queue:work`.
- **Prod:** supervised process (documented in the PR's deploy steps); binds localhost.
- **Config:** `CRAWLER_EGRESS_PROXY` (e.g. `127.0.0.1:8118`) in `config/services.php` crawler
  block + `.env.example`.

**Ops doc:** OS egress firewall scoped to a dedicated Chromium uid/netns is *recommended*
defense-in-depth; with the validate-and-pin proxy + `<-loopback>` it is no longer a hard
prerequisite for the flag.

### D. Empty-chunk heal-loop fix

- `ProcessKnowledgeItem::markSkipped` takes a `string $reason`. The `chunks === []` branch
  (`ProcessKnowledgeItem.php:58`) passes `'no_chunks'`; the insufficient branch keeps
  `'no_content'`.
- `SiteCrawler` `healCandidate` (`SiteCrawler.php:120`) additionally requires the skip reason
  be heal-eligible — only `'no_content'` heals (rendering could help). A `'no_chunks'` page
  is excluded, falls to normal hash/ETag skip, and stops being re-fetched every crawl.

### E. Per-crawl render budget

- Config `CRAWLER_RENDER_BUDGET` (int, default e.g. `25`; `0` = unlimited).
- `RenderOnFallback::resolve(string $url, string $httpBody, bool $allowRender = true)` — when
  `$allowRender` is false, skip the render step (behaves like gate-failed, no render).
- `SiteCrawler` counts renders that actually executed (`$resolution['rendered'] === true`) and
  passes `$allowRender = $rendersUsed < $budget`. Once exhausted, insufficient pages become
  `SkippedNoContent` **without** stamping `render_attempted_at`, so they remain heal-eligible
  for a future crawl when budget is available (consistent with the "stamp only on actual
  render" rule).

## 6. Failure behavior (all paths)

A blocked/private address → the request fails → the caller's existing path skips the page,
increments `pages_failed`, logs `(NO $)`, and the crawl continues. No tenant-facing surface,
no hard-fail of the session.

## 7. Testing strategy

Three TDD layers; **failing test first** for every task.

- **PHP unit (`SafeExternalUrlTest`):** extend the adversarial IP table with the new gap
  cases (CGNAT, multicast, NAT64, metadata). `isSafeIp` / `resolvePublicIps` covered directly.
- **PHP feature (`GuardedHttpClient` + the 5 sites):** `Http::fake` *can* exercise the manual
  loop because each hop is a discrete `Http::get` (unlike Guzzle's internal redirect
  middleware). Assert per-hop behavior with `Http::assertSent` / `Http::assertNotSent` against
  private targets; the pin logic itself is extracted as a pure unit (since `Http::fake`
  cannot exercise `CURLOPT_RESOLVE`).
- **Node (`node:test`, run via `node --test resources/node/`):**
  - `private-cidr.test.mjs` — classifier against the **shared adversarial IP fixture**.
  - `egress-proxy.test.mjs` — point the proxy at a host resolving to a private IP and assert
    **rejection at connect**; assert a public target tunnels; assert it connects to the
    validated IP.
- **PHP parity test (`SsrfParityTest`):** shell `node` to classify every IP in the shared
  fixture and assert the JS verdicts match `SafeExternalUrl::isSafeIp` — covers normalization,
  not just the CIDR list. Guards drift on every Browsershot/Node change.
- **Interlock test:** `PageRenderer::enabled()` is false when `egress_proxy` unset even with
  `js_rendering` on (update the existing flag-only tests).
- **Adjacent fixes:** `'no_chunks'` skip reason + `healCandidate` exclusion;
  render-budget exhaustion stops rendering without poisoning `render_attempted_at`.
- **Full suite between tasks** (`php artisan test`) + **browser smoke** before PR.

Shared fixture: `tests/fixtures/ssrf-ip-cases.json` (`{ ip: expectedPrivate }`), consumed by
`SafeExternalUrlTest`, `private-cidr.test.mjs`, and `SsrfParityTest`.

## 8. File map

**New:**
- `app/Services/Crawler/GuardedHttpClient.php`
- `app/Exceptions/BlockedAddressException.php`
- `resources/node/private-cidr.mjs`
- `resources/node/egress-proxy.mjs`
- `resources/node/private-cidr.test.mjs`
- `resources/node/egress-proxy.test.mjs`
- `tests/fixtures/ssrf-ip-cases.json`
- `tests/Unit/Services/Crawler/GuardedHttpClientTest.php`
- `tests/Unit/Security/SsrfParityTest.php`

**Modified:**
- `app/Rules/SafeExternalUrl.php` (isSafeIp, resolvePublicIps, isPrivateIp hardening, docstring)
- `app/Services/Crawler/SiteCrawler.php` (route fetchBody/probeHeaders; healCandidate reason; render budget)
- `app/Services/Crawler/RobotsTxtPolicy.php` (route fetchFor)
- `app/Services/Crawler/SitemapDiscoverer.php` (route fetchSitemap/extractLinks)
- `app/Services/Crawler/PageRenderer.php` (enabled() interlock; setProxyServer + `<-loopback>`)
- `app/Services/Crawler/RenderOnFallback.php` (resolve() `$allowRender`)
- `app/Jobs/ProcessKnowledgeItem.php` (markSkipped reason)
- `config/services.php` (`egress_proxy`, `render_budget`)
- `.env.example` (new keys)
- `composer.json` (`dev` script: add egress proxy to concurrently)
- existing crawler/PageRenderer tests (interlock + routing updates)

## 9. Task sequencing (for the plan)

Ordered so the **pure-PHP live-vuln fix is an independently-green commit first**:

0. **Task 0** — `CURLOPT_RESOLVE` + resolver-consistency probe (gates Task 1).
1. `SafeExternalUrl` hardening + `isSafeIp`/`resolvePublicIps` + adversarial table.
2. `GuardedHttpClient` (manual loop + pin) + `BlockedAddressException`.
3. Route all 5 Guzzle sites through it (replace `isSafe`). ← **live vuln closed; green commit.**
4. `private-cidr.mjs` + JS tests + `SsrfParityTest`.
5. `egress-proxy.mjs` + `node:test` reject-at-connect.
6. `PageRenderer` interlock (`enabled()`) + `setProxyServer`/`<-loopback>` + config + `composer dev`.
7. Empty-chunk heal-loop fix (D).
8. Per-crawl render budget (E).
9. Docs: `.env.example`, ops egress-firewall note, PR deploy steps.

## 10. Out of scope (explicit)

- OS-level egress firewall provisioning (ops/deploy; documented only).
- Allowlist-based crawling.
- Flipping `CRAWLER_JS_RENDERING=true`.
- Changing `DocumentProcessor::fetchUrl`'s request shape.
