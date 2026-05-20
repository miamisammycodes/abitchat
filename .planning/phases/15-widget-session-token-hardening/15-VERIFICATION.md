---
phase: 15-widget-session-token-hardening
verified: 2026-05-20T13:30:00Z
status: passed
score: 5/5 must-haves verified
overrides_applied: 0
gaps: []
re_verification: true
prior_status: gaps_found (3/5)
---

# Phase 15: Widget Session Token Hardening — Verification Report (Re-verification after gap closure)

**Phase Goal:** The widget session token system is hardened to production-grade reliability and ready for strict-mode cutover, with TrustProxies correctly configured so IP-binding and rate limits work through all proxy layers.
**Verified:** 2026-05-20T13:30:00Z
**Status:** passed
**Re-verification:** Yes — after gap closure (`15-02-PLAN` executed across commits `fd8cd5b → 08d81fe`, docs sync `0bdc685 → f96c4da`)
**Prior Status:** `gaps_found (3/5)` — CR-01 (raw api_key in cache keys) + CR-02 (missing saved-hook invalidation)

---

## Goal Achievement

### Observable Truths

| #   | Truth                                                                                                | Status     | Evidence                                                                                                                                                                                                                                                                                                                                              |
| --- | ---------------------------------------------------------------------------------------------------- | ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| SC1 | TrustProxies configured; per-IP rate limits + IP-binding work through proxy layers                   | ✓ VERIFIED | `bootstrap/app.php:25` `$middleware->trustProxies(at: env-driven CIDR list, headers: HEADER_X_FORWARDED_FOR\|HOST\|PORT\|PROTO)` wired unchanged. `TrustProxiesTest` now asserts **behavior** (`$request->ip()` resolution via ad-hoc route) instead of source text — covers both "trusted → honored" and "untrusted → ignored" branches (2 passing). |
| SC2 | WidgetAudit::log() calls wrapped in try/catch; failure never bubbles                                 | ✓ VERIFIED | `RequireWidgetSessionToken.php` lines 52–62 (Rejected path) and 74–79 (Approved path) both wrapped with `\Throwable` catch + `Cache::increment('widget_audit_failures')`. Unchanged by gap plan.                                                                                                                                                      |
| SC3 | api_key null guard; missing api_key returns structured 401 not unhandled exception                   | ✓ VERIFIED | `RequireWidgetSessionToken.php:37` returns `{error: SESSION_TOKEN_REQUIRED}` 401. Unchanged.                                                                                                                                                                                                                                                          |
| SC4 | WIDGET_SESSION_DUAL_ACCEPT defaults to false; strict mode SAFE (rotation immediately invalidates)    | ✓ VERIFIED | `config/widget.php:6` defaults false. `Tenant::saved` hook (line 102–117) now branches on `wasChanged('api_key')`, derives `$oldKey = getOriginal('api_key')`, calls `Cache::forget('tenant:api_key_hash:'.self::hashApiKey($oldKey))`. `RequireWidgetSessionToken.php:68` also uses constant-time `hash_equals` for body-vs-tenant api_key compare. `TenantSavedHookRotationTest::test_rotated_old_api_key_no_longer_authenticates` exercises the auth-bypass scenario end-to-end and asserts 401 after direct-model rotation.        |
| SC5 | api_key_hash column indexed; raw api_key NOT in storage OR cache                                     | ✓ VERIFIED | Zero matches for `tenant:api_key:` in `app/`. All 5 cache call sites key on `tenant:api_key_hash:{hash}` via the canonical `Tenant::hashApiKey()` helper (literal appears 4× because ChatController extracted `$cacheKey` for get+put — same key, deduplicated). `CacheKeyHardeningTest` (3 passing) is the regression net.                            |

**Score: 5/5 truths verified**

---

### Required Artifacts

| Artifact                                                  | Expected                                                                | Status     | Details                                                                                                                                                                  |
| --------------------------------------------------------- | ----------------------------------------------------------------------- | ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `app/Models/Tenant.php`                                   | `hashApiKey()` helper + null-clearing saving hook + cache-invalidating saved hook | ✓ VERIFIED | Helper present at line 72–75 (sole `hash('sha256'` site in file). `saving` hook (line 94–100) nulls hash on null api_key. `saved` hook (line 102–117) invalidates old slot on rotation. |
| `app/Http/Middleware/ValidateWidgetDomain.php`            | Cache key + miss-filler use `Tenant::hashApiKey()`                       | ✓ VERIFIED | Line 40 (cache key) + line 42 (miss filler) — both via helper.                                                                                                           |
| `app/Http/Middleware/CheckUsageLimits.php`                | Cache key + miss-filler use `Tenant::hashApiKey()`                       | ✓ VERIFIED | Line 75 (cache key) + line 77 (miss filler) — both via helper.                                                                                                           |
| `app/Http/Controllers/Api/V1/Widget/ChatController.php`   | Cache get/put on hashed key                                              | ✓ VERIFIED | Line 366 extracts `$cacheKey`; line 368 (`Cache::get`) + line 375 (`Cache::put`) reuse the same hashed key. Line 373 miss-filler also via helper.                        |
| `app/Http/Controllers/Api/V1/Widget/LeadController.php`   | DB lookup via helper                                                     | ✓ VERIFIED | Line 37 uses `Tenant::where('api_key_hash', Tenant::hashApiKey($request->api_key))`.                                                                                     |
| `app/Http/Controllers/Client/WidgetController.php`        | No explicit Cache::forget; saved hook is canonical                       | ✓ VERIFIED | `regenerateApiKey()` (line 68–80) carries only `$tenant->update(['api_key' => ...])`. Cache + Tenant imports removed. Comment cites CR-02 fix + CLAUDE.md "no dual-system support". |
| `app/Http/Middleware/RequireWidgetSessionToken.php`       | `hash_equals` constant-time compare + `false` config fallback           | ✓ VERIFIED | Line 26 fallback now `false`. Line 68 uses `hash_equals((string) $tenant->api_key, (string) $bodyApiKey)`.                                                                |
| `database/seeders/DatabaseSeeder.php`                     | `declare(strict_types=1)` present                                        | ✓ VERIFIED | Line 3 has the directive.                                                                                                                                                |
| `tests/Feature/Widget/CacheKeyHardeningTest.php`          | RED-first regression net for cache key shape                             | ✓ VERIFIED | 3 tests passing. Asserts the raw-keyed slot stays empty AND the hashed slot is populated after a real widget request (ValidateWidgetDomain + ChatController paths).      |
| `tests/Feature/Widget/TenantSavedHookRotationTest.php`    | RED-first regression net for non-controller rotation invalidation        | ✓ VERIFIED | 3 tests passing. Critically `test_rotated_old_api_key_no_longer_authenticates` proves the auth-bypass window is gone (old key → 401 after `$tenant->update()`).          |
| `tests/Feature/Widget/TrustProxiesTest.php`               | Behavioral test (replaces file_get_contents pseudo-tests)                | ✓ VERIFIED | 2 tests passing. Both drive `TRUSTED_PROXIES` env + `refreshApplication()` + ad-hoc route that returns `$request->ip()`. Substantive replacement.                        |

---

### Key Link Verification

| From                                                | To                                       | Via                                                                                          | Status        | Details                                                                                                                                                                 |
| --------------------------------------------------- | ---------------------------------------- | -------------------------------------------------------------------------------------------- | ------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Tenant::saved` hook                                | Old api_key cache slot                   | `Cache::forget('tenant:api_key_hash:'.self::hashApiKey($tenant->getOriginal('api_key')))`     | ✓ WIRED       | Branches on `wasChanged('api_key')`; uses `getOriginal()` (correct slot — the OLD value's hash). Test `test_direct_model_rotation_invalidates_old_api_key_cache` proves it. |
| 5 cache call sites + 2 model hooks + 1 DB-only site | `Tenant::hashApiKey()`                   | Static method call                                                                            | ✓ WIRED       | 9 distinct callers route through the single helper. Recipe never duplicated within `app/Models` + `app/Http`.                                                            |
| `RequireWidgetSessionToken` api_key compare         | Constant-time string equality            | `hash_equals(...)`                                                                            | ✓ WIRED       | Line 68. Old `!==` comparison eradicated.                                                                                                                                |
| `RequireWidgetSessionToken` dual-accept resolution  | `config('widget.session_dual_accept')`   | `(bool) config('widget.session_dual_accept', false)`                                          | ✓ WIRED       | Line 26 fallback matches `config/widget.php:6` (both `false`).                                                                                                           |
| `bootstrap/app.php` TrustProxies                    | Global middleware stack                  | `$middleware->trustProxies(at: TRUSTED_PROXIES env list, headers: HEADER_X_FORWARDED_FOR\|...)` | ✓ WIRED (reaffirmed) | Unchanged; new behavioral test now actually exercises `$request->ip()` resolution under both trusted and untrusted REMOTE_ADDR.                                       |

**Resolved key links from prior run:**
- `ValidateWidgetDomain` / `CheckUsageLimits` / `ChatController` → `Tenant` (cache) — was **PARTIAL (CR-01)**. Now **WIRED**: cache key matches DB-lookup key.
- `Tenant::booted()` saved hook → api_key cache invalidation — was **NOT_WIRED (CR-02)**. Now **WIRED** via `wasChanged('api_key')` branch.

---

### Data-Flow Trace (Level 4)

| Artifact                              | Data Variable               | Source                                                                                                  | Produces Real Data | Status      |
| ------------------------------------- | --------------------------- | ------------------------------------------------------------------------------------------------------- | ------------------ | ----------- |
| `SessionTokenService::verify()`       | `$tenant`                   | `Tenant::where('api_key_hash', ...)->first()`                                                            | Real DB query on indexed column | ✓ FLOWING |
| `RequireWidgetSessionToken`           | `$tenant`                   | `Cache::remember('tenant:api_key_hash:'.hash, fn () => Tenant::where('api_key_hash', hash)->first())` | Real query; cache key matches DB query — cache hit and miss produce the same tenant deterministically                  | ✓ FLOWING (was HOLLOW) |
| `ValidateWidgetDomain` / `CheckUsageLimits` / `ChatController` | `$tenant` | Same hash-keyed `Cache::remember` pattern via `Tenant::hashApiKey()` helper                              | Real query                          | ✓ FLOWING (was HOLLOW) |
| `Tenant::saved` cache invalidation    | Old api_key cache slot      | `Cache::forget('tenant:api_key_hash:'.self::hashApiKey($tenant->getOriginal('api_key')))`                | Targets the correct (OLD) slot for every rotation path | ✓ FLOWING (was DISCONNECTED) |

---

### Behavioral Spot-Checks

| Behavior                                                              | Command                                                                                          | Result                                                                                       | Status |
| --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------- | ------ |
| Raw api_key cache pattern eradicated                                  | `grep -rn "tenant:api_key:" app/ --include='*.php'`                                              | (no matches)                                                                                 | ✓ PASS (was FAIL) |
| Hashed cache pattern present at 4 sites (5 logical, ChatController dedup) | `grep -rn "tenant:api_key_hash:" app/ --include='*.php'`                                       | Tenant.php:114; ValidateWidgetDomain:40; CheckUsageLimits:75; ChatController:366 — 4 lines, 5 logical uses | ✓ PASS  |
| `hash('sha256'` literal count in Tenant.php = 1                       | `grep -c "hash('sha256'" app/Models/Tenant.php`                                                  | `1` (the helper body)                                                                        | ✓ PASS |
| saved hook on rotation invalidates cache                              | `grep -n "wasChanged('api_key')" app/Models/Tenant.php`                                          | Line 111                                                                                     | ✓ PASS (was FAIL) |
| getOriginal('api_key') used for cache key derivation                  | `grep -n "getOriginal('api_key')" app/Models/Tenant.php`                                         | Lines 107 (comment) + 112 (use)                                                              | ✓ PASS |
| WidgetController explicit Cache::forget deleted                        | `grep -n "Cache::forget" app/Http/Controllers/Client/WidgetController.php`                       | (no matches)                                                                                 | ✓ PASS |
| Constant-time api_key compare                                          | `grep -n "hash_equals" app/Http/Middleware/RequireWidgetSessionToken.php`                        | Line 68                                                                                      | ✓ PASS (was WR-01) |
| session_dual_accept fallback matches config                           | `grep -n "session_dual_accept', true\|session_dual_accept', false" app/Http/Middleware/RequireWidgetSessionToken.php` | Only `false` match on line 26; zero `true` matches                                | ✓ PASS (was WR-07) |
| Seeder strict_types                                                    | `grep -n "declare(strict_types=1)" database/seeders/DatabaseSeeder.php`                          | Line 3                                                                                       | ✓ PASS (was WR-04) |
| TrustProxies behavioral test (no file_get_contents)                    | `grep -n "file_get_contents.*bootstrap/app.php" tests/Feature/Widget/TrustProxiesTest.php`       | (no matches)                                                                                 | ✓ PASS (was WR-05) |
| `CacheKeyHardeningTest`                                                | `php artisan test --filter=CacheKeyHardeningTest`                                                | 3 passed (7 assertions)                                                                      | ✓ PASS |
| `TenantSavedHookRotationTest`                                          | `php artisan test --filter=TenantSavedHookRotationTest`                                          | 3 passed (7 assertions)                                                                      | ✓ PASS |
| `TrustProxiesTest`                                                     | `php artisan test --filter=TrustProxiesTest`                                                     | 2 passed (5 assertions)                                                                      | ✓ PASS |
| Full suite                                                             | `php artisan test`                                                                               | 542 passed, 1 skipped (1314 assertions)                                                      | ✓ PASS |
| PHPStan baseline                                                       | `./vendor/bin/phpstan analyse --no-progress`                                                     | `No errors`                                                                                  | ✓ PASS |
| Pint                                                                   | `./vendor/bin/pint --test`                                                                       | `{"result":"pass"}`                                                                          | ✓ PASS |

---

### Requirements Coverage

| Requirement | Description                                                                            | Status      | Evidence                                                                                                            |
| ----------- | -------------------------------------------------------------------------------------- | ----------- | ------------------------------------------------------------------------------------------------------------------- |
| CONS-22-a   | TrustProxies correctly configured so per-IP rate limits + IP-binding work through proxies | ✓ SATISFIED | `bootstrap/app.php:25` wired; `TrustProxiesTest` now asserts behavior end-to-end.                                  |
| CONS-22-b   | WidgetAudit::log() wrapped in try/catch; failure never propagates                       | ✓ SATISFIED | Both audit paths guarded; `widget_audit_failures` counter still incremented.                                       |
| CONS-22-c   | api_key null guard; missing api_key returns structured 401                              | ✓ SATISFIED | `SESSION_TOKEN_REQUIRED` 401 returned. `NullApiKeyGuardTest` (existing) covers behavior.                            |
| CONS-22-d   | JWT::$timestamp not mutated; Octane-safe timing                                         | ✓ SATISFIED | `SessionTokenService` uses manual HMAC verify + Carbon timing. No `JWT::$timestamp` matches.                       |
| CONS-22-e   | WidgetAuditEvent typed enum replaces string constants                                   | ✓ SATISFIED | `app/Enums/Widget/WidgetAuditEvent.php` (Init/Request/Rejected) consumed by `WidgetAudit::log()`.                  |
| CONS-22-f   | api_key_hash column with unique index; raw api_key not in storage or cache              | ✓ SATISFIED | Column + unique index present; **CR-01 closed** — zero raw-keyed cache entries remain.                              |
| CONS-22-g   | WIDGET_SESSION_DUAL_ACCEPT defaults to false; strict-mode cutover complete              | ✓ SATISFIED | Defaults false; **CR-02 closed** — `Tenant::saved` invalidates old api_key cache on every rotation path.            |

**Documentation follow-up (not a verification gap):** `.planning/REQUIREMENTS.md` still marks CONS-22-f and CONS-22-g as `DEFERRED`. The executor reported (commit `f96c4da`) that the mark-complete handler couldn't update the table due to a format mismatch — these need a hand-edit to flip to SATISFIED. Code is correct; docs are stale.

---

### Anti-Patterns Found

| File                                                  | Line | Pattern                                                                                              | Severity | Impact                                                                                                                                                 |
| ----------------------------------------------------- | ---- | ---------------------------------------------------------------------------------------------------- | -------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `database/migrations/2026_05_20_000001_add_api_key_hash_to_tenants_table.php` | 25   | Inlines `hash('sha256', $t->api_key.config('app.key'))` — could route through `Tenant::hashApiKey()` | ℹ️ INFO   | Recipe is byte-identical to the helper today; future refactor should route through the helper to eliminate latent drift risk. Out of plan scope (plan gate limited to `app/Http` + `app/Models`). |
| `database/seeders/DatabaseSeeder.php`                 | 72   | Same — inlines the literal recipe                                                                    | ℹ️ INFO   | Same drift risk; same out-of-scope justification.                                                                                                       |
| `app/Services/Widget/SessionTokenService.php`         | 33   | Inlines `hash('sha256', $tenant->api_key.$this->secret)` for JWT `sub` derivation                    | ℹ️ INFO   | Plan explicitly excluded SessionTokenService ("rewriting it would tangle into JWT verification"); `$this->secret = config('app.key')` by binding, so values match. Future refactor candidate. |

**Debt markers:** Zero `TODO`, `FIXME`, `XXX`, `TBD`, `HACK`, `PLACEHOLDER` in any of the 11 modified files.

**Eradicated from prior run:**

| Prior anti-pattern                                                                | Resolution                                                                                                          |
| --------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| ValidateWidgetDomain:40 raw-keyed `Cache::remember`                                | ✓ ELIMINATED — line 40 now uses hashed key via `Tenant::hashApiKey()` (commit `fd8cd5b`)                            |
| CheckUsageLimits:75 raw-keyed `Cache::remember`                                    | ✓ ELIMINATED — line 75 hashed (commit `fd8cd5b`)                                                                    |
| ChatController:366,373 raw-keyed `Cache::get/put`                                 | ✓ ELIMINATED — single `$cacheKey` variable, hashed, reused for both ops (commit `fd8cd5b`)                          |
| Tenant.php saved hook missing api_key cache invalidation                          | ✓ ELIMINATED — `wasChanged('api_key')` branch + `getOriginal()` slot eviction (commit `82ec7b5`)                    |
| RequireWidgetSessionToken:68 non-constant-time compare                            | ✓ ELIMINATED — `hash_equals((string) $tenant->api_key, (string) $bodyApiKey)` (commit `08d81fe`)                    |
| Tenant.php saving hook leaves stale hash on null api_key (WR-02)                   | ✓ ELIMINATED — `isDirty('api_key')` branch now nulls hash when api_key is nulled (commit `82ec7b5`)                |
| DatabaseSeeder.php missing strict_types                                            | ✓ ELIMINATED — `declare(strict_types=1)` on line 3 (commit `08d81fe`)                                              |
| TrustProxiesTest file_get_contents pseudo-tests                                    | ✓ ELIMINATED — rewritten to env-driven + `$request->ip()` assertion (commit `08d81fe`)                              |
| WidgetController:78 raw-key forget                                                 | ✓ ELIMINATED — Cache::forget line + Cache/Tenant imports all deleted; saved hook is canonical (commit `82ec7b5`)    |
| RequireWidgetSessionToken:26 misleading `session_dual_accept', true` fallback     | ✓ ELIMINATED — fallback now `false` (commit `08d81fe`)                                                              |

**Remaining warning from prior run (acknowledged, not blocking):**

| Prior warning                                                                              | Disposition                                                                                                                                                            |
| ------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| WR-03 migration created unique index before backfill (transactional half-apply risk)       | Out of gap-plan scope; explicitly deferred ("fixing a shipped migration in place is risky; if a future deploy fails, address with a NEW migration then"). Not a blocker. |

---

### Human Verification Required

None required. All 5 ROADMAP success criteria are programmatically verifiable and were verified by independent grep + behavioral tests + full suite execution.

---

### Gaps Summary

**No gaps.** Both prior BLOCKERs (CR-01 raw-keyed cache, CR-02 missing saved-hook invalidation) and all four absorbed WARNINGs (WR-01 timing side-channel, WR-04 seeder strict_types, WR-05 file_get_contents pseudo-tests, WR-07 misleading config fallback) are eradicated. WR-02 (stale hash on null api_key) was absorbed into Task 2 alongside CR-02 and also closed.

**Difference from prior run:** prior verification scored 3/5 (SC1, SC2, SC3 passing; SC4, SC5 failing). This run scores 5/5 — SC4 and SC5 both flipped to VERIFIED via the gap-closure plan's three task commits:

| Commit    | Closed                                                                              |
| --------- | ----------------------------------------------------------------------------------- |
| `fd8cd5b` | CR-01 (raw-keyed cache) + WR-06 (WidgetController forget renamed mid-flight) — SC5 |
| `82ec7b5` | CR-02 (missing saved-hook invalidation) + WR-02 (null api_key hash) + WidgetController duplicate forget deleted — SC4 |
| `08d81fe` | WR-01 (timing side-channel) + WR-04 (strict_types) + WR-05 (TrustProxies behavioral test) + WR-07 (config fallback) |

**Latent drift risk acknowledged (Info-level, not gaps):** the SHA-256+APP_KEY recipe still appears literally in `database/migrations/...add_api_key_hash_to_tenants_table.php:25`, `database/seeders/DatabaseSeeder.php:72`, and `app/Services/Widget/SessionTokenService.php:33`. The plan's grep gate explicitly scoped to `app/Http` + `app/Models` and explicitly excluded SessionTokenService. All four recipes are byte-identical today; the risk is future drift if anyone touches one without the others. Suggest a future hardening pass to route the migration + seeder + SessionTokenService through `Tenant::hashApiKey()`. Not a Phase 15 SC blocker.

**Documentation follow-ups (do not block PR ready):**
1. `.planning/REQUIREMENTS.md` rows for CONS-22-f + CONS-22-g need a hand-edit to flip from `DEFERRED` to `SATISFIED` (executor flagged the auto-handler couldn't parse the table format).
2. `.planning/STATE.md` `progress` counters are correct (`completed_plans: 2`, `percent: 11`) but the narrative `Status:` line still reads "Ready to execute" — minor stale wording, no behavioral consequence.

---

## Closure Assessment

Phase 15 is ready for `gh pr ready 30`. All 5 ROADMAP Success Criteria pass with independent evidence: zero raw-api_key cache writes remain in `app/` (CR-01), the `Tenant::saved` hook invalidates the previous api_key's cache slot via `wasChanged + getOriginal` (CR-02), constant-time `hash_equals` replaces the timing-leak `!==` compare (WR-01), the `session_dual_accept` middleware fallback now matches the canonical config default (WR-07), the `TrustProxies` test asserts behavioral `$request->ip()` resolution (WR-05), and three new RED-first regression test files (542 passing tests, 1 skipped) lock the closures against future regression. PHPStan baseline is `0 errors`, Pint is clean. The three remaining Info-level findings (recipe inlined in migration + seeder + SessionTokenService) are documented but explicitly out of plan scope and produce byte-identical hashes today — they are a future hardening candidate, not a gap. The auth-bypass window CR-02 described (~5 min stale cache for non-controller rotations) is closed end-to-end and proven by `TenantSavedHookRotationTest::test_rotated_old_api_key_no_longer_authenticates` returning 401 after a direct `$tenant->update()` rotation. Strict-mode cutover is safe to declare.

---

_Verified: 2026-05-20T13:30:00Z_
_Verifier: Claude (gsd-verifier) — re-verification after `15-02-PLAN` gap closure_
