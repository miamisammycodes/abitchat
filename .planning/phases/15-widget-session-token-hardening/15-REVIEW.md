---
phase: 15-widget-session-token-hardening
reviewed: 2026-05-20T00:00:00Z
depth: standard
files_reviewed: 20
files_reviewed_list:
  - database/migrations/2026_05_20_000001_add_api_key_hash_to_tenants_table.php
  - app/Enums/Widget/WidgetAuditEvent.php
  - app/Models/Tenant.php
  - app/Services/Widget/SessionTokenService.php
  - app/Support/Widget/WidgetAudit.php
  - app/Http/Middleware/RequireWidgetSessionToken.php
  - app/Http/Middleware/ValidateWidgetDomain.php
  - app/Http/Middleware/CheckUsageLimits.php
  - app/Http/Controllers/Api/V1/Widget/ChatController.php
  - app/Http/Controllers/Api/V1/Widget/LeadController.php
  - bootstrap/app.php
  - config/widget.php
  - database/seeders/DatabaseSeeder.php
  - tests/Unit/Models/TenantApiKeyHashTest.php
  - tests/Feature/Widget/ApiKeyHashLookupTest.php
  - tests/Feature/Widget/TrustProxiesTest.php
  - tests/Feature/Widget/WidgetAuditGuardTest.php
  - tests/Feature/Widget/NullApiKeyGuardTest.php
  - tests/Unit/Services/Widget/SessionTokenOctaneSafetyTest.php
  - tests/Unit/Enums/WidgetAuditEventTest.php
  - tests/Feature/Widget/StrictModeSystemTest.php
findings:
  critical: 2
  warning: 7
  info: 2
  total: 11
status: issues_found
---

# Phase 15: Code Review Report — Widget Session Token Hardening

**Reviewed:** 2026-05-20
**Depth:** standard
**Files Reviewed:** 20
**Status:** issues_found

## Summary

Phase 15 implements CONS-22 a-g: an `api_key_hash` blind-index column, Octane-safe JWT verification, audit-log exception guards, TrustProxies wiring, and strict-mode default. The JWT verification core (`SessionTokenService::verify()`) is sound — manual `hash_hmac` + `hash_equals`, alg pinning to HS256, full `exp`/`nbf`/`iat` validation, no `JWT::$timestamp` mutation, `aud`/`iss`/`ip` claim checks. WidgetAudit `try/catch` guards on both approve and reject paths correctly increment a counter and log to a separate channel before continuing. TrustProxies defaults to trust-none.

However, the partial migration off raw `api_key` lookups has left two production-exploitable gaps in the caching layer that undermine the api_key-hash and rotation guarantees the phase set out to establish:

1. **Raw api_key still stored in cache keys.** Three call sites cache by `tenant:api_key:{rawApiKey}`. The DB column was renamed `api_key_hash` precisely so a DB dump cannot reveal raw keys — but a Redis dump now leaks every active api_key in plaintext.
2. **Rotation cache invalidation only works through one specific UI path.** `WidgetController::regenerateApiKey()` invalidates by old-key. Any rotation through factory/admin tooling/console/future jobs leaves the stale cached Tenant valid for up to 5 minutes — and `RequireWidgetSessionToken` line 68 compares the body `api_key` against the cached (stale) `$tenant->api_key`, so the old key continues to authenticate.

Several test files also assert wiring/file-content rather than behavior, weakening the regression net for the imminent strict-mode cutover.

## Critical Issues

### CR-01: Raw api_key persisted in cache keys defeats the api_key_hash blind-index goal

**Files:**
- `app/Http/Middleware/ValidateWidgetDomain.php:40`
- `app/Http/Middleware/CheckUsageLimits.php:75`
- `app/Http/Controllers/Api/V1/Widget/ChatController.php:366,373`

**Issue:** Phase 15 introduces `api_key_hash` to ensure the database never stores or indexes the raw API key, so a DB dump cannot reveal active credentials. The three lookup call sites then build a cache key from the raw `api_key`:

```php
$tenant = Cache::remember(
    "tenant:api_key:{$apiKey}",
    300,
    fn () => Tenant::where('api_key_hash', hash('sha256', $apiKey.config('app.key')))->first(),
);
```

The Redis/cache backend now stores the raw api_key as part of its key. A `KEYS tenant:api_key:*` (or any cache dump, replication snapshot, debug log of a `Cache::get()` miss, RedisInsight inspection) discloses every active api_key in plaintext — including for inactive tenants whose keys were ever looked up during the 5-minute TTL window. This re-introduces the exact storage surface the migration was meant to eliminate, and is strictly worse than the prior state because the rationale-for-removal is now documented in the codebase.

**Fix:** Key the cache by `api_key_hash`, which is non-secret-equivalent for lookup purposes:

```php
$hash = hash('sha256', $apiKey.config('app.key'));
$tenant = Cache::remember(
    "tenant:api_key_hash:{$hash}",
    300,
    fn () => Tenant::where('api_key_hash', $hash)->first(),
);
```

Apply identically in `CheckUsageLimits::resolveTenant`, `ChatController::findTenantByApiKey`, and update `WidgetController::regenerateApiKey` to forget by `hash('sha256', $oldKey.config('app.key'))` instead of `$oldKey`. The cache key prefix change (`tenant:api_key:` → `tenant:api_key_hash:`) naturally invalidates the existing stale entries on deploy without an explicit flush.

---

### CR-02: api_key rotation outside `WidgetController::regenerateApiKey` leaves a stale auth-cache window

**Files:**
- `app/Models/Tenant.php:81-85` (missing invalidation hook)
- `app/Http/Middleware/RequireWidgetSessionToken.php:68` (consumes the stale value)
- `app/Http/Middleware/ValidateWidgetDomain.php:39-43`, `app/Http/Middleware/CheckUsageLimits.php:74-78`, `app/Http/Controllers/Api/V1/Widget/ChatController.php:366-376` (cache writers)

**Issue:** The model's `saving` hook recomputes `api_key_hash` when `api_key` rotates, but no hook invalidates `tenant:api_key:{$oldKey}`. Only `WidgetController::regenerateApiKey()` (line 78) does — and only for the one UI path. Any rotation through:

- Database seeders or factories during tests (`Tenant::create([..., 'api_key' => $explicitKey])` then subsequent `update`)
- `php artisan tinker` admin maintenance
- Future admin-on-behalf rotation tooling
- Background jobs (e.g., automatic rotation after a compromise)
- Direct `$tenant->update(['api_key' => ...])` anywhere in code other than `WidgetController`

...leaves the cache populated with the **old** Tenant object (whose `api_key` attribute is the OLD value). For up to 5 minutes after rotation:

1. `ChatController::findTenantByApiKey($oldApiKey)` → cache hit, returns stale tenant.
2. `RequireWidgetSessionToken` line 68 compares `$bodyApiKey !== $tenant->api_key` — both are the OLD value, so the check **passes**.
3. The old key continues to authenticate widget API calls.

The previous code matched by `Tenant::where('api_key', $apiKey)`, so a DB-level rotation would invalidate the next lookup; the new hash-based lookup combined with raw-key cache keying makes the cache the sole bottleneck and there's nothing tying its invalidation to the rotation event. Note also that the spec calls out post-rotation invalidation as the load-bearing security guarantee (it's why `SessionTokenService::mint()` hashes the api_key into `sub` rather than the tenant_id).

**Fix:** Move invalidation into the model's `saved` hook so every rotation path is covered uniformly:

```php
static::saved(function (Tenant $tenant) {
    Cache::forget("tenant:{$tenant->id}:with_plan");

    if ($tenant->wasChanged('api_key')) {
        $oldKey = $tenant->getOriginal('api_key');
        if ($oldKey) {
            Cache::forget("tenant:api_key:{$oldKey}");
            // Or, after CR-01 fix:
            Cache::forget('tenant:api_key_hash:'.hash('sha256', $oldKey.config('app.key')));
        }
    }
});
```

Then drop the now-redundant `Cache::forget` in `WidgetController::regenerateApiKey` (CLAUDE.md "no dual-system support" — model-level invalidation is the single source of truth, same pattern as the existing `with_plan` invalidation).

Add a feature test that rotates `api_key` directly on the model (not through `WidgetController`) and asserts that a request bearing the old api_key + old Bearer is rejected immediately, not after TTL.

## Warnings

### WR-01: Non-constant-time comparison of api_key in widget request path

**File:** `app/Http/Middleware/RequireWidgetSessionToken.php:68`

**Issue:**

```php
if ($bodyApiKey !== null && $bodyApiKey !== $tenant->api_key) {
    return response()->json(['error' => WidgetErrors::SESSION_EXPIRED], 401);
}
```

This is a non-constant-time string comparison of an authenticated secret. Practical exploitability is low because (a) the JWT signature has already been verified by this point, and (b) the api_key is sent over TLS so attacker-controlled timing of the network round-trip dominates. But the codebase already establishes `hash_equals` as the constant-time idiom (used at `SessionTokenService.php:83`), and this is a security-critical hardening phase whose stated theme is "no timing-channel comparisons of secrets."

**Fix:**

```php
if ($bodyApiKey !== null && ! hash_equals((string) $tenant->api_key, (string) $bodyApiKey)) {
    return response()->json(['error' => WidgetErrors::SESSION_EXPIRED], 401);
}
```

---

### WR-02: `saving` hook leaves api_key_hash stale when api_key is cleared

**File:** `app/Models/Tenant.php:81-85`

**Issue:**

```php
static::saving(function (Tenant $tenant) {
    if ($tenant->isDirty('api_key') && $tenant->api_key) {
        $tenant->api_key_hash = hash('sha256', $tenant->api_key.config('app.key'));
    }
});
```

If `api_key` is ever set to `null` or `''` (e.g., a future admin "disable widget" flow, or a buggy mass-update), `isDirty('api_key')` is true but `$tenant->api_key` is falsy, so the branch is skipped and `api_key_hash` retains the value from the OLD api_key. The orphaned hash continues to resolve to this tenant via the indexed lookup — meaning a tenant who has had their key "cleared" still authenticates by the old key for as long as the hash row exists. Not exploitable today (no code path nulls the column), but a clear future foot-gun and the kind of "hash and credential drift" issue that's hard to debug retroactively.

**Fix:** Clear the hash explicitly when the source is cleared:

```php
static::saving(function (Tenant $tenant) {
    if ($tenant->isDirty('api_key')) {
        $tenant->api_key_hash = $tenant->api_key
            ? hash('sha256', $tenant->api_key.config('app.key'))
            : null;
    }
});
```

---

### WR-03: Migration backfill is not transactional and runs after the unique index is added

**File:** `database/migrations/2026_05_20_000001_add_api_key_hash_to_tenants_table.php:12-29`

**Issue:** The unique index on `api_key_hash` is created in the `Schema::table` call before the backfill loop runs. If the backfill fails partway (OOM during a large `chunkById`, a hash collision in a corrupted dataset, model boot-hook exception on a malformed legacy row), the migration is left half-applied: column exists, index exists, some rows backfilled, others NULL. Re-running the migration will fail at `Schema::table` because the column already exists. Operators have to hand-roll partial recovery.

Also: the migration uses `$t->update(['api_key_hash' => ...])` which loads the model and fires `saving`/`saved` model events on every row. With the `static::saved` hook in `Tenant.php:87-89` calling `Cache::forget` per row, a 200-row chunk does 200 cache deletes against the production cache backend during deploy — and the `saving` hook (line 81) also recomputes the same hash, doing the SHA-256 twice per row.

**Fix:**

```php
public function up(): void
{
    Schema::table('tenants', function (Blueprint $table) {
        $table->string('api_key_hash')->nullable()->after('api_key');
    });

    DB::transaction(function () {
        Tenant::withTrashed()->whereNotNull('api_key')->chunkById(200, function ($tenants) {
            foreach ($tenants as $t) {
                // Use a raw UPDATE to skip model events and avoid re-firing
                // the saving/saved hooks (which would re-hash and bust cache).
                DB::table('tenants')
                    ->where('id', $t->id)
                    ->update(['api_key_hash' => hash('sha256', $t->api_key.config('app.key'))]);
            }
        });
    });

    Schema::table('tenants', function (Blueprint $table) {
        $table->unique('api_key_hash');
    });
}
```

Adding the unique index AFTER the backfill also lets the migration fail loudly on a true duplicate rather than silently skipping an `update` with a unique constraint violation buried in a chunk.

---

### WR-04: `DatabaseSeeder.php` missing `declare(strict_types=1)` (CLAUDE.md violation)

**File:** `database/seeders/DatabaseSeeder.php:1-3`

**Issue:** CLAUDE.md mandates `declare(strict_types=1);` on all PHP files; every other file modified in this phase has it. The seeder was edited in this phase (line 65-78 sets `api_key_hash` explicitly to compensate for `WithoutModelEvents`), so this is a phase regression, not pre-existing debt.

**Fix:** Add `declare(strict_types=1);` after the opening `<?php` tag.

---

### WR-05: TrustProxies tests don't verify TrustProxies behavior

**File:** `tests/Feature/Widget/TrustProxiesTest.php`

**Issues:**

1. `test_trust_proxies_is_wired_in_bootstrap` (line 53-59) and `test_trusted_proxies_default_is_empty_trust_none` (line 61-69) both do `file_get_contents(base_path('bootstrap/app.php'))` + `assertStringContainsString`. These pass for any file that contains the literal strings `'trustProxies'` and `"env('TRUSTED_PROXIES'"` — including a commented-out call. They assert nothing about runtime behavior.

2. `test_trusted_proxy_forwards_real_ip_via_x_forwarded_for` (line 30-51) sets `X-Forwarded-For: 1.2.3.4`, sets `app.trusted_proxies` config (not the real config key — `TrustProxies` reads from the `at:` argument constructed at boot time in `bootstrap/app.php`, which has already executed), and asserts only `$response->assertOk()`. There is no assertion that `$request->ip()` returned `1.2.3.4` vs `127.0.0.1`. The test passes whether TrustProxies works or not.

The TrustProxies wiring is the load-bearing prerequisite for the strict-mode cutover (per memory entry `widget_session_tokens_pr29.md`: "TrustProxies must land before strict-mode cutover or IP-binding + rate-limits collapse to proxy IP") — the regression net here is too thin to catch a regression.

**Fix:** Add a behavioral test that resolves the request IP via a controller or middleware spy:

```php
public function test_x_forwarded_for_is_honored_when_remote_addr_is_in_trusted_list(): void
{
    // Override the trusted-proxy list at runtime via Request::setTrustedProxies
    // (the boot-time list from env is empty in tests).
    \Illuminate\Http\Request::setTrustedProxies(['127.0.0.1'], \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR);

    Route::get('/__test_ip', fn (Request $r) => ['ip' => $r->ip()]);

    $this->withHeaders(['X-Forwarded-For' => '1.2.3.4'])
        ->get('/__test_ip')
        ->assertJson(['ip' => '1.2.3.4']);
}

public function test_x_forwarded_for_is_ignored_when_remote_addr_is_not_trusted(): void
{
    \Illuminate\Http\Request::setTrustedProxies([], \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR);
    Route::get('/__test_ip', fn (Request $r) => ['ip' => $r->ip()]);

    $this->withHeaders(['X-Forwarded-For' => '1.2.3.4'])
        ->get('/__test_ip')
        ->assertJsonMissing(['ip' => '1.2.3.4']);
}
```

The two `file_get_contents` tests should be deleted — they're testing source-text presence, not behavior.

---

### WR-06: `TenantApiKeyHashTest::test_migration_backfill_populates_hash_for_existing_tenants` does not test the migration

**File:** `tests/Unit/Models/TenantApiKeyHashTest.php:79-98`

**Issue:** The test name and comment claim it verifies "migration backfill works correctly," but the body just calls `Tenant::create(...)` and asserts the `creating` model hook ran. This is identical to `test_creating_hook_sets_hash_even_when_api_key_is_factory_provided` (line 29-42) — duplicate coverage and misleading naming. The migration's `Tenant::withTrashed()->chunkById()` loop is never exercised.

To actually test the migration backfill, you'd need to:
1. Insert a row via `DB::table('tenants')->insert([...])` (bypassing model hooks) with `api_key_hash = null`.
2. Re-run the migration's backfill closure.
3. Assert the hash is now populated.

**Fix:** Either rewrite the test body to exercise the migration logic (preferred), or rename it to `test_creating_hook_handles_explicit_api_key_with_short_legacy_format` (whatever it's really testing) and delete the misleading docblock. Right now this is dead coverage — a future PR that breaks the migration's chunked backfill will not be caught by this test despite its name implying it would.

---

### WR-07: `LeadController::capture` does inline raw tenant lookup with no caching, diverging from sibling controllers

**File:** `app/Http/Controllers/Api/V1/Widget/LeadController.php:37`

**Issue:**

```php
$tenant = Tenant::where('api_key_hash', hash('sha256', $request->api_key.config('app.key')))->first();
```

`ChatController` has a `findTenantByApiKey` helper with caching (line 360-377); `ValidateWidgetDomain` and `CheckUsageLimits` use `Cache::remember`. `LeadController` does neither — every lead capture hits the DB for the same lookup. Once CR-01 is fixed (cache by `api_key_hash`), this divergence is a maintainability liability: four call sites for the same lookup with different signatures.

Additionally, this is the **fourth copy** of the hash-derivation expression `hash('sha256', $apiKey.config('app.key'))`. CLAUDE.md "no dual-system support" / "choose one and commit" applies: extract a single helper.

**Fix:** Add a static helper to `Tenant`:

```php
public static function findByApiKey(string $apiKey): ?self
{
    if ($apiKey === '') {
        return null;
    }
    $hash = hash('sha256', $apiKey.config('app.key'));
    return Cache::remember(
        "tenant:api_key_hash:{$hash}",
        300,
        fn () => static::where('api_key_hash', $hash)->first(),
    );
}
```

Then replace all four call sites with `Tenant::findByApiKey($apiKey)`. (This also makes the model `saved` cache-invalidation hook in CR-02 a clean single-target invalidation.)

## Info

### IN-01: `WidgetAudit::ipHash()` throws on empty APP_KEY but the caller assumes it cannot fail at construction time

**File:** `app/Support/Widget/WidgetAudit.php:29-32`

**Issue:** `WidgetAudit::ipHash()` throws `RuntimeException('APP_KEY must be set for widget audit IP hashing')` when `config('app.key')` is empty. The callers in `RequireWidgetSessionToken` wrap this in `try { ... } catch (\Throwable $e)` and silently increment a counter — which is the intended behavior per CONS-22-b.

However, `SessionTokenService` is constructed via `AppServiceProvider::register()` which already throws on empty APP_KEY (line 28-30). And the `Tenant` model boot hook (line 77) computes `api_key_hash = hash('sha256', $key . config('app.key'))` with no empty-key guard at all — meaning if `APP_KEY` were ever empty in production, the model layer would silently produce `hash('sha256', $key . '')` and every tenant would collide on the same hash prefix space, while the audit layer would loudly throw and get swallowed.

The three layers should be consistent: either all three guard against empty APP_KEY at the same boundary (the service provider, which is already doing it for `SessionTokenService`), or none do (rely on Laravel's existing APP_KEY-not-set boot error). Mixing them creates the surprising property that an empty APP_KEY produces hash collisions on tenant creation but throws on audit logging.

**Fix:** Move the empty-key check up to a single boot-time guard in `AppServiceProvider::register()` (e.g., via an `app.key` validation on boot), and drop the per-call `RuntimeException` in `WidgetAudit::ipHash`. The `try/catch` in `RequireWidgetSessionToken` is still useful for log-channel failures, just not for APP_KEY misconfiguration.

---

### IN-02: `captureLeadFromMessage` re-reads the full user-message history on every chat turn

**File:** `app/Http/Controllers/Api/V1/Widget/ChatController.php:382-407`

**Issue:** Per-turn lead extraction calls:

```php
$messages = $conversation->messages()->where('role', 'user')->get();
$allContent = $messages->pluck('content')->implode(' ');
```

On every message, this re-reads ALL prior user messages from the conversation just to extract a candidate name. For a long conversation this is O(n) per turn, O(n²) over the conversation. It also re-runs `extractName` against text that was already scanned in prior turns. (Out of v1 review scope per CLAUDE.md, but flagged because the new userMessage `Message::create` happens AFTER `captureLeadFromMessage` is called inside the chat-controller — so the current message isn't even in the result set being scanned. The function may be doing more work than it needs to AND missing the most recent message; verify against test coverage.)

**Fix:** Pass the current message in directly and only fall back to history if name extraction fails:

```php
private function captureLeadFromMessage(Conversation $conversation, string $message): void
{
    $contactInfo = $this->leadService->extractContactInfo($message);
    if (empty($contactInfo)) return;

    if (empty($contactInfo['name'])) {
        $contactInfo['name'] = $this->extractName($message)
            ?? $this->extractName(
                $conversation->messages()->where('role', 'user')
                    ->latest('id')->limit(5)->get()->pluck('content')->implode(' ')
            );
    }
    // ...
}
```

---

_Reviewed: 2026-05-20_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
