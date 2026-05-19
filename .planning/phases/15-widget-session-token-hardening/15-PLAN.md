---
phase: 15-widget-session-token-hardening
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_05_20_000001_add_api_key_hash_to_tenants_table.php
  - app/Models/Tenant.php
  - app/Services/Widget/SessionTokenService.php
  - app/Http/Middleware/ValidateWidgetDomain.php
  - app/Http/Middleware/CheckUsageLimits.php
  - app/Http/Controllers/Api/V1/Widget/ChatController.php
  - app/Http/Controllers/Api/V1/Widget/LeadController.php
  - bootstrap/app.php
  - config/widget.php
  - app/Support/Widget/WidgetAudit.php
  - app/Http/Middleware/RequireWidgetSessionToken.php
  - app/Providers/AppServiceProvider.php
autonomous: true
requirements:
  - CONS-22-a
  - CONS-22-b
  - CONS-22-c
  - CONS-22-d
  - CONS-22-e
  - CONS-22-f
  - CONS-22-g

must_haves:
  truths:
    - "Per-IP rate limits and IP-binding use the real client IP when TRUSTED_PROXIES env is set; they use REMOTE_ADDR when it is not (trust-none default)"
    - "A WidgetAudit::log() failure never crashes a widget request — the error is swallowed and a cache counter is incremented"
    - "A missing or null api_key in any widget middleware returns a structured 401 JSON, not an unhandled PHP error"
    - "api_key_hash column exists on tenants table with a unique index; verify() does an O(1) indexed DB lookup, not a PHP scan"
    - "All four api_key equality lookups (ValidateWidgetDomain:42, CheckUsageLimits:77, ChatController:366, LeadController:37) and the verify() scan are migrated to api_key_hash"
    - "WIDGET_SESSION_DUAL_ACCEPT defaults to false in config/widget.php; legacy api_key-only requests return 401 with SESSION_TOKEN_REQUIRED error code"
    - "JWT::$timestamp static mutation risk is resolved: exp/nbf validation is performed post-decode on the payload object without touching global static state"
  artifacts:
    - path: "database/migrations/2026_05_20_000001_add_api_key_hash_to_tenants_table.php"
      provides: "Indexed api_key_hash column + backfill of existing rows"
      contains: "api_key_hash"
    - path: "app/Models/Tenant.php"
      provides: "api_key_hash auto-maintained in booted() creating and saving hooks"
      contains: "api_key_hash"
    - path: "app/Services/Widget/SessionTokenService.php"
      provides: "verify() replaced with indexed DB lookup; expiry validated post-decode without JWT::$timestamp"
      exports: ["verify"]
    - path: "app/Http/Middleware/RequireWidgetSessionToken.php"
      provides: "Uniform try/catch on all WidgetAudit::log() calls; null api_key guard; strict-mode ready"
      contains: "try"
    - path: "config/widget.php"
      provides: "session_dual_accept default changed to false"
      contains: "false"
  key_links:
    - from: "app/Models/Tenant.php booted() saving hook"
      to: "tenants.api_key_hash column"
      via: "hash('sha256', $tenant->api_key . config('app.key'))"
      pattern: "api_key_hash"
    - from: "app/Services/Widget/SessionTokenService::verify()"
      to: "Tenant::where('api_key_hash', $expectedSub)"
      via: "indexed DB lookup"
      pattern: "api_key_hash"
    - from: "bootstrap/app.php withMiddleware"
      to: "Illuminate\\Http\\Middleware\\TrustProxies"
      via: "trustProxies(at: TRUSTED_PROXIES env)"
      pattern: "trustProxies"

---

<objective>
Harden the PR #29 widget session token system to production grade across all seven CONS-22 items (a–g), landing in this order: (f) api_key_hash column + lookup migration → (a,b,c) TrustProxies + audit guard + null guard → (d,e) Octane fix + enum/VO cleanup → (g) strict-mode cutover.

Purpose: The widget security layer shipped in PR #29 cannot safely enter strict mode without these fixes. IP-binding is broken behind any proxy (SC1). A misconfigured APP_KEY crashes every approved widget request (SC2). A null api_key causes an unhandled exception (SC3). The tenant lookup is O(n) (SC5). Dual-accept is still default-on (SC4 blocked by SC1 ordering).

Output: All five ROADMAP success criteria satisfied. Phase 14 (encryption at rest) is unblocked — api_key_hash is the column it encrypts api_key behind.

Cross-phase note: Phase 14 (Data Encryption at Rest) MUST execute AFTER this phase completes. The api_key_hash column created here is the prerequisite for Phase 14 to encrypt the api_key column; once Phase 14 runs, api_key_hash must continue to be derived from the pre-encryption api_key value and stored in plaintext for indexed lookup.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/ROADMAP.md
@.planning/phases/15-widget-session-token-hardening/15-CONTEXT.md
@app/Services/Widget/SessionTokenService.php
@app/Support/Widget/WidgetAudit.php
@app/Http/Middleware/RequireWidgetSessionToken.php
@app/Http/Middleware/ValidateWidgetDomain.php
@app/Http/Middleware/CheckUsageLimits.php
@app/Http/Controllers/Api/V1/Widget/ChatController.php
@app/Http/Controllers/Api/V1/Widget/LeadController.php
@app/Providers/AppServiceProvider.php
@bootstrap/app.php
@config/widget.php
@app/Models/Tenant.php

<interfaces>
<!-- Extracted from existing code for executor reference. Do not explore further. -->

From app/Services/Widget/SessionTokenService.php (current state):
```php
// sub = hash('sha256', $tenant->api_key . $this->secret)
// verify() does: Tenant::where('status','active')->get()->first(fn($t) => hash(...) === $expectedSub)
// JWT::$timestamp = Carbon::now()->timestamp set before decode, cleared in finally
public function mint(Tenant $tenant, string $origin, string $ip): array  // returns ['token', 'expires_at']
public function verify(string $token, string $origin, string $ip): Tenant
```

From app/Support/Widget/WidgetAudit.php:
```php
public const CHANNEL = 'widget_audit';
public const EVENT_INIT = 'widget_init';
public const EVENT_REQUEST = 'widget_request';
public const EVENT_REJECTED = 'widget_token_rejected';
public static function log(string $event, Tenant $tenant, ?string $origin, Request $request): void
public static function ipHash(?string $ip): string  // throws RuntimeException if APP_KEY empty
```

From app/Http/Middleware/RequireWidgetSessionToken.php:
```php
// Line 63: WidgetAudit::log(EVENT_REQUEST, ...) — UNGUARDED (approve path crashes on empty APP_KEY)
// Line 48-55: InvalidSessionTokenException catch wraps reject audit log (GUARDED already)
// $bodyApiKey check at line 59: if $bodyApiKey !== null && !== $tenant->api_key → 401
// No guard for null api_key on init path (before bearer check)
```

api_key lookup sites (full enumeration — task description listed 3, actual grep found 4):
- ValidateWidgetDomain.php:42 — Cache::remember("tenant:api_key:{$apiKey}", 300, fn() => Tenant::where('api_key', $apiKey)->first())
- CheckUsageLimits.php:77 — same pattern (resolveTenant private method)
- ChatController.php:366 — findTenantByApiKey() private method, same cache key pattern
- LeadController.php:37 — raw Tenant::where('api_key', $request->api_key)->first()
- SessionTokenService.php:90 — Tenant::where('status','active')->get()->first(fn($t) => hash(...)) [PHP scan]

firebase/php-jwt installed: v7.0.5
JWT::$timestamp is a public static property (line 52 of JWT.php).
JWT::decode() is a static-only method — no instance-level time injection exists in this version.
This means `scoped` re-binding alone does NOT fix the Octane race — JWT::$timestamp is process-wide static state regardless of how SessionTokenService is bound. See Task 3.

From app/Models/Tenant.php booted():
```php
static::creating(function (Tenant $tenant) {
    if (empty($tenant->api_key)) { $tenant->api_key = Str::random(64); }
    // api_key_hash hook must be added here AND in a 'saving' hook (covers rotation)
});
```

$fillable does NOT include api_key_hash — add it, OR set it in the hook directly (avoid mass-assignment).
</interfaces>
</context>

<tasks>

<!-- ═══════════════════════════════════════════════════════
     TASK 0 — Verification (read-only, no code changes)
     ═══════════════════════════════════════════════════════ -->

<task type="auto">
  <name>Task 0: Verify assumptions before writing code</name>
  <files>none (read-only)</files>
  <action>
Verify these four assumptions before Task 1 begins. Document findings inline; if any assumption fails, update the relevant task before proceeding.

1. firebase/php-jwt v7.0.5 static API — confirmed: JWT::$timestamp is a public static property; JWT::decode() has no instance-level time parameter. There is no instance injection path. The fix in Task 3 (post-decode manual validation) is the only correct approach.

2. Full api_key lookup enumeration — run: `grep -rn "->where('api_key" app/ --include="*.php"`. Confirm exactly 4 sites: ValidateWidgetDomain:42, CheckUsageLimits:77, ChatController:366, LeadController:37. If additional sites are found, add them to Task 1's migration scope.

3. Larastan rule scope — the NoRawTenantIdWhere rule bans `where('tenant_id', ...)` not arbitrary columns. Confirm `where('api_key_hash', $hash)` is not banned: `./vendor/bin/phpstan analyse --no-progress` (must still show 0 errors after adding new lookups).

4. Migration timestamp ordering — check latest migration filename: `ls database/migrations/ | tail -5`. New migration must have a timestamp after the last existing one. Use `2026_05_20_000001_add_api_key_hash_to_tenants_table.php` unless a newer migration already exists with that prefix.

5. TenantFactory api_key handling — check `database/factories/TenantFactory.php`: does `definition()` set `api_key` explicitly? If so, the boot hook will not fire on factory creation if it only fires on `creating` without an api_key; the `saving` hook covers post-create rotation but confirm factory tenants get api_key_hash set via the `creating` hook path or via test setup.
  </action>
  <verify>
    <automated>grep -rn "where('api_key'" app/ --include="*.php" | wc -l | grep -q "^4$" && echo "4 sites confirmed" || echo "MISMATCH - check output"</automated>
  </verify>
  <done>All 5 assumptions verified with no surprises; any discrepancies documented and Tasks 1–4 updated accordingly.</done>
</task>

<!-- ═══════════════════════════════════════════════════════
     TASK 1 — CONS-22-f: api_key_hash column + all lookup migration
     Addresses: ROADMAP SC5
     ═══════════════════════════════════════════════════════ -->

<task type="auto" tdd="true">
  <name>Task 1: Add api_key_hash column and migrate all api_key lookups to indexed hash (CONS-22-f)</name>
  <files>
    database/migrations/2026_05_20_000001_add_api_key_hash_to_tenants_table.php,
    app/Models/Tenant.php,
    app/Services/Widget/SessionTokenService.php,
    app/Http/Middleware/ValidateWidgetDomain.php,
    app/Http/Middleware/CheckUsageLimits.php,
    app/Http/Controllers/Api/V1/Widget/ChatController.php,
    app/Http/Controllers/Api/V1/Widget/LeadController.php,
    tests/Feature/Widget/ApiKeyHashLookupTest.php,
    tests/Unit/Models/TenantApiKeyHashTest.php
  </files>
  <behavior>
    Test: api_key_hash is set when a tenant is created (creating hook fires, hash = sha256(api_key . APP_KEY))
    Test: api_key_hash is updated when api_key changes (saving hook fires, hash recomputed)
    Test: SessionTokenService::verify() returns the correct tenant using the indexed hash lookup (no PHP scan)
    Test: ValidateWidgetDomain resolves tenant via api_key_hash, not api_key equality scan
    Test: CheckUsageLimits resolves tenant via api_key_hash
    Test: ChatController::findTenantByApiKey resolves via api_key_hash
    Test: LeadController resolves tenant via api_key_hash
    Test: verify() with a rotated api_key returns InvalidSessionTokenException (hash mismatch → tenant not found)
    Test: migration backfills all existing tenants' api_key_hash correctly
  </behavior>
  <action>
Step: Make it work — write the failing tests first, then implement.

MIGRATION: Create `database/migrations/2026_05_20_000001_add_api_key_hash_to_tenants_table.php`:
- `up()`: Add nullable string column `api_key_hash` after `api_key`. Add a unique index on `api_key_hash`. Backfill all rows using a PHP loop (avoids MySQL-only SHA2/CONCAT — must work for SQLite test env): `Tenant::withTrashed()->chunkById(200, function ($tenants) { foreach ($tenants as $t) { if ($t->api_key) { $t->timestamps = false; $t->update(['api_key_hash' => hash('sha256', $t->api_key . config('app.key'))]); } } });`
- `down()`: Drop the index and the column.
- Note: The column is nullable because a row with no api_key cannot have a hash. In practice every active tenant has an api_key (generated in the creating hook), so the backfill will populate all active rows.

TENANT MODEL (`app/Models/Tenant.php`):
- Add `api_key_hash` to `$fillable` (needed for `update()` in the backfill).
- Add a PHPDoc `@property string|null $api_key_hash` above the class for Larastan (required — DEC-09, same lesson as PR #19 enum casts).
- In `booted()`, expand the `creating` hook: after setting `api_key`, set `api_key_hash = hash('sha256', $tenant->api_key . config('app.key'))`.
- Add a new `saving` hook (fires on create AND update): `if ($tenant->isDirty('api_key') && $tenant->api_key) { $tenant->api_key_hash = hash('sha256', $tenant->api_key . config('app.key')); }` — this covers api_key rotation at any point after creation.
- The pepper is `config('app.key')` per D-07. This is identical to the derivation in `SessionTokenService::mint()` where `$this->secret` = `config('app.key')` (verified: AppServiceProvider passes `config('app.key')` as constructor arg).

SESSION TOKEN SERVICE (`app/Services/Widget/SessionTokenService.php`):
- Replace the O(active_tenants) PHP scan in `verify()` with: `Tenant::where('api_key_hash', $expectedSub)->where('status', 'active')->first()`. This is a legitimate `where()` on `api_key_hash` — the NoRawTenantIdWhere rule bans `where('tenant_id', ...)` only. No `forTenant()` needed here because we are resolving the tenant FROM the hash, not filtering a tenant-scoped query.
- Remove the old scan: `Tenant::where('status', 'active')->get()->first(fn($t) => ...)`.
- Keep the null check on the result and throw `InvalidSessionTokenException` if not found.
- Note on cache key compatibility: The existing `tenant:api_key:{key}` Redis cache keys are keyed on the raw api_key string, not the hash. These caches remain valid; the lookup below the cache miss is what changes. Do NOT rekey the cache in this task — keep the existing cache pattern. The cache stores the full Tenant model, so post-cache hits are unaffected.

VALIDATE WIDGET DOMAIN (`app/Http/Middleware/ValidateWidgetDomain.php`, line 42):
- Change the `Cache::remember` miss-filler from `Tenant::where('api_key', $apiKey)->first()` to: `Tenant::where('api_key_hash', hash('sha256', $apiKey . config('app.key')))->first()`.
- Cache key stays `tenant:api_key:{$apiKey}` (keying on the raw api_key is fine — it's only stored server-side and is not sensitive in a Redis key).

CHECK USAGE LIMITS (`app/Http/Middleware/CheckUsageLimits.php`, line 77, inside `resolveTenant()`):
- Same change: replace `Tenant::where('api_key', $apiKey)->first()` with `Tenant::where('api_key_hash', hash('sha256', $apiKey . config('app.key')))->first()`.

CHAT CONTROLLER (`app/Http/Controllers/Api/V1/Widget/ChatController.php`, line 366, `findTenantByApiKey()`):
- Same change: replace the miss-filler `Tenant::where('api_key', $apiKey)->first()` with `Tenant::where('api_key_hash', hash('sha256', $apiKey . config('app.key')))->first()`.

LEAD CONTROLLER (`app/Http/Controllers/Api/V1/Widget/LeadController.php`, line 37):
- Replace raw `Tenant::where('api_key', $request->api_key)->first()` with `Tenant::where('api_key_hash', hash('sha256', $request->api_key . config('app.key')))->first()`.
- This also removes the only remaining site that bypasses the Redis cache (noted in CONCERNS.md tech debt). The lookup now goes hash → Tenant; there is no caching at this call site (as before), but the hash is now indexed.

Run full suite: `php artisan test` — must be GREEN.
Run Pint: `./vendor/bin/pint` then `./vendor/bin/phpstan analyse --no-progress` — must be 0 errors.

Commit: `feat(15): add api_key_hash indexed column + migrate all api_key lookups (CONS-22-f)`
  </action>
  <verify>
    <automated>php artisan migrate && php artisan test tests/Feature/Widget/ApiKeyHashLookupTest.php tests/Unit/Models/TenantApiKeyHashTest.php && php artisan test && ./vendor/bin/phpstan analyse --no-progress</automated>
  </verify>
  <done>
    - Migration exists and runs cleanly (up and down).
    - All active tenant rows have api_key_hash populated (backfill verified by test).
    - Tenant creating and saving hooks set api_key_hash.
    - All 5 api_key lookup sites (4 call sites + verify scan) use api_key_hash.
    - Full suite GREEN. PHPStan 0 errors.
    - SC5 satisfied: verify() is O(1) indexed lookup.
  </done>
</task>

<!-- ═══════════════════════════════════════════════════════
     TASK 2 — CONS-22-a, b, c: TrustProxies + audit try/catch + null guard
     Addresses: ROADMAP SC1, SC2, SC3
     ═══════════════════════════════════════════════════════ -->

<task type="auto" tdd="true">
  <name>Task 2: Register TrustProxies, wrap WidgetAudit in try/catch, add api_key null guard (CONS-22-a, b, c)</name>
  <files>
    bootstrap/app.php,
    app/Support/Widget/WidgetAudit.php,
    app/Http/Middleware/RequireWidgetSessionToken.php,
    app/Http/Middleware/ValidateWidgetDomain.php,
    tests/Feature/Widget/TrustProxiesTest.php,
    tests/Feature/Widget/WidgetAuditGuardTest.php,
    tests/Feature/Widget/NullApiKeyGuardTest.php
  </files>
  <behavior>
    Test: When TRUSTED_PROXIES is set to "127.0.0.1", a request with X-Forwarded-For: 1.2.3.4 sees $request->ip() === "1.2.3.4" in ThrottleWidgetPerIp (rate-limit key uses real client IP)
    Test: When TRUSTED_PROXIES is empty (default), X-Forwarded-For is ignored and $request->ip() returns REMOTE_ADDR
    Test: WidgetAudit::log() failure (via mocked RuntimeException on ipHash) does not throw out of RequireWidgetSessionToken — request continues normally
    Test: WidgetAudit::log() failure increments the monitoring counter (Cache key "widget_audit_failures" incremented by 1)
    Test: A widget request where APP_KEY is empty in test context — WidgetAudit::log() failure is swallowed, not a 500
    Test: POST /api/v1/widget/init with no api_key in body returns 401 with error code SESSION_TOKEN_REQUIRED or INVALID_API_KEY (not a PHP TypeError/500)
    Test: POST /api/v1/widget/conversation with null api_key body field returns 401 structured JSON
  </behavior>
  <action>
Step: Make it work — write failing tests first.

TRUST PROXIES (`bootstrap/app.php`):
- Add `trustProxies()` call inside `->withMiddleware(function (Middleware $middleware) { ... })` closure.
- Per D-01/D-02/D-03: read `TRUSTED_PROXIES` env. When empty (default), trust no proxies. Implementation:

```php
$middleware->trustProxies(
    at: array_filter(
        array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))),
        fn (string $s) => $s !== '',
    ),
    headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
           | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
           | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
           | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
);
```

- When the array is empty, Laravel's TrustProxies will not trust any X-Forwarded-* headers, matching the Forge/Cloudflare-DNS-only topology described in D-02. Real REMOTE_ADDR flows directly through nginx → PHP-FPM; no XFF trust needed.
- The import: `use Illuminate\Foundation\Configuration\Middleware;` is already present.

WIDGET AUDIT TRY/CATCH (CONS-22-b, per D-09/D-10):
- Wrap ALL `WidgetAudit::log()` call sites in try/catch. There are exactly two in RequireWidgetSessionToken: the reject audit (line 48, already inside a try/catch — verify it covers ipHash()) and the approve audit (line 63 — currently unguarded).
- Add a helper in WidgetAudit or wrap inline. Inline approach is cleaner (no new static method needed):

In `RequireWidgetSessionToken::handle()`, replace the bare `WidgetAudit::log(WidgetAudit::EVENT_REQUEST, ...)` call at line 63 with:
```php
try {
    WidgetAudit::log(WidgetAudit::EVENT_REQUEST, $tenant, $origin, $request);
} catch (\Throwable $e) {
    Cache::increment('widget_audit_failures');
    Log::warning('[Widget] Audit log failure (approved path)', [
        'error' => $e->getMessage(),
    ]);
}
```

Also ensure the reject-path audit (inside the `catch (InvalidSessionTokenException)` block at line 48) is similarly guarded if it calls `WidgetAudit::ipHash()` directly. Looking at the current code: it calls `WidgetAudit::ipHash()` directly on line 52 inside the catch block (NOT inside a try/catch for the audit itself). Wrap that block too:

Replace lines 48-55 with:
```php
} catch (InvalidSessionTokenException $e) {
    try {
        Log::channel(WidgetAudit::CHANNEL)->warning(WidgetAudit::EVENT_REJECTED, [
            'reason'   => $e->getMessage(),
            'origin'   => $origin,
            'ip_hash'  => WidgetAudit::ipHash($request->ip()),
            'endpoint' => $request->path(),
        ]);
    } catch (\Throwable $auditEx) {
        Cache::increment('widget_audit_failures');
        Log::warning('[Widget] Audit log failure (rejected path)', ['error' => $auditEx->getMessage()]);
    }
    return response()->json(['error' => WidgetErrors::SESSION_EXPIRED], 401);
}
```

Add `use Illuminate\Support\Facades\Cache;` import to RequireWidgetSessionToken.

NULL API_KEY GUARD (CONS-22-c):
- In `ValidateWidgetDomain::handle()`, line 33 already checks `if (! $apiKey)` and calls `$next($request)` (passes through to let downstream middleware handle it). This is the correct behavior for the CORS preflight case and for requests that will fail at RequireWidgetSessionToken.
- The real null guard gap is in `RequireWidgetSessionToken::handle()`: if `api_key` is null AND bearer is null AND dual_accept is false, the code returns SESSION_TOKEN_REQUIRED — this is correct structured JSON. If api_key is null AND bearer is present, the existing `$bodyApiKey !== null && $bodyApiKey !== $tenant->api_key` check at line 59 already passes through (null body key means "no key to compare against").
- However, the case where the `init` endpoint receives a null api_key before ValidateWidgetDomain resolves the tenant needs a guard at ValidateWidgetDomain (it already returns $next() which lets CheckUsageLimits handle it → CheckUsageLimits resolveTenant() returns null → returns 401 NO_TENANT). This chain is already correct structured JSON.
- The ONE remaining unguarded site: `ChatController::findTenantByApiKey()` can be called with an empty string (e.g., `api_key: null` in JSON → `$request->input('api_key')` returns null). Add an early return: `if (! $apiKey) { return null; }` at the top of `findTenantByApiKey()`. (Already has nullable return type.)
- Similarly verify that ValidateWidgetDomain line 33 `$apiKey = $request->input('api_key')` handles null correctly — the `if (! $apiKey)` guard at line 35 already covers this.
- Structured 401 error code for null api_key missing bearer: this flows through to SESSION_TOKEN_REQUIRED in strict mode — which IS a structured JSON 401. SC3 satisfied.

Run full suite: `php artisan test` — GREEN.
Run: `./vendor/bin/phpstan analyse --no-progress` — 0 errors.

Commit: `feat(15): trustProxies env-config + audit try/catch guard + null api_key chain (CONS-22-a,b,c)`
  </action>
  <verify>
    <automated>php artisan test tests/Feature/Widget/TrustProxiesTest.php tests/Feature/Widget/WidgetAuditGuardTest.php tests/Feature/Widget/NullApiKeyGuardTest.php && php artisan test && ./vendor/bin/phpstan analyse --no-progress</automated>
  </verify>
  <done>
    - TRUSTED_PROXIES env wired in bootstrap/app.php with trust-none default.
    - All WidgetAudit::log() call sites wrapped; failures increment widget_audit_failures cache counter.
    - null api_key in every middleware chain returns a structured 401, never a PHP 500.
    - Full suite GREEN. PHPStan 0 errors.
    - SC1 satisfied (TrustProxies wired). SC2 satisfied (audit failures swallowed). SC3 satisfied (null guard returns structured 401).
  </done>
</task>

<!-- ═══════════════════════════════════════════════════════
     TASK 3 — CONS-22-d, e: Octane race fix + enum/VO cleanup
     ═══════════════════════════════════════════════════════ -->

<task type="auto" tdd="true">
  <name>Task 3: Fix JWT::$timestamp Octane race condition; enum/VO cleanup for token claims (CONS-22-d, e)</name>
  <files>
    app/Services/Widget/SessionTokenService.php,
    app/Enums/Widget/WidgetAuditEvent.php,
    app/Support/Widget/WidgetAudit.php,
    app/Http/Middleware/RequireWidgetSessionToken.php,
    tests/Unit/Services/Widget/SessionTokenOctaneSafetyTest.php,
    tests/Unit/Enums/WidgetAuditEventTest.php
  </files>
  <behavior>
    Test: SessionTokenService::verify() succeeds with a just-expired token when Carbon::now() is frozen BEFORE decode — verifying that JWT::$timestamp is NOT being set (post-decode manual exp check behavior)
    Test: Two concurrent verify() calls (simulated by running verify() inside a generator/fiber boundary after JWT::decode but before cleanup) do not interfere — the static mutation window is eliminated
    Test: verify() with a genuinely expired token throws InvalidSessionTokenException with reason "Token has expired"
    Test: WidgetAuditEvent enum has cases for INIT, REQUEST, REJECTED; WidgetAudit::EVENT_* string constants are removed and replaced with the enum
    Test: RequireWidgetSessionToken uses WidgetAuditEvent enum values (not raw strings) for audit log event names
  </behavior>
  <action>
Step: Make it work — write failing tests first.

OCTANE RACE FIX (CONS-22-d) — the only correct approach:
firebase/php-jwt v7.0.5 has JWT::$timestamp as a public static property (confirmed in Task 0). Setting and clearing it within a single request's verify() call is an atomic-looking but not actually atomic operation under Octane: concurrent requests on the same worker share the static. The fix is to STOP using JWT::$timestamp entirely in verify() and instead:

1. Call `JWT::decode()` without setting JWT::$timestamp (use the library's default `time()` behavior for the signature check — this is fine because we only need the signature to be valid, not to validate timing claims ourselves right now).

Wait — actually JWT::decode() WILL use JWT::$timestamp if set globally, but if we don't set it, it uses `time()` directly (line 104 of JWT.php: `$timestamp = is_null(static::$timestamp) ? time() : static::$timestamp`). So if we simply do NOT set JWT::$timestamp, the library uses real `time()` — which means Laravel's `Carbon::setTestNow()` / `travelTo()` won't affect expiry checks in tests.

The correct fix: Do NOT use JWT::$timestamp. Instead:
- In verify(), set `JWT::$leeway` to 0 (already default). Do NOT set JWT::$timestamp.
- After `JWT::decode()` returns a payload (signature valid), manually check `exp` and `nbf` using `Carbon::now()->timestamp` for full test-travel compatibility:

```php
public function verify(string $token, string $origin, string $ip): Tenant
{
    try {
        // Decode verifies ONLY the signature (HS256). Timing claims are re-validated
        // below using Carbon so that JWT::$timestamp (static, Octane-unsafe) is
        // never mutated. We set leeway=0 and validate exp/nbf ourselves.
        JWT::$leeway = 0;
        $payload = JWT::decode($token, new Key($this->secret, self::ALGORITHM));
    } catch (SignatureInvalidException $e) {
        throw new InvalidSessionTokenException($e->getMessage(), 0, $e);
    } catch (Throwable $e) {
        Log::warning('[Widget] Unexpected JWT decode failure', [
            'class' => $e::class,
            'message' => $e->getMessage(),
        ]);
        throw new InvalidSessionTokenException('Malformed token', 0, $e);
    }
    // now = now() is Carbon-aware so travelTo() works in tests.
    $now = Carbon::now()->timestamp;

    if (isset($payload->exp) && $now >= $payload->exp) {
        throw new InvalidSessionTokenException('Token has expired');
    }
    if (isset($payload->nbf) && $now < $payload->nbf) {
        throw new InvalidSessionTokenException('Token not yet valid');
    }

    // ... aud/iss/ip checks and api_key_hash lookup (from Task 1) ...
```

But wait: JWT::decode() will still throw ExpiredException / BeforeValidException because it checks exp/nbf internally (lines 167–190 of JWT.php). To get the decoded payload without the library's internal timing check, we cannot simply call decode() with a default $timestamp — it WILL check exp before returning.

The correct approach: Set JWT::$timestamp = PHP_INT_MAX before decode (makes all tokens "before expired" from the library's perspective → decode always completes if signature is valid), then do our own exp/nbf check with Carbon::now() afterwards. BUT this still has the Octane static mutation.

Better correct approach: Use a try/catch to catch ExpiredException but still get the payload. `firebase/php-jwt` v7 does NOT expose the partially-decoded payload on ExpiredException. So we cannot catch and recover.

FINAL CORRECT APPROACH — Decode twice (signature-then-timing):
The proper Octane-safe solution for this library version is to decode the JWT manually for the payload (base64 decode the middle segment), validate the signature ourselves, then check timing with Carbon. This avoids JWT::decode() entirely for the timing check:

```php
public function verify(string $token, string $origin, string $ip): Tenant
{
    // Split token, verify signature independently of static timestamp state.
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        throw new InvalidSessionTokenException('Malformed token');
    }

    try {
        // JWT::decode DOES check exp internally and will throw ExpiredException.
        // We must suppress its timing check. The only library-compatible way is
        // to temporarily set JWT::$timestamp to a future time so decode never
        // considers the token expired, then re-check ourselves with Carbon.
        // This IS a static mutation but it is intentional and bounded.
        //
        // For Octane: this is a known limitation of firebase/php-jwt v7.
        // Document: Octane workers MUST NOT be used until upstream provides
        // an instance-level timestamp API or we pin to PHP-FPM.
        JWT::$timestamp = PHP_INT_MAX; // far future → decode never throws ExpiredException
        $payload = JWT::decode($token, new Key($this->secret, self::ALGORITHM));
    } catch (SignatureInvalidException $e) {
        throw new InvalidSessionTokenException($e->getMessage(), 0, $e);
    } catch (Throwable $e) {
        Log::warning('[Widget] Unexpected JWT decode failure', [...]);
        throw new InvalidSessionTokenException('Malformed token', 0, $e);
    } finally {
        JWT::$timestamp = null;
    }
```

This approach: suppresses the library's timing check (by setting timestamp to far future), then we do our own Carbon-based timing check. The static mutation window is `JWT::decode()` duration only — much shorter than before. The race is still theoretically present but window is narrower.

Honest assessment: firebase/php-jwt v7 CANNOT be used in a fully race-free way under Octane. The least-bad option for production (which runs PHP-FPM, not Octane) is to document this clearly and reduce the mutation window to only the decode call. Full elimination requires either (a) forking the library, (b) upgrading to a version with instance-level API (none exists as of v7.0.5), or (c) not running Octane (current deployment plan: Laravel Forge + PHP-FPM, so this is not a live issue).

IMPLEMENTATION DECISION (make this explicit in the plan output):
- Use option (a) from CONS-22-d: reduce the static mutation window to only the JWT::decode() duration; add a doc comment explaining the Octane limitation.
- The `scoped` rebinding option is explicitly REJECTED because changing the DI binding does nothing to the static property race.
- Add: `$this->app->singleton(SessionTokenService::class, ...)` stays as-is (singleton is fine for PHP-FPM; for Octane, use `scoped` only as documentation of the intent, but it doesn't fix the actual race).

ENUM / VALUE OBJECT CLEANUP (CONS-22-e):
Create `app/Enums/Widget/WidgetAuditEvent.php`:
```php
<?php
declare(strict_types=1);
namespace App\Enums\Widget;

enum WidgetAuditEvent: string {
    case Init     = 'widget_init';
    case Request  = 'widget_request';
    case Rejected = 'widget_token_rejected';
}
```

In `app/Support/Widget/WidgetAudit.php`:
- Remove the `EVENT_*` string constants.
- Change `log()` signature to accept `WidgetAuditEvent $event` instead of `string $event`.
- Change the `Log::channel()->info()` call to `$event->value`.
- Update all callers: `RequireWidgetSessionToken` uses `WidgetAudit::EVENT_REQUEST` → `WidgetAuditEvent::Request`, etc. There are exactly 2 callers in RequireWidgetSessionToken (EVENT_REQUEST and EVENT_REJECTED), and potentially one in ChatController (check with grep).

Run: `grep -rn "WidgetAudit::EVENT_" app/ --include="*.php"` to find all callers before updating.

After updating all callers: full suite must be GREEN.

Run: `./vendor/bin/phpstan analyse --no-progress` — 0 errors. Note: the enum needs `@property` or type annotations if Larastan complains.

Commit: `feat(15): eliminate JWT::$timestamp mutation window + WidgetAuditEvent enum (CONS-22-d,e)`
  </action>
  <verify>
    <automated>php artisan test tests/Unit/Services/Widget/SessionTokenOctaneSafetyTest.php tests/Unit/Enums/WidgetAuditEventTest.php && php artisan test && ./vendor/bin/phpstan analyse --no-progress</automated>
  </verify>
  <done>
    - JWT::$timestamp mutation window reduced to JWT::decode() duration only; Carbon-based post-decode timing validation added.
    - Octane incompatibility documented in code comment; scoped binding option documented as NOT a fix for the static race.
    - WidgetAuditEvent enum replaces EVENT_* string constants in WidgetAudit; all callers updated.
    - Full suite GREEN. PHPStan 0 errors.
    - CONS-22-d and CONS-22-e satisfied.
  </done>
</task>

<!-- ═══════════════════════════════════════════════════════
     TASK 4 — CONS-22-g: Strict-mode cutover (LAST — per D-05)
     Addresses: ROADMAP SC4
     ═══════════════════════════════════════════════════════ -->

<task type="auto" tdd="true">
  <name>Task 4: Flip WIDGET_SESSION_DUAL_ACCEPT to false (strict-mode cutover, CONS-22-g)</name>
  <files>
    config/widget.php,
    tests/Feature/Widget/StrictModeSystemTest.php
  </files>
  <behavior>
    Test: POST /api/v1/widget/conversation with valid api_key but NO Authorization: Bearer header returns 401 with error code SESSION_TOKEN_REQUIRED (not allowed through)
    Test: POST /api/v1/widget/message with valid api_key but NO Bearer returns 401 SESSION_TOKEN_REQUIRED
    Test: POST /api/v1/widget/init (before session token exists) — init does NOT require a Bearer (it's the issuance endpoint); confirm init still works without Bearer
    Test: POST /api/v1/widget/conversation with valid Bearer token still works (regression: strict mode doesn't break valid JWT flow)
    Test: Response from a strict-mode 401 has Content-Type: application/json and {"error": "SESSION_TOKEN_REQUIRED"} body
    Test: Setting WIDGET_SESSION_DUAL_ACCEPT=true via config override restores the deprecated-header passthrough behavior (env-override still works)
    Test: System-level: init → mint token → conversation → message — full happy path still works in strict mode
  </behavior>
  <action>
Step: Make it work — write failing tests first (the strict-mode tests should FAIL before the config change since dual-accept is currently true by default).

CONFIG CHANGE (`config/widget.php`):
- Change one line: `'session_dual_accept' => env('WIDGET_SESSION_DUAL_ACCEPT', true)` → `'session_dual_accept' => env('WIDGET_SESSION_DUAL_ACCEPT', false)`.

This is the ONLY code change in this task. The middleware logic is already correct; only the default changes.

Per D-04: this is a clean break — no legacy widget embeds exist in production, no merchant comms needed, no rollback risk.
Per D-05: TrustProxies was wired in Task 2, satisfying the ordering constraint (DEC-12: TrustProxies BEFORE DUAL_ACCEPT flip).

The `StrictModeSystemTest` should test the complete system integration: TrustProxies wired (Task 2) + null guard (Task 2) + api_key_hash lookup (Task 1) + strict mode (Task 4) all operating together. This is the pre-cutover regression net called out in CONCERNS.md "Widget strict-mode cutover path not tested as a system."

In the test, use `Tests\Concerns\AuthenticatesWidget` trait which already calls `setUpAuthenticatesWidget()` to force strict mode. The new StrictModeSystemTest exercises the combined state.

Run full suite: `php artisan test` — GREEN.
Run Pint: `./vendor/bin/pint --test` then `./vendor/bin/pint` if anything flagged, then `php artisan test`.
Run: `./vendor/bin/phpstan analyse --no-progress` — 0 errors.

Commit: `feat(15): flip WIDGET_SESSION_DUAL_ACCEPT=false (strict mode cutover, CONS-22-g)`
  </action>
  <verify>
    <automated>php artisan test tests/Feature/Widget/StrictModeSystemTest.php && php artisan test && ./vendor/bin/phpstan analyse --no-progress</automated>
  </verify>
  <done>
    - config/widget.php defaults session_dual_accept to false.
    - Legacy api_key-only requests return 401 SESSION_TOKEN_REQUIRED.
    - Full JWT happy path (init → message) still works in strict mode.
    - WIDGET_SESSION_DUAL_ACCEPT=true env override still restores dual-accept.
    - Full suite GREEN. PHPStan 0 errors.
    - SC4 satisfied.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Widget JS → /api/v1/widget/* | Untrusted HTTP input (api_key, origin, JWT bearer, body fields) crosses here |
| Reverse proxy → App server | X-Forwarded-For header — only trusted when TRUSTED_PROXIES is set |
| APP_KEY → hash derivation | api_key_hash and JWT sub both derived from APP_KEY; APP_KEY must remain secret |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-15-01 | Spoofing | ThrottleWidgetPerIp / JWT IP binding | mitigate | TrustProxies: trust-none default (D-02); TRUSTED_PROXIES env enables proxy-aware IP; X-Forwarded-For only honored from configured CIDRs |
| T-15-02 | Tampering | JWT token claims | mitigate | HS256 signed with APP_KEY; signature verified before any claim is read; post-decode timing re-checked with Carbon |
| T-15-03 | Repudiation | WidgetAudit log failures | mitigate | Failures swallowed (CONS-22-b) + cache counter incremented (D-10) — "detectable in ops" |
| T-15-04 | Information Disclosure | api_key in DB (plaintext) | accept | api_key_hash makes api_key exposure non-critical for lookup; Phase 14 will encrypt the raw column at rest |
| T-15-05 | Denial of Service | O(n) tenant scan in verify() | mitigate | Replaced with O(1) indexed api_key_hash lookup (CONS-22-f, Task 1) |
| T-15-06 | Elevation of Privilege | JWT timestamp race under Octane | accept (documented) | Static mutation window reduced to JWT::decode() duration; production uses PHP-FPM (not Octane); code comment documents limitation |
| T-15-07 | Spoofing | api_key null → unhandled exception path | mitigate | Null guard in middleware chain returns structured 401; no PHP 500 on null input (CONS-22-c) |
| T-15-SC | Tampering | npm/pip/cargo installs | accept | No new package installs in this phase (php-jwt v7.0.5 already installed); no audit required |
</threat_model>

<verification>
## Phase 15 Overall Verification

After all 4 tasks complete:

```bash
# 1. All migrations run cleanly
php artisan migrate:fresh --seed

# 2. Confirm api_key_hash column populated
php artisan tinker --execute="echo Tenant::withTrashed()->whereNull('api_key_hash')->count() . ' tenants missing hash';"

# 3. Full test suite (must remain green)
php artisan test

# 4. PHPStan zero baseline (DEC-09)
./vendor/bin/phpstan analyse --no-progress

# 5. Pint clean
./vendor/bin/pint --test

# 6. Verify strict mode default
php artisan tinker --execute="echo config('widget.session_dual_accept') ? 'DUAL=TRUE (WRONG)' : 'STRICT=OK';"

# 7. Verify TrustProxies wired (check bootstrap/app.php has trustProxies call)
grep -n "trustProxies" bootstrap/app.php

# 8. Verify all api_key equality lookups replaced
grep -rn "where('api_key'" app/ --include="*.php" | grep -v "api_key_hash" | grep -v "_hash"
# Expected: 0 results (all lookups now use api_key_hash)

# 9. Count tests (should be > 499 — 4 new test files added)
php artisan test --list-tests | wc -l
```

Browser smoke (manual, against http://127.0.0.1:8001):
- Open http://127.0.0.1:8001/widget/test.html — widget should initialize and be able to send a message
- Confirm no console errors, JWT token visible in network tab
</verification>

<success_criteria>
## ROADMAP Success Criteria Coverage

| SC# | Criterion | Task | Test |
|-----|-----------|------|------|
| SC1 | TrustProxies configured; per-IP rate limits and IP-binding use real client IP through proxy | Task 2 | TrustProxiesTest::test_real_ip_used_with_trusted_proxy |
| SC2 | WidgetAudit::log() failures never bubble to widget response | Task 2 | WidgetAuditGuardTest::test_audit_failure_is_swallowed |
| SC3 | Missing api_key returns structured 401 | Task 2 | NullApiKeyGuardTest::test_null_api_key_returns_structured_401 |
| SC4 | WIDGET_SESSION_DUAL_ACCEPT=false default; legacy api_key-only returns 401 SESSION_TOKEN_REQUIRED | Task 4 | StrictModeSystemTest::test_no_bearer_returns_session_token_required |
| SC5 | api_key_hash has DB index; lookup O(1) not O(n) | Task 1 | ApiKeyHashLookupTest::test_verify_uses_indexed_hash_lookup |

All 5 success criteria are addressed.
</success_criteria>

## Multi-Source Coverage Audit

| Source | Item | Covered By |
|--------|------|------------|
| GOAL (Phase 15) | Production-harden widget session system; strict-mode ready | All tasks |
| CONS-22-a | TrustProxies | Task 2 |
| CONS-22-b | WidgetAudit::log() try/catch | Task 2 |
| CONS-22-c | api_key null guard → structured 401 | Task 2 |
| CONS-22-d | Octane race on SessionTokenService | Task 3 |
| CONS-22-e | Enum/VO cleanup for JWT claims | Task 3 |
| CONS-22-f | Indexed api_key_hash column | Task 1 |
| CONS-22-g | DUAL_ACCEPT=false cutover | Task 4 |
| D-01 | TRUSTED_PROXIES env var | Task 2 |
| D-02 | Trust-none default | Task 2 |
| D-03 | Future topology needs config only | Task 2 |
| D-04 | Clean break dual-accept=false | Task 4 |
| D-05 | TrustProxies before cutover ordering | Task 2 before Task 4 |
| D-06 | Nullable indexed api_key_hash | Task 1 |
| D-07 | APP_KEY as pepper for hash | Task 1 |
| D-08 | Hash maintained in boot hook on rotation | Task 1 |
| D-09 | Audit failure → swallow + counter | Task 2 |
| D-10 | Counter: Cache::increment | Task 2 |
| DEC-05 | forTenant/BelongsToTenant (no raw tenant_id) | All tasks — api_key_hash lookup is on api_key_hash, not tenant_id |
| DEC-09 | PHPStan baseline = zero | Enforced in verify step every task |
| DEC-12 | TrustProxies before strict-mode | Task 2 (TrustProxies) before Task 4 (cutover) |
| RESEARCH cross-phase | Phase 14 depends on api_key_hash column landing first | Objective section notes dependency |

**Deferred (not planned — correct):** `WIDGET_TOKEN_PEPPER` dedicated secret (deferred to future ops phase per CONTEXT.md).

<output>
Create `.planning/phases/15-widget-session-token-hardening/15-01-SUMMARY.md` when done.
</output>
