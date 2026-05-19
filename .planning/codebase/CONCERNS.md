# Codebase Concerns

**Analysis Date:** 2026-05-20

---

## Tech Debt

**LeadController bypasses tenant cache:**
- Issue: `app/Http/Controllers/Api/V1/Widget/LeadController.php` line 37 does a raw `Tenant::where('api_key', $request->api_key)->first()` DB lookup. `ChatController` uses a Redis cache (`tenant:api_key:{key}` TTL 300 s) via its private `findTenantByApiKey()` method. `CheckUsageLimits` middleware also caches. LeadController hits the DB on every lead submission.
- Files: `app/Http/Controllers/Api/V1/Widget/LeadController.php`, `app/Http/Controllers/Api/V1/Widget/ChatController.php`
- Impact: Extra DB round-trip per lead submission; inconsistent behaviour under load; duplicated lookup logic in three places.
- Fix approach: Extract `findTenantByApiKey()` into a shared `TenantResolver` service (or a static helper on Tenant); inject it into all three call sites.

**AnalyticsService has no DB-level caching:**
- Issue: All 9 public methods in `app/Services/Analytics/AnalyticsService.php` execute raw Eloquent queries on every call. `AnalyticsController` calls all 9 on every page load. No `Cache::remember` anywhere in the file.
- Files: `app/Services/Analytics/AnalyticsService.php`, `app/Http/Controllers/Client/AnalyticsController.php`
- Impact: Each analytics page load fires 9+ DB queries. Under moderate traffic, this is the most expensive page in the app.
- Fix approach: Wrap each method body in `Cache::remember("analytics:{$tenantId}:{$method}", 300, fn() => ...)` and add a cache-bust hook on `Conversation::created` / `Lead::created`.

**KnowledgeBaseController uses raw `tenant_id` assignment:**
- Issue: `app/Http/Controllers/Client/KnowledgeBaseController.php` line 95: `$item->tenant_id = $tenant->id;` — direct column assignment. The codebase enforces the `BelongsToTenant::forTenant()` pattern via a Larastan rule (`NoRawTenantIdWhere`), and Cluster E swept 25 raw calls. This one survived.
- Files: `app/Http/Controllers/Client/KnowledgeBaseController.php`
- Impact: Architectural inconsistency; the PHPStan rule is circumvented because `forTenant()` is the `where()` variant, not the assignment variant.
- Fix approach: Replace with `$item->fill(['tenant_id' => $tenant->id])` or add a `BelongsToTenant::assignTenant(Tenant $t)` helper; add a Larastan rule for direct `->tenant_id =` assignments.

**`laravel/cashier` wildcard constraint, entirely unused:**
- Issue: `composer.json` line 13: `"laravel/cashier": "*"`. No `Billable` trait on any model, no Cashier migrations, no `stripe_id` column, no Cashier routes or webhooks. Package is pulled in, auto-discovered, and does nothing.
- Files: `composer.json`
- Impact: Adds ~50 Cashier migration stubs to the migration history; wildcard `"*"` means next `composer update` could pull a breaking major version.
- Fix approach: Remove entirely from `composer.json` (and `composer.lock`); add explicit Stripe integration if/when billing is implemented via Cashier.

**`routes/api.php` client-API group is a stub:**
- Issue: A commented `// TODO: Add client API routes` placeholder exists inside a Sanctum-guarded route group. No client API routes are implemented.
- Files: `routes/api.php` (line 42)
- Impact: Any planned mobile/external API client has no endpoints. The placeholder may cause confusion about what is and is not implemented.
- Fix approach: Either implement or remove the stub group; document the intent in a spec.

**`WIDGET_SESSION_DUAL_ACCEPT` dual-accept mode is the permanent default:**
- Issue: `config/widget.php` defaults `session_dual_accept` to `true`. This means all widget requests without a Bearer token silently pass through (with a `Deprecation` header). The flag exists to allow a grace period before strict enforcement, but there is no scheduled cutover date or tracking mechanism in code.
- Files: `config/widget.php`, `app/Http/Middleware/RequireWidgetSessionToken.php`
- Impact: The entire session-token security layer is optional indefinitely unless the env var is explicitly set to `false`.
- Fix approach: Set `WIDGET_SESSION_DUAL_ACCEPT=false` in production after TrustProxies is registered; add an ops runbook entry tracking the cutover gate.

---

## Known Bugs

**`DkBankClient::getPrivateKey()` silent TypeError on missing key file:**
- Symptoms: If `config('services.dk_bank.private_key_path')` points to a missing or unreadable file, `file_get_contents()` returns `false`. The return value is assigned to `private ?string $cachedPrivateKey` under `declare(strict_types=1)`, which causes a `TypeError` at the assignment site — not a clear "key file missing" error.
- Files: `app/Services/Payment/DkBank/DkBankClient.php` (lines 118–125)
- Trigger: Deploy with missing/wrong path for `DK_BANK_PRIVATE_KEY_PATH`, then hit any DK payment route.
- Workaround: Set the env var correctly. No runtime guard exists.
- Fix approach: Check `$content = file_get_contents(...); if ($content === false) { throw new \RuntimeException('DK Bank private key file unreadable: '.$path); }` before assigning to `$cachedPrivateKey`.

**`WidgetAudit::log()` throws on empty `APP_KEY` — request crashes:**
- Symptoms: `WidgetAudit::ipHash()` throws `RuntimeException('APP_KEY must be set...')` if `app.key` is empty. `log()` calls `ipHash()` without try/catch. Two callers — `ChatController::sendMessage()` and `RequireWidgetSessionToken` (approved path, line 63) — have no guard either. An empty `APP_KEY` in a misconfigured environment kills every widget request at the audit-log step.
- Files: `app/Support/Widget/WidgetAudit.php`, `app/Http/Controllers/Api/V1/Widget/ChatController.php`, `app/Http/Middleware/RequireWidgetSessionToken.php`
- Trigger: Deploy without `APP_KEY` set (e.g., fresh environment, CI integration test without env).
- Fix approach: Wrap `WidgetAudit::log()` calls in try/catch with a fallback log to the default channel; or check `config('app.key')` at boot in `AppServiceProvider` and fail-fast.

---

## Security Considerations

**TrustProxies middleware is not registered — IP-binding is broken behind a proxy:**
- Risk: `ThrottleWidgetPerIp` and `SessionTokenService::verify()` both rely on `$request->ip()` to enforce per-IP rate limits and validate JWT `ip` claims. Without `TrustProxies`, `$request->ip()` returns the proxy/load-balancer IP, not the real client IP. All session tokens are bound to the wrong IP; all rate limits collapse to a single bucket. The middleware logs a warning (line 22 of `ThrottleWidgetPerIp.php`) but does not abort — it falls back to `'unknown'`.
- Files: `app/Http/Middleware/ThrottleWidgetPerIp.php`, `app/Http/Middleware/RequireWidgetSessionToken.php`, `app/Services/Widget/SessionTokenService.php`, `bootstrap/app.php`
- Current mitigation: None. No `TrustProxies.php` exists anywhere in the project.
- Recommendations: Register Laravel's built-in `Illuminate\Http\Middleware\TrustProxies` (or a concrete subclass) in `bootstrap/app.php` with appropriate `$proxies` and `$headers` configuration before flipping `WIDGET_SESSION_DUAL_ACCEPT=false`.

**DK_BANK_ENABLED killswitch is UI-only — API routes are always live:**
- Risk: `config('services.dk_bank.enabled')` is checked only in `HandleInertiaRequests` (passes flag to Vue) and in tests. All four controller methods (`start`, `show`, `status`, `verifyRrn`) in `DkBankQrController` are reachable regardless of the flag. An attacker or crawler can hit `/dk-bank/*` routes when the integration is "disabled."
- Files: `app/Http/Controllers/Client/DkBankQrController.php`, `app/Http/Middleware/HandleInertiaRequests.php` (line 72), `routes/web.php`
- Current mitigation: Routes are behind `auth` + Spatie tenant middleware; not publicly accessible without login.
- Recommendations: Add `abort_if(! config('services.dk_bank.enabled'), 404)` to each controller method, or wrap the route group in a middleware that checks the flag.

**Widget audit log `reject` path is guarded; `approve` path is not:**
- Risk: In `RequireWidgetSessionToken`, the rejected-token branch wraps `WidgetAudit::log()` in try/catch. The approved-request branch (line 63) does not. If `APP_KEY` is missing (see Known Bugs), rejected tokens are silently swallowed but approved tokens crash with a 500.
- Files: `app/Http/Middleware/RequireWidgetSessionToken.php` (lines 48, 63)
- Current mitigation: `APP_KEY` is always set in Laravel deployments. Risk is low in practice but asymmetric — the security event (rejection) is guarded; the normal path is not.
- Recommendations: Wrap all `WidgetAudit::log()` call sites uniformly in try/catch.

**Widget origin `allowed_domains` is empty for all new tenants — widget is broken at signup:**
- Risk: `ValidateWidgetDomain` returns 403 when `allowed_domains` is empty ("No allowed domains configured"). `RegisterController` never seeds `allowed_domains` from the tenant's `website_url`. Every new tenant's widget is non-functional until they discover and fill in the widget settings.
- Files: `app/Http/Middleware/ValidateWidgetDomain.php` (lines 72–79), `app/Http/Controllers/Auth/RegisterController.php`
- Current mitigation: None. Widget fails silently from the end-user perspective.
- Recommendations: In `RegisterController::store()`, after tenant creation, set `allowed_domains = [parse_url(website_url, PHP_URL_HOST)]` as the initial value; add an onboarding step or warning banner for tenants with empty `allowed_domains`.

---

## Performance Bottlenecks

**`SessionTokenService::verify()` is O(active_tenants) on every widget request:**
- Problem: Every authenticated widget request calls `Tenant::where('status', 'active')->get()->first(fn($t) => hash('sha256', $t->api_key.$this->secret) === $expectedSub)`. This loads all active tenants into PHP memory and hashes each `api_key` to find a match.
- Files: `app/Services/Widget/SessionTokenService.php` (lines 87–91)
- Cause: No `api_key_hash` column exists on the `tenants` table. The SHA-256 hash is computed in PHP, not in the database, so there is no index-capable lookup.
- Improvement path: Add an `api_key_hash` column (indexed, computed as `SHA2(CONCAT(api_key, secret_salt), 256)`), store it on `Tenant`. On `verify()`, decode the JWT, extract `sub`, and do `Tenant::where('api_key_hash', $expectedSub)->where('status', 'active')->firstOrFail()`. The TODO comment in the source file (line 87) acknowledges this.

**AnalyticsService loads full result sets into PHP memory for aggregation:**
- Problem: Two methods perform aggregation in PHP rather than in the database:
  - `getOverviewStats()` (line 55): `(clone $conversations)->withCount('messages')->get()->avg('messages_count')` — loads all conversations to compute avg message count.
  - `getLeadScoreDistribution()` (lines 163–174): `->get()` then `->where('score', '>=', 70)->count()` / `->where('score', 'between', ...)` — loads all leads then filters in PHP.
- Files: `app/Services/Analytics/AnalyticsService.php`
- Cause: Eloquent Collection methods used where Query Builder aggregate functions should be.
- Improvement path: Replace `->get()->avg(...)` with `->avg(DB::raw(...))` and collection `->where()` filters with `->whereBetween('score', [...])` + `->count()` query chains.

**No caching on any analytics query:**
- Problem: `AnalyticsService` has 9 public methods, each executes a DB query. `AnalyticsController` calls all 9 on every request. Zero `Cache::remember` calls in the file.
- Files: `app/Services/Analytics/AnalyticsService.php`, `app/Http/Controllers/Client/AnalyticsController.php`
- Cause: Caching was not added when the service was built.
- Improvement path: Add `Cache::remember("analytics:{$tenantId}:{$method}:{$period}", 300, ...)` per method; bust on `Conversation`, `Lead`, and `UsageRecord` model events via observer or event listener.

**Knowledge chunk retrieval lacks a compound index for tenant filtering:**
- Problem: The HNSW vector index on `knowledge_chunks.embedding` is not compound with `knowledge_item_id`. Retrieval queries filter by tenant via a subquery on `knowledge_items.tenant_id`, which grows with tenant count.
- Files: `database/migrations/2025_11_28_060506_create_knowledge_chunks_table.php`
- Cause: SQLite-vec (the vector extension used here) may not support compound vector indexes; this may be a library constraint rather than an oversight.
- Improvement path: Add a standard B-tree index on `knowledge_chunks.knowledge_item_id`; evaluate pgvector (with `tenant_id` as a partition key) if retrieval latency becomes a bottleneck at scale.

---

## Fragile Areas

**JWT::$timestamp static mutation — Octane race condition:**
- Files: `app/Services/Widget/SessionTokenService.php` (lines 54, 67)
- Why fragile: `SessionTokenService::verify()` sets `JWT::$timestamp = Carbon::now()->timestamp` and resets it to `null` in a `finally` block. `JWT::$timestamp` is a static class property on the firebase/php-jwt library. Under Laravel Octane (Swoole/RoadRunner long-running process), concurrent requests share the same PHP process. Request A sets the timestamp; Request B reads it before A's `finally` resets it, causing incorrect expiry checks. The `finally` block ensures cleanup for the request that set it, but the window between set and reset is a race.
- Safe modification: Replace `JWT::$timestamp` mutation with a subclass or mock that does not touch shared static state; or pin to non-Octane deployment until fixed; or file an upstream issue with firebase/php-jwt requesting a non-static timestamp override API.
- Test coverage: `tests/Feature/Widget/SessionTokenFlowTest.php` covers the flow but not concurrent execution.

**DK Bank private key file loading — no retry, no runtime validation:**
- Files: `app/Services/Payment/DkBank/DkBankClient.php`
- Why fragile: `getPrivateKey()` reads the PEM file lazily on first use and caches in `$cachedPrivateKey`. There is no validation that the file is a valid RSA private key, no retry on transient file system errors, and a missing file produces a TypeError (see Known Bugs). If the key is rotated at the OS level while Octane is running, the cached stale key is never invalidated.
- Safe modification: Read and validate the key at service construction time (fail-fast at boot) rather than lazily. Under Octane, restart the worker after key rotation.

**`RequireWidgetSessionToken` dual-accept flag is checked per-request from config:**
- Files: `app/Http/Middleware/RequireWidgetSessionToken.php` (line 24), `config/widget.php`
- Why fragile: `config('widget.session_dual_accept')` is read on every request. Flipping the env var in production takes effect only after `php artisan config:cache` is re-run (or the process restarts under Octane). A partial rollout (some workers with old cache, some with new) creates a mixed enforcement window — some requests require Bearer, others do not.
- Safe modification: Treat the cutover as a deployment event: clear config cache immediately after env change; monitor error rates before proceeding.

**Analytics page load executes 9 synchronous DB queries:**
- Files: `app/Http/Controllers/Client/AnalyticsController.php`, `app/Services/Analytics/AnalyticsService.php`
- Why fragile: If any single query times out or the DB is slow, the entire analytics page fails with a 500. There is no partial-load, timeout budget, or fallback. Under a tenant with large conversation volume, some queries (particularly `getRecentActivity`) load full record sets.
- Safe modification: Add `Cache::remember` with a reasonable TTL (see Performance section); add per-query timeouts via `DB::statement('SET SESSION max_execution_time=5000')` on the analytics connection.

---

## Scaling Limits

**Session token verify — full tenant table scan:**
- Current capacity: Acceptable up to ~1,000 active tenants (per the TODO comment in `SessionTokenService`).
- Limit: At ~1,000+ active tenants, each widget request loads 1,000+ rows into PHP memory and hashes each api_key. This is O(n) memory and O(n) CPU per request.
- Scaling path: Add `api_key_hash` indexed column (see Performance Bottlenecks). After the column exists, verify becomes O(1) indexed lookup.

**Per-IP rate limiting collapses to one bucket without TrustProxies:**
- Current capacity: 10 init / 30 message / 5,000 daily per IP. Without TrustProxies, all widget traffic from behind a load balancer shares a single `'unknown'` bucket.
- Limit: Any production deployment behind a proxy/LB is effectively at 10 inits / 30 messages / 5,000 daily for the entire platform.
- Scaling path: Register TrustProxies first (see Security section).

**Analytics queries — no time-range partitioning:**
- Current capacity: Queries filter by `tenant_id` and optionally a date range but load full matching rows for in-PHP aggregation.
- Limit: A tenant with 100k+ conversations will experience slow analytics pages.
- Scaling path: Add DB-level aggregation (see Performance); consider a nightly summary table for historical analytics.

---

## Dependencies at Risk

**`laravel/cashier: "*"` — wildcard major-version constraint:**
- Risk: The `"*"` constraint in `composer.json` line 13 allows `composer update` to pull any future major version of Cashier. If Cashier releases v16 with breaking changes, the next update breaks the app silently (the package is auto-discovered via `composer.json`, even though unused).
- Impact: Unexpected breaking changes on `composer update`; bloated vendor directory; potential migration stub conflicts.
- Migration plan: Remove `laravel/cashier` entirely from `composer.json`. Re-add with a pinned version constraint when Stripe billing is implemented.

**`firebase/php-jwt` — static mutable state (`JWT::$timestamp`):**
- Risk: The library exposes a static public property as its only mechanism for overriding "current time" in tests. This works in single-process PHP (FPM) but is a race condition under Octane.
- Impact: Incorrect token expiry validation under Octane.
- Migration plan: Monitor for a library update that provides instance-level time injection; or maintain a thin wrapper that avoids touching static state.

---

## Missing Critical Features

**TrustProxies not implemented — blocks strict session-token enforcement:**
- Problem: The entire widget security upgrade (PR #29) depends on correct client IP resolution. Without TrustProxies registered, IP-binding is broken, rate limits are broken, and `WIDGET_SESSION_DUAL_ACCEPT` cannot safely be set to `false`.
- Blocks: Widget strict-mode cutover; per-IP rate limit accuracy; session-token IP validation in production.

**Client API group is a stub:**
- Problem: `routes/api.php` contains a commented `// TODO: Add client API routes` inside a Sanctum-guarded group. No client-facing REST API endpoints exist.
- Blocks: Mobile clients, third-party integrations, headless dashboard access.

**DNS-TXT domain verification for new tenant registrations:**
- Problem: Identified as deferred in PR #25 spec Out-of-Scope. Tenants self-report `website_url` with no verification. `allowed_domains` is derived from this unverified input.
- Blocks: Confident enforcement of domain ownership; prevents widget embedding on non-owned domains.

**Headless crawler renderer for JavaScript-heavy sites:**
- Problem: The site crawler (PR #25) uses a plain HTTP fetcher and cannot process SPA or JavaScript-rendered content. Tenants with React/Vue marketing sites get empty knowledge bases.
- Blocks: RAG accuracy for JS-heavy tenant sites.

**Cross-bank RRN verification for DK Bank payments:**
- Problem: `DkBankQrService::verifyRrn()` calls only the intra-bank status endpoint (`/v1/intra-transaction/status`). Cross-bank (inter-bank) RRN verification is untested and may require a different endpoint. No cross-bank test exists.
- Files: `app/Services/Payment/DkBank/DkBankQrService.php` (line 71)
- Blocks: Payment reconciliation for cross-bank QR scans.

---

## Test Coverage Gaps

**AnalyticsService: 8 of 9 methods untested:**
- What's not tested: `getOverviewStats`, `getConversationsOverTime`, `getLeadsOverTime`, `getTokenUsageOverTime`, `getLeadScoreDistribution`, `getLeadStatusDistribution`, `getConversationsByHour`, `getRecentActivity`.
- Files: `app/Services/Analytics/AnalyticsService.php`, `tests/Unit/Services/Analytics/` (only `GetTopQuestionsTest.php` exists)
- Risk: In-PHP aggregation bugs (wrong score bucket boundaries, off-by-one date ranges) go undetected. Refactoring to DB-level aggregation has no regression net.
- Priority: High — these methods contain non-trivial aggregation logic.

**DK Bank killswitch (`DK_BANK_ENABLED=false`) not tested:**
- What's not tested: No test asserts that routes return 404/403/405 when `services.dk_bank.enabled` is false. `DkBankQrControllerTest` sets enabled=true unconditionally.
- Files: `tests/Feature/Client/Billing/DkBankQrControllerTest.php`, `app/Http/Controllers/Client/DkBankQrController.php`
- Risk: The killswitch does not actually block API routes (see Security section). A test would catch this gap.
- Priority: High — confirms security invariant.

**TrustProxies + IP-binding integration test absent:**
- What's not tested: No test verifies that widget requests behind a proxy header (`X-Forwarded-For`) use the real client IP for rate limiting or token binding. `PerIpThrottleTest` and `SessionTokenFlowTest` run without proxy simulation.
- Files: `tests/Feature/Widget/PerIpThrottleTest.php`, `tests/Feature/Widget/SessionTokenFlowTest.php`
- Risk: TrustProxies registration (when it happens) could be misconfigured; no test would catch it.
- Priority: High — directly tied to the blocking security gap.

**Widget strict-mode cutover path (`WIDGET_SESSION_DUAL_ACCEPT=false`) not tested as a system:**
- What's not tested: The interaction between `WIDGET_SESSION_DUAL_ACCEPT=false`, `TrustProxies`, and correct IP binding is not tested end-to-end. Individual unit tests exist but not the combined strict-mode state.
- Files: `tests/Feature/Widget/`
- Risk: Cutover to strict mode could break production widget traffic if any piece is misconfigured.
- Priority: High — pre-cutover regression net.

**No end-to-end / browser tests:**
- What's not tested: There are no Playwright or Dusk tests. All tests are Pest feature/unit tests. UI flows (registration wizard, widget settings, analytics dashboard, DK Bank QR scan) have no browser-level coverage.
- Files: No `tests/Browser/` directory exists.
- Risk: Route path mismatches between backend and frontend, Vue form targeting, Inertia redirect flows, flash-message rendering — none of these are caught by Pest.
- Priority: Medium — high-value for registration wizard and payment flows.

**`ValidateWidgetDomain` empty `allowed_domains` → new-tenant broken widget:**
- What's not tested: No test exercises a newly registered tenant (with no `allowed_domains`) attempting to use the widget and receiving 403.
- Files: `app/Http/Middleware/ValidateWidgetDomain.php`, `tests/Feature/Widget/WidgetCorsTest.php`
- Risk: The onboarding gap (widget broken at signup) is invisible in CI.
- Priority: Medium.

---

*Concerns audit: 2026-05-20*
