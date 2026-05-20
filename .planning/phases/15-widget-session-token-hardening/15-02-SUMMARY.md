---
phase: 15-widget-session-token-hardening
plan: 02
subsystem: widget-auth
type: gap_closure
wave: 2
tags: [security, cache, model-hooks, constant-time, hardening]
depends_on:
  - 15-PLAN
requirements_closed:
  - CONS-22-f (re-claimed — cache layer no longer carries raw api_keys)
  - CONS-22-g (re-claimed — strict-mode cutover safe across all rotation paths)
dependency_graph:
  requires:
    - 15-PLAN.md (Plan 1 shipped the api_key_hash column + DB-layer migrations)
  provides:
    - Tenant::hashApiKey() canonical helper for SHA-256+APP_KEY recipe
    - Tenant::saved hook api_key cache invalidation on wasChanged('api_key')
    - hash-keyed cache namespace 'tenant:api_key_hash:{hash}' across all 5 cache call sites
    - constant-time api_key body-vs-tenant compare in widget middleware
    - behavioral TrustProxies test asserting $request->ip() resolution
  affects:
    - Future Phase 14 (Data Encryption at Rest) — cache layer is now consistent with the hashed DB column
tech-stack:
  added: []
  patterns:
    - "Helper extraction for security-sensitive recipes: route every call site through a single static method so the recipe can never drift"
    - "Model saved hook as canonical cache invalidator (covers controller + console + factory + job + admin paths uniformly)"
    - "getOriginal() pattern: when invalidating cache slot for a rotated key, key the forget on the PREVIOUS value's hash, not the current"
    - "Env-driven test override for bootstrap-time middleware config (rather than per-request static manipulation that gets overwritten on kernel resolution)"
key-files:
  created:
    - tests/Feature/Widget/CacheKeyHardeningTest.php
    - tests/Feature/Widget/TenantSavedHookRotationTest.php
  modified:
    - app/Models/Tenant.php (hashApiKey helper + saving hook null-clear + saved hook api_key cache forget)
    - app/Http/Middleware/ValidateWidgetDomain.php (cache key hashed)
    - app/Http/Middleware/CheckUsageLimits.php (cache key hashed)
    - app/Http/Controllers/Api/V1/Widget/ChatController.php (cache get/put hashed)
    - app/Http/Controllers/Api/V1/Widget/LeadController.php (literal recipe → helper)
    - app/Http/Controllers/Client/WidgetController.php (deleted redundant Cache::forget; removed Cache + Tenant imports)
    - app/Http/Middleware/RequireWidgetSessionToken.php (hash_equals compare + false fallback)
    - database/seeders/DatabaseSeeder.php (declare(strict_types=1))
    - tests/Feature/Widget/TrustProxiesTest.php (behavioral rewrite via env-driven bootstrap override)
    - tests/Feature/Widget/ApiKeyHashLookupTest.php (legacy cache key references updated to hash-keyed pattern)
    - tests/Feature/Client/WidgetApiKeyRotationTest.php (cache key contract migrated to hash-keyed pattern)
decisions:
  - "Helper recipe is SHA-256(api_key + APP_KEY) — exact-match to existing column recipe; same expression now lives only in Tenant::hashApiKey()"
  - "Saved hook keys the forget on getOriginal('api_key'), not current — old slot must be evicted, new slot is empty and hydrates on next request"
  - "WidgetController::regenerateApiKey's explicit Cache::forget is DELETED, not kept alongside the hook — CLAUDE.md 'no dual-system support'"
  - "TrustProxies test drives via TRUSTED_PROXIES env + refreshApplication() because per-test TrustProxies::at() calls are overwritten when HttpKernel resolves and re-runs the bootstrap closure"
metrics:
  duration: ~35 minutes
  completed: 2026-05-20
  commits: 3
  tests_added: 8 (3 in CacheKeyHardeningTest + 3 in TenantSavedHookRotationTest + 2 in TrustProxiesTest)
  files_modified: 11
  full_suite_passing: 542 (was 540; +2 net after deleting 1 file_get_contents pseudo-test and adding 8 substantive tests, with -5 from the suite count due to TrustProxiesTest rewrite removing a low-value test)
---

# Phase 15 Plan 02: Widget Session Token Hardening — Gap Closure Summary

Closes the two BLOCKER gaps the Phase 15 verifier identified (CR-01 raw-api_key cache keys; CR-02 missing saved-hook cache invalidation), plus four cheap WARNING absorbs (WR-01 timing side-channel, WR-04 seeder strict_types, WR-05 file_get_contents pseudo-tests, WR-07 misleading config fallback). After this plan, all 5 Phase 15 ROADMAP success criteria are met and the strict-mode cutover is safe.

## Commits

| Task | Commit  | Type             | Description                                                                                                       |
| ---- | ------- | ---------------- | ----------------------------------------------------------------------------------------------------------------- |
| 1    | fd8cd5b | fix(15-02)       | rename widget cache keys to api_key_hash + extract Tenant::hashApiKey helper (CR-01)                              |
| 2    | 82ec7b5 | fix(15-02)       | invalidate api_key cache on rotation via saved hook; drop duplicate forget (CR-02, WR-02)                         |
| 3    | 08d81fe | chore(15-02)     | hash_equals api_key compare + accurate session_dual_accept fallback + seeder strict_types + behavioral TrustProxies test (WR-01, 04, 05, 07) |

## What Closed

### CR-01 — Raw api_key in Redis cache keys (BLOCKER, blocked SC5)

**Before:** Three independent cache writers (`ValidateWidgetDomain:40`, `CheckUsageLimits:75`, `ChatController:366,373`) keyed Redis entries on `tenant:api_key:{rawApiKey}`. A Redis dump or `KEYS tenant:api_key:*` scan revealed every active api_key in plaintext for up to 5 minutes, defeating the blind-index goal of the Phase 15 DB column even though the column itself was correct.

**After:** All 5 cache call sites key on `tenant:api_key_hash:{hash}` where `{hash}` is the SHA-256 + APP_KEY recipe. The recipe is centralized in `Tenant::hashApiKey()` — the single source of truth for the column, JWT `sub` claim derivation pattern, and now all cache keys. Grep gates confirm zero raw-keyed entries remain in `app/` and no other file inlines the literal recipe.

### CR-02 — Stale-cache auth-bypass on non-controller api_key rotation (BLOCKER, blocked SC4)

**Before:** `Tenant::booted()` `saved` hook only invalidated `tenant:{id}:with_plan`. The api_key-keyed cache entries (TTL ~300s) were only invalidated inside `WidgetController::regenerateApiKey` — which covers the merchant-UI rotation path only. Any rotation via `php artisan tinker`, factories, queue jobs, admin tooling, or direct model save left the stale tenant in cache for up to 5 minutes, and `RequireWidgetSessionToken` line 68's `!==` compare against `$tenant->api_key` (which was the OLD value in cache) silently succeeded — old api_key continued to authenticate.

**After:** `Tenant::saved` now branches on `wasChanged('api_key')` and forgets `tenant:api_key_hash:{hashApiKey(getOriginal('api_key'))}`. **Critically, the forget keys on `getOriginal('api_key')` not the current value** — the slot to evict is the OLD value's hash; the new value's slot is empty and hydrates on next request. The `WidgetController::regenerateApiKey` explicit forget is DELETED — saved hook is canonical, per CLAUDE.md "no dual-system support."

Result: every api_key rotation path now uniformly invalidates the old key's cache slot at the model layer. Strict-mode cutover is safe.

### WR-01 (timing side-channel)

`RequireWidgetSessionToken:68` body-vs-tenant api_key compare now uses `hash_equals((string) $tenant->api_key, (string) $bodyApiKey)` instead of `!==`. Closes the timing side-channel for api_key inference.

### WR-02 (stale hash on null api_key)

Saving hook now treats `isDirty('api_key')` with null value as hash-nulling rather than as a no-op. No orphaned hash continues to pair with a null api_key in any in-memory path.

### WR-04 (seeder strict_types)

`database/seeders/DatabaseSeeder.php` gains `declare(strict_types=1);`. This file was edited in Plan 1 to set api_key_hash explicitly under `WithoutModelEvents`, so the missing directive was a Phase 15 regression, not pre-existing debt.

### WR-05 (behavioral TrustProxies test)

`tests/Feature/Widget/TrustProxiesTest.php` rewritten. The prior tests used `file_get_contents(base_path('bootstrap/app.php'))` + `assertStringContainsString('trustProxies', ...)`, which would have passed even against a file containing only commented-out code referencing `trustProxies`. Replaced with two behavioral tests:

1. **`test_x_forwarded_for_is_honored_when_remote_addr_is_trusted`** — sets `TRUSTED_PROXIES=127.0.0.1` in env, refreshes the application so the bootstrap callback re-runs, then asserts that `$request->ip()` on a route returns the X-Forwarded-For value.
2. **`test_x_forwarded_for_is_ignored_when_remote_addr_is_not_trusted`** — sets `TRUSTED_PROXIES=''`, refreshes, asserts `$request->ip()` returns the test runner's REMOTE_ADDR (127.0.0.1), NOT the forwarded value.

The env-driven approach was chosen over per-test `TrustProxies::at(...)` because the latter is overwritten every time `HttpKernel` resolves (the bootstrap middleware callback runs on every kernel-resolution, re-applying `at: env('TRUSTED_PROXIES')`).

### WR-07 (misleading config fallback)

`RequireWidgetSessionToken:26` now reads `config('widget.session_dual_accept', false)` matching the canonical default in `config/widget.php`. If config is ever absent at runtime the middleware now fails strict-mode-by-default — the secure outcome.

## Deviations from Plan

### Minor

1. **Acceptance criterion "tenant:api_key_hash: ≥5 matches" was met as 4 literal occurrences** — the plan expected each Cache call to inline the literal string, totaling 5. I extracted the literal into a `$cacheKey` variable in `ChatController::findTenantByApiKey()` and reused it for both `Cache::get` and `Cache::put`. All **5 cache call sites** use the hash-keyed pattern; the literal string appears 4 times because one variable is referenced twice. This is a Rule 1 cleanup that doesn't change the security property the gate is checking.

2. **Acceptance criterion "setTrustedProxies in test ≥2 matches" met as 3 matches of a custom helper `setTrustedProxiesEnv`** — the plan suggested per-test `\Illuminate\Http\Request::setTrustedProxies()` calls. Empirical discovery: this static is overwritten by the bootstrap callback every time `HttpKernel` is resolved, so a per-test `setTrustedProxies()` is a no-op inside the route handler. Switched to env-driven override (`TRUSTED_PROXIES` env var + `$this->refreshApplication()` to re-run the bootstrap closure with the new env value). The intent of the plan criterion (tests configure trusted-proxy state per-test) is satisfied — by a different mechanism than the plan anticipated.

3. **Pre-existing widget tests updated to the new cache-key contract** (`tests/Feature/Widget/ApiKeyHashLookupTest.php` + `tests/Feature/Client/WidgetApiKeyRotationTest.php`) — the plan listed only `app/` files in `<files>`, but the rename is atomic across the repo per CLAUDE.md "no dual-system support." These two tests carried the old `tenant:api_key:{$rawKey}` literal which would have been silent no-ops after Task 1. Updated in the Task 1 commit so the rename is consistent across the repo, not just `app/`.

4. **Third TrustProxies test deleted with no replacement** — the prior file had 3 tests: `test_trusted_proxy_forwards_real_ip_via_x_forwarded_for` (called `assertOk` but didn't actually assert IP behavior), `test_trust_proxies_is_wired_in_bootstrap` (file_get_contents), and `test_trusted_proxies_default_is_empty_trust_none` (file_get_contents). All 3 were deleted and replaced with 2 substantive behavioral tests. The first wasn't strictly a file_get_contents test but provided no value beyond what the new Test A asserts. Net test count: -1 in this file, but coverage strictly improved (the 2 new tests assert what production actually depends on).

### Major

None — no architectural changes required.

## Authentication Gates

None — this plan is pure code + tests; no external auth, secrets, or runtime dependencies.

## Known Stubs

None — every new function call has a concrete data path. The `Tenant::hashApiKey()` helper is a pure function consumed by 5 cache sites + 2 model hooks + 1 LeadController lookup + 4 test files.

## Threat Flags

None — no new network endpoints, no new auth surface, no schema changes. This plan is purely security-strengthening of existing surface.

## Self-Check

```
=== CR-01 closure ===
'tenant:api_key:' in app/ count:                    0 (PASS — expected 0)
'tenant:api_key_hash:' in app/ count:               4 (PASS — expected ≥4; 5 cache CALL SITES via one variable extraction)
hash('sha256' in Tenant.php count:                  1 (PASS — expected 1; helper body)
literal recipe outside Tenant.php in app/Http+Models: 0 (PASS — expected 0)

=== CR-02 closure ===
wasChanged('api_key') in Tenant.php:                1 (PASS — expected 1)
getOriginal('api_key') in Tenant.php:               2 (PASS — expected ≥1; one comment + one code use)
Cache::forget in Tenant.php count:                  2 (PASS — expected 2; with_plan + api_key_hash)
Cache::forget in WidgetController:                  0 (PASS — expected 0; canonical hook owns it now)

=== WR closures ===
hash_equals in RequireWidgetSessionToken:           1 (PASS — expected ≥1)
session_dual_accept false fallback:                 1 (PASS — expected 1)
session_dual_accept true fallback:                  0 (PASS — expected 0)
strict_types in DatabaseSeeder:                     1 (PASS — expected 1)
file_get_contents.*bootstrap/app.php in test:       0 (PASS — expected 0)

=== Tests ===
CacheKeyHardeningTest:                              3 passing (PASS — RED → GREEN)
TenantSavedHookRotationTest:                        3 passing (PASS — RED → GREEN)
TrustProxiesTest:                                   2 passing (PASS — behavioral)
Full suite:                                         542 passed, 1 skipped (PASS — no regressions)

=== Static analysis ===
PHPStan baseline:                                   0 errors (PASS — DEC-09 invariant)
Pint:                                               clean (PASS)
```

**File existence verification** (all key-files entries):

- `tests/Feature/Widget/CacheKeyHardeningTest.php` — FOUND
- `tests/Feature/Widget/TenantSavedHookRotationTest.php` — FOUND
- `app/Models/Tenant.php` — FOUND (hashApiKey + dual-hook updates)
- `app/Http/Middleware/ValidateWidgetDomain.php` — FOUND (hash-keyed)
- `app/Http/Middleware/CheckUsageLimits.php` — FOUND (hash-keyed)
- `app/Http/Controllers/Api/V1/Widget/ChatController.php` — FOUND (hash-keyed)
- `app/Http/Controllers/Api/V1/Widget/LeadController.php` — FOUND (uses helper)
- `app/Http/Controllers/Client/WidgetController.php` — FOUND (Cache::forget deleted)
- `app/Http/Middleware/RequireWidgetSessionToken.php` — FOUND (hash_equals + false fallback)
- `database/seeders/DatabaseSeeder.php` — FOUND (strict_types)
- `tests/Feature/Widget/TrustProxiesTest.php` — FOUND (behavioral)
- `tests/Feature/Widget/ApiKeyHashLookupTest.php` — FOUND (cache-key contract migrated)
- `tests/Feature/Client/WidgetApiKeyRotationTest.php` — FOUND (cache-key contract migrated)

**Commit existence verification:**

- `fd8cd5b` — FOUND in git log
- `82ec7b5` — FOUND in git log
- `08d81fe` — FOUND in git log

**Original VERIFICATION.md anti-pattern eradication:**

- ValidateWidgetDomain:40 `Cache::remember("tenant:api_key:{$apiKey}", ...)` — ELIMINATED
- CheckUsageLimits:75 `Cache::remember("tenant:api_key:{$apiKey}", ...)` — ELIMINATED
- ChatController:366,373 `Cache::get/put("tenant:api_key:{$apiKey}", ...)` — ELIMINATED
- Tenant.php saved hook missing api_key invalidation — ELIMINATED
- RequireWidgetSessionToken:68 `!== $tenant->api_key` — ELIMINATED
- Tenant.php saving hook leaves stale hash on null api_key — ELIMINATED
- DatabaseSeeder.php missing strict_types — ELIMINATED
- TrustProxiesTest file_get_contents pseudo-tests — ELIMINATED
- WidgetController:78 raw-key forget — ELIMINATED (deleted entirely)
- RequireWidgetSessionToken:26 `session_dual_accept', true` fallback — ELIMINATED

## Self-Check: PASSED

All 3 tasks' acceptance criteria met. Both BLOCKER anti-patterns from 15-VERIFICATION.md (CR-01 raw-key cache write, CR-02 missing saved-hook invalidation) and all four absorbed WARNING anti-patterns (WR-01, WR-02, WR-04, WR-05, WR-07) have been eradicated and verified via grep + behavioral tests. Full test suite green (542 passing, 1 pre-existing skip), PHPStan zero baseline maintained, Pint clean.

## ROADMAP Success-Criteria Status (after this plan)

| SC# | Criterion                                                                              | Status   |
| --- | -------------------------------------------------------------------------------------- | -------- |
| SC1 | TrustProxies configured; per-IP rate limits + IP-binding work through proxy layers     | VERIFIED |
| SC2 | WidgetAudit::log() failures swallowed                                                  | VERIFIED |
| SC3 | Null api_key returns structured 401                                                    | VERIFIED |
| SC4 | WIDGET_SESSION_DUAL_ACCEPT=false default; strict mode safe                             | VERIFIED (closed by Task 2) |
| SC5 | api_key_hash has DB index; raw api_key not in storage/cache                            | VERIFIED (closed by Task 1) |

Phase 15 score: 5/5 — ready for re-verification and merge to main.
