---
phase: 15-widget-session-token-hardening
verified: 2026-05-20T12:00:00Z
status: gaps_found
score: 3/5 must-haves verified
overrides_applied: 0
gaps:
  - truth: "api_key_hash column exists with unique index; raw api_key not exposed in storage or cache (SC5)"
    status: failed
    reason: "CR-01: Three cache-writer call sites use 'tenant:api_key:{$rawApiKey}' as the Redis cache key. DB column and lookups correctly use api_key_hash, but every api_key-keyed cache hit exposes raw api_keys in Redis plaintext, directly defeating the blind-index confidentiality goal."
    artifacts:
      - path: "app/Http/Middleware/ValidateWidgetDomain.php"
        issue: "Line 40: Cache::remember(\"tenant:api_key:{$apiKey}\", ...) — raw api_key as cache key"
      - path: "app/Http/Middleware/CheckUsageLimits.php"
        issue: "Line 75: Cache::remember(\"tenant:api_key:{$apiKey}\", ...) — raw api_key as cache key"
      - path: "app/Http/Controllers/Api/V1/Widget/ChatController.php"
        issue: "Lines 366,373: Cache::get/put(\"tenant:api_key:{$apiKey}\", ...) — raw api_key as cache key"
    missing:
      - "Change all cache key patterns from 'tenant:api_key:{$rawApiKey}' to 'tenant:api_key_hash:{$hash}' where $hash = hash('sha256', $apiKey.config('app.key'))"
      - "Update WidgetController::regenerateApiKey() to forget by hash-keyed entry, not by raw-keyed entry"

  - truth: "WIDGET_SESSION_DUAL_ACCEPT defaults to false; strict mode is safe because api_key rotation immediately invalidates all cache paths (SC4)"
    status: failed
    reason: "CR-02: Tenant::booted() saved hook only invalidates 'tenant:{id}:with_plan'. The api_key-keyed cache entries (TTL ~300s) are NOT invalidated on rotation unless WidgetController::regenerateApiKey() runs. Any rotation via console, factory, job, or direct model save leaves the stale tenant (with old api_key) in cache for up to 5 minutes. RequireWidgetSessionToken line 68 compares $bodyApiKey !== $tenant->api_key — old api_key fetched from stale cache still matches, so old key continues to authenticate."
    artifacts:
      - path: "app/Models/Tenant.php"
        issue: "saved hook only calls Cache::forget('tenant:{$tenant->id}:with_plan'); no api_key cache invalidation on api_key rotation"
      - path: "app/Http/Controllers/Client/WidgetController.php"
        issue: "regenerateApiKey() at line 78 calls Cache::forget('tenant:api_key:{$oldKey}') — covers only the UI path; not a model-level invariant"
    missing:
      - "Add wasChanged('api_key') check to Tenant::booted() saved hook; on match, call Cache::forget('tenant:api_key_hash:{hash-of-new-key}') for new key and Cache::forget for old key if available"
      - "Once CR-01 is fixed (cache key uses hash), the forget call in the saved hook must use the hash-keyed pattern consistently"
---

# Phase 15: Widget Session Token Hardening — Verification Report

**Phase Goal:** The widget session token system is hardened to production-grade reliability and ready for strict-mode cutover, with TrustProxies correctly configured so IP-binding and rate limits work through all proxy layers.
**Verified:** 2026-05-20T12:00:00Z
**Status:** gaps_found
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| SC1 | TrustProxies configured; per-IP rate limits + IP-binding work through proxy layers | VERIFIED | `bootstrap/app.php`: `$middleware->trustProxies(at: ..., headers: HEADER_X_FORWARDED_FOR|HOST|PORT|PROTO)` wired in global middleware stack. Production-correct. |
| SC2 | WidgetAudit::log() calls wrapped in try/catch so logging failure never bubbles up | VERIFIED | `RequireWidgetSessionToken.php` lines 52-62 (Rejected path) and 74-79 (Approved path) both wrapped in try/catch. |
| SC3 | api_key null guard in middleware chain; missing api_key returns structured 401 not unhandled exception | VERIFIED | `RequireWidgetSessionToken.php` returns 401 JSON `{error: 'SESSION_TOKEN_REQUIRED'}` on null api_key. NullApiKeyGuardTest confirms behavior. |
| SC4 | WIDGET_SESSION_DUAL_ACCEPT defaults to false; strict mode safe | FAILED | Config defaults correctly to `false`. However CR-02 (no model-level cache invalidation on api_key rotation) creates a stale-cache auth window of ~5 minutes for non-controller rotations — strict mode is not safe to claim. |
| SC5 | api_key_hash column has DB index; O(1) lookup; raw api_key not in storage/cache | FAILED | Column + unique index exist. All 5 DB lookup sites use `where('api_key_hash', ...)`. But CR-01: 3 cache-write call sites use `"tenant:api_key:{$rawApiKey}"` as Redis key, exposing plaintext api_keys in Redis. |

**Score: 3/5 truths verified**

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Http/Middleware/RequireWidgetSessionToken.php` | JWT Bearer auth + audit try/catch + null guard | VERIFIED | All behaviors present and substantive |
| `app/Services/Widget/SessionTokenService.php` | JWT mint/verify; no JWT::$timestamp mutation; carbon timing | VERIFIED | Manual HMAC-SHA256 + hash_equals; JWT::$timestamp never touched |
| `bootstrap/app.php` | TrustProxies global middleware | VERIFIED | Wired with correct forwarded header flags |
| `config/widget.php` | session_dual_accept defaults false | VERIFIED | `env('WIDGET_SESSION_DUAL_ACCEPT', false)` |
| `database/migrations/2026_05_20_000001_add_api_key_hash_to_tenants_table.php` | api_key_hash column + unique index | VERIFIED (with caveat) | Column and unique index exist. WR-03: index created before backfill — transactional risk if backfill fails mid-migration |
| `app/Models/Tenant.php` | api_key_hash computed on creating/saving | PARTIAL | creating hook correct. saving hook: WR-02 — doesn't clear hash when api_key is set to null. saved hook: CR-02 — missing api_key cache invalidation |
| `app/Enums/Widget/WidgetAuditEvent.php` | Typed enum for audit events | VERIFIED | `Init`, `Request`, `Rejected` values present |
| `app/Support/Widget/WidgetAudit.php` | log() accepts WidgetAuditEvent enum | VERIFIED | Signature: `log(WidgetAuditEvent $event, Tenant $tenant, ...)` |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `RequireWidgetSessionToken` | `SessionTokenService::verify()` | constructor injection + `$this->tokenService->verify()` | VERIFIED | Real verification call, not bypassed |
| `SessionTokenService::verify()` | `Tenant` (DB) | `Tenant::where('api_key_hash', $expectedSub)` | VERIFIED | Hash-based lookup; no raw api_key in WHERE clause |
| `ValidateWidgetDomain` / `CheckUsageLimits` / `ChatController` | `Tenant` (cache) | `Cache::remember("tenant:api_key:{$apiKey}", ...)` | PARTIAL — CR-01 | DB query uses hash (correct); cache key uses raw api_key (broken) |
| `Tenant::booted()` saved hook | api_key cache invalidation | `Cache::forget(...)` | NOT_WIRED — CR-02 | Hook only forgets `tenant:{id}:with_plan`; api_key-keyed cache not invalidated on rotation |
| `TrustProxies` | Global middleware stack | `$middleware->trustProxies(...)` in `bootstrap/app.php` | VERIFIED | Wired in prepend position for all routes |

---

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|--------------|--------|--------------------|--------|
| `SessionTokenService::verify()` | `$tenant` | `Tenant::where('api_key_hash', ...)->first()` | Yes — real DB query against indexed column | FLOWING |
| `RequireWidgetSessionToken` | `$tenant` (from cache or DB) | `Cache::remember("tenant:api_key:{$apiKey}", ...)` wrapping `Tenant::where(...)` | Partially — DB query is real; cache key exposes raw api_key | HOLLOW (cache key leaks raw api_key) |
| `ValidateWidgetDomain` | `$tenant` | Same `Cache::remember("tenant:api_key:{$apiKey}", ...)` pattern | Same issue | HOLLOW (CR-01) |

---

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| config/widget.php dual_accept default | `grep -n 'session_dual_accept' /Users/sam/Dev/laravel/chatbot/config/widget.php` | `'session_dual_accept' => env('WIDGET_SESSION_DUAL_ACCEPT', false)` | PASS |
| TrustProxies wired in bootstrap | `grep -n 'trustProxies' /Users/sam/Dev/laravel/chatbot/bootstrap/app.php` | Found with HEADER_X_FORWARDED_FOR\|HOST\|PORT\|PROTO | PASS |
| JWT::$timestamp never mutated | `grep -rn 'JWT::$timestamp' /Users/sam/Dev/laravel/chatbot/app/` | No matches | PASS |
| Raw api_key in cache keys | `grep -rn 'tenant:api_key:' /Users/sam/Dev/laravel/chatbot/app/` | Found in ValidateWidgetDomain:40, CheckUsageLimits:75, ChatController:366,373 | FAIL (CR-01) |
| Tenant saved hook invalidates api_key cache | `grep -n 'Cache::forget' /Users/sam/Dev/laravel/chatbot/app/Models/Tenant.php` | Only `tenant:{$tenant->id}:with_plan` found | FAIL (CR-02) |
| Audit try/catch on both paths | `grep -n 'try' /Users/sam/Dev/laravel/chatbot/app/Http/Middleware/RequireWidgetSessionToken.php` | Lines 52 and 74 — both paths guarded | PASS |

---

### Requirements Coverage

| Requirement | Description | Status | Evidence |
|-------------|-------------|--------|----------|
| CONS-22-a | TrustProxies correctly configured so per-IP rate limits and IP-binding work through all proxy layers | SATISFIED | `bootstrap/app.php` trustProxies wired with correct headers |
| CONS-22-b | WidgetAudit::log() wrapped in try/catch; logging failure never propagates | SATISFIED | Both code paths in RequireWidgetSessionToken guarded |
| CONS-22-c | api_key null guard; missing api_key returns structured 401 | SATISFIED | Null path returns `{'error':'SESSION_TOKEN_REQUIRED'}` with 401 |
| CONS-22-d | JWT::$timestamp not mutated; Octane-safe timing via Carbon; manual HMAC-SHA256 + hash_equals | SATISFIED | SessionTokenService uses manual signature verification; JWT::$timestamp never touched |
| CONS-22-e | WidgetAuditEvent typed enum replaces string constants | SATISFIED | `app/Enums/Widget/WidgetAuditEvent.php` with Init/Request/Rejected |
| CONS-22-f | api_key_hash column with unique index on tenants table | PARTIALLY SATISFIED | Column + index exist, all DB lookups correct. BLOCKED by CR-01: cache keys still expose raw api_key, undermining the blind-index confidentiality goal |
| CONS-22-g | WIDGET_SESSION_DUAL_ACCEPT defaults to false; strict-mode cutover complete | PARTIALLY SATISFIED | Config defaults false. BLOCKED by CR-02: stale-cache auth-bypass window (~5 min) for non-controller api_key rotations means strict mode cannot be declared safe |

---

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Http/Middleware/ValidateWidgetDomain.php` | 40 | `Cache::remember("tenant:api_key:{$apiKey}", ...)` — raw api_key as Redis cache key | BLOCKER (CR-01) | Defeats blind-index confidentiality; raw api_keys persist in Redis for up to 5 minutes |
| `app/Http/Middleware/CheckUsageLimits.php` | 75 | Same `"tenant:api_key:{$rawApiKey}"` cache key pattern | BLOCKER (CR-01) | Same exposure — three independent write sites |
| `app/Http/Controllers/Api/V1/Widget/ChatController.php` | 366, 373 | `Cache::get/put("tenant:api_key:{$apiKey}", ...)` | BLOCKER (CR-01) | Third site; also means regenerateApiKey's `Cache::forget` uses this key — must be migrated together |
| `app/Models/Tenant.php` | saved hook | `Cache::forget("tenant:{$tenant->id}:with_plan")` only — no api_key cache invalidation | BLOCKER (CR-02) | Any non-controller api_key rotation leaves stale cache; stale tenant authenticates old api_key for ~5 min |
| `app/Http/Middleware/RequireWidgetSessionToken.php` | 68 | `$bodyApiKey !== $tenant->api_key` — non-constant-time string comparison | WARNING (WR-01) | Timing side-channel for api_key comparison; use `hash_equals()` |
| `app/Models/Tenant.php` | saving hook | Does not clear `api_key_hash` when `api_key` is set to null | WARNING (WR-02) | Stale hash on nulled api_key; silent hash drift |
| `database/migrations/2026_05_20_000001_add_api_key_hash_to_tenants_table.php` | — | Unique index created before bulk backfill runs | WARNING (WR-03) | If backfill fails mid-run, migration is half-applied with orphaned index |
| `database/seeders/DatabaseSeeder.php` | 1 | Missing `declare(strict_types=1);` | WARNING (WR-04) | Phase regression — CLAUDE.md mandates strict_types in all PHP files |
| `tests/Feature/Widget/TrustProxiesTest.php` | — | Tests 1+3 assert source text (`file_get_contents`); Test 2 asserts `assertOk()` with no IP assertion | WARNING (WR-05) | TrustProxies tests don't verify that `$request->ip()` resolves to the forwarded IP; behavior is unconfirmed by the test suite |
| `app/Http/Controllers/Client/WidgetController.php` | 78 | `Cache::forget("tenant:api_key:{$oldKey}")` — raw api_key pattern (part of CR-01) | WARNING (WR-06) | Must be migrated to hash-keyed forget when CR-01 is fixed |
| `config/widget.php` + `RequireWidgetSessionToken.php` | — | Config file returns `false`; middleware has `config('widget.session_dual_accept', true)` fallback | WARNING (WR-07) | Misleading — `true` fallback in middleware is wrong if config is ever missing; should match config default of `false` |

**Debt markers:** Zero `TODO`, `FIXME`, `XXX`, or `TBD` markers found in phase-modified files. No debt-marker blockers.

---

### Human Verification Required

None required. All SC checks are programmatically verifiable.

---

### Gaps Summary

Two production-exploitable gaps block the phase goal despite correct implementation of 5 of 7 CONS-22 items.

**CR-01 — Raw api_key in Redis cache keys (blocks SC5)**

The phase's central security premise is that api_keys are never stored outside the hashed column. This holds true for the database layer — all 5 DB lookup sites correctly use `where('api_key_hash', hash(...))`. But three independent cache-writer call sites (`ValidateWidgetDomain:40`, `CheckUsageLimits:75`, `ChatController:366,373`) use `"tenant:api_key:{$rawApiKey}"` as the Redis key, meaning every active api_key appears in Redis in plaintext for up to 5 minutes. A Redis dump, keyspace notification listener, or Redis SCAN command reveals all active api_keys. The blind-index goal is not achieved.

Fix: rename cache key pattern to `"tenant:api_key_hash:{$hash}"` (where hash = `hash('sha256', $apiKey . config('app.key'))`), update all read, write, and forget call sites consistently. The `WidgetController::regenerateApiKey` forget call must also be migrated.

**CR-02 — Missing cache invalidation in Tenant saved hook (blocks SC4)**

`WIDGET_SESSION_DUAL_ACCEPT` correctly defaults to `false`. However the security claim "strict mode is safe" requires that api_key rotation immediately makes the old key unusable. Currently: `Tenant::booted()` `saved` hook invalidates only `"tenant:{$tenant->id}:with_plan"`. The api_key-keyed cache entries (TTL 300s) are only invalidated in `WidgetController::regenerateApiKey` — which covers the merchant UI path only. Any rotation via `php artisan tinker`, factories, queue jobs, or admin console leaves the stale tenant (with old `api_key` value) in cache for up to 5 minutes. `RequireWidgetSessionToken` line 68 compares `$bodyApiKey !== $tenant->api_key`; because both sides use the old value, authentication succeeds. The auth-bypass window is bounded (~5 min) but real.

Fix: add `wasChanged('api_key')` branch in `Tenant::booted()` `saved` hook to invalidate the api_key-keyed cache entry. Once CR-01 is fixed, use the hash-keyed pattern in this forget call.

**Relationship between CR-01 and CR-02:** These are independent gaps but the fix set must be coordinated — the cache key rename (CR-01 fix) must be done atomically with the forget-key update in both `WidgetController::regenerateApiKey` (WR-06) and the new `saved` hook branch (CR-02 fix). Fixing only one leaves the other inconsistent.

---

_Verified: 2026-05-20T12:00:00Z_
_Verifier: Claude (gsd-verifier)_
