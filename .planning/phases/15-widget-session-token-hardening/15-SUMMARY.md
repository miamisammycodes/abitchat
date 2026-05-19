---
phase: 15-widget-session-token-hardening
plan: 01
subsystem: api
tags: [jwt, widget, security, tenant, hash, redis, middleware]

# Dependency graph
requires:
  - phase: 29-widget-session-tokens (PR #29)
    provides: JWT widget session system that this phase hardens
provides:
  - api_key_hash indexed column on tenants table (Phase 14 dependency for encryption at rest)
  - Octane-safe JWT verify() with genuine static-mutation elimination
  - TrustProxies env-config with trust-none default
  - WidgetAudit try/catch guard on all audit log paths
  - WidgetAuditEvent enum replacing EVENT_* string constants
  - WIDGET_SESSION_DUAL_ACCEPT=false strict mode cutover
affects: [14-data-encryption-at-rest, widget, security, tenants]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Manual HMAC-SHA256 JWT signature verification bypassing library's static state
    - api_key_hash blind index pattern for O(1) tenant lookup without raw api_key exposure
    - WidgetAuditEvent string-backed enum replacing raw string constants for audit events
    - WithoutModelEvents seeders must explicitly compute derived columns (api_key_hash)

key-files:
  created:
    - database/migrations/2026_05_20_000001_add_api_key_hash_to_tenants_table.php
    - app/Enums/Widget/WidgetAuditEvent.php
    - tests/Unit/Models/TenantApiKeyHashTest.php
    - tests/Feature/Widget/ApiKeyHashLookupTest.php
    - tests/Feature/Widget/TrustProxiesTest.php
    - tests/Feature/Widget/WidgetAuditGuardTest.php
    - tests/Feature/Widget/NullApiKeyGuardTest.php
    - tests/Unit/Services/Widget/SessionTokenOctaneSafetyTest.php
    - tests/Unit/Enums/WidgetAuditEventTest.php
    - tests/Feature/Widget/StrictModeSystemTest.php
  modified:
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

key-decisions:
  - "Use manual HS256 HMAC verification in SessionTokenService::verify() to eliminate JWT::$timestamp mutation entirely (not just narrow window) — satisfies CONS-22-d must_haves.truths #7"
  - "APP_KEY used as pepper for api_key_hash (sha256(api_key . APP_KEY)) — consistent with existing JWT sub derivation"
  - "TrustProxies wired with trust-none default (empty TRUSTED_PROXIES env) for Forge single-server + Cloudflare DNS-only topology"
  - "Seeder explicitly sets api_key_hash because WithoutModelEvents disables creating hook"

patterns-established:
  - "Blind index pattern: api_key_hash = sha256(api_key . APP_KEY) — stored indexed, api_key not used for lookups"
  - "Audit failure guard: try/catch all WidgetAudit::log() calls; increment widget_audit_failures cache counter on failure"
  - "Manual JWT verify: split token, hash_hmac for sig check, Carbon::now()->timestamp for timing — no JWT::$timestamp"

requirements-completed:
  - CONS-22-a
  - CONS-22-b
  - CONS-22-c
  - CONS-22-d
  - CONS-22-e
  - CONS-22-f
  - CONS-22-g

# Metrics
duration: 14min
completed: 2026-05-20
---

# Phase 15 Plan 01: Widget Session Token Hardening Summary

**api_key_hash blind index + Octane-safe JWT verify + TrustProxies + WidgetAuditEvent enum + strict-mode cutover across all 7 CONS-22 items**

## Performance

- **Duration:** 14 min
- **Started:** 2026-05-20T06:22:34Z
- **Completed:** 2026-05-20T06:36:56Z
- **Tasks:** 4 executable tasks (+ Task 0 verification)
- **Files modified:** 20

## Accomplishments

- api_key_hash column with unique index on tenants; all 5 api_key lookup sites (ValidateWidgetDomain, CheckUsageLimits, ChatController, LeadController, SessionTokenService::verify) migrated to indexed hash — O(1) vs O(n) scan (CONS-22-f, SC5)
- SessionTokenService::verify() rewritten without JWT::$timestamp mutation — manual HS256 + Carbon timing; genuinely Octane-safe (CONS-22-d)
- TrustProxies wired in bootstrap/app.php reading TRUSTED_PROXIES env; trust-none default for Forge/Cloudflare-DNS-only topology (CONS-22-a, SC1)
- All WidgetAudit::log() call sites wrapped in try/catch; failures increment widget_audit_failures cache counter (CONS-22-b, SC2)
- WidgetAuditEvent string-backed enum replaces EVENT_INIT/EVENT_REQUEST/EVENT_REJECTED string constants (CONS-22-e)
- WIDGET_SESSION_DUAL_ACCEPT default flipped false; legacy api_key-only requests return 401 SESSION_TOKEN_REQUIRED (CONS-22-g, SC4)
- 537 tests passing (55 new tests added across 10 new test files); 0 PHPStan errors

## Task Commits

Each task was committed atomically:

1. **Task 1: api_key_hash column + all lookup migration (CONS-22-f)** — `59b29c7` (feat)
2. **Task 2: TrustProxies + audit guard + null api_key (CONS-22-a,b,c)** — `45152c2` (feat)
3. **Task 3: JWT::$timestamp elimination + WidgetAuditEvent enum (CONS-22-d,e)** — `e674f9d` (feat)
4. **Task 4: Strict mode cutover WIDGET_SESSION_DUAL_ACCEPT=false (CONS-22-g)** — `f7856fa` (feat)

## Files Created/Modified

- `database/migrations/2026_05_20_000001_add_api_key_hash_to_tenants_table.php` — Adds nullable unique api_key_hash column + backfill migration
- `app/Models/Tenant.php` — PHPDoc @property, api_key_hash in fillable, creating hook (hash on create), saving hook (hash on rotation)
- `app/Services/Widget/SessionTokenService.php` — Manual HS256 verify; no JWT::$timestamp; Carbon timing; indexed api_key_hash lookup
- `app/Enums/Widget/WidgetAuditEvent.php` — New string-backed enum (Init/Request/Rejected)
- `app/Support/Widget/WidgetAudit.php` — log() signature now accepts WidgetAuditEvent; EVENT_* constants removed
- `app/Http/Middleware/RequireWidgetSessionToken.php` — Cache import; try/catch around both audit log paths; WidgetAuditEvent enum
- `app/Http/Middleware/ValidateWidgetDomain.php` — api_key_hash lookup in cache miss filler
- `app/Http/Middleware/CheckUsageLimits.php` — api_key_hash lookup in resolveTenant()
- `app/Http/Controllers/Api/V1/Widget/ChatController.php` — null guard in findTenantByApiKey(); api_key_hash lookup; WidgetAuditEvent::Init
- `app/Http/Controllers/Api/V1/Widget/LeadController.php` — api_key_hash lookup
- `bootstrap/app.php` — trustProxies() with TRUSTED_PROXIES env (trust-none default)
- `config/widget.php` — session_dual_accept default: true → false
- `database/seeders/DatabaseSeeder.php` — Explicit api_key_hash for WithoutModelEvents seeder

## Decisions Made

- Manual HMAC-SHA256 verification (no JWT::$timestamp) was chosen over the plan's "PHP_INT_MAX narrowing" approach, resolving the must_haves.truths #7 contradiction. The plan's body described a narrowed mutation window; the must_haves invariant required zero mutation. Verification: `JWT::$timestamp` asserted null before, during, and after every verify() call.
- Seeder deviation: DatabaseSeeder uses `WithoutModelEvents` which disables the creating hook. Added explicit `api_key_hash` computation in the seeder to keep the derived column in sync (Rule 2 — correctness required).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] AuditLogTest referenced removed WidgetAudit::EVENT_REJECTED constant**
- **Found during:** Task 3 (WidgetAuditEvent enum implementation)
- **Issue:** `tests/Feature/Widget/AuditLogTest.php` referenced `WidgetAudit::EVENT_REJECTED` which was removed when converting to enum
- **Fix:** Updated AuditLogTest to use `WidgetAuditEvent::Rejected->value` (and all three EVENT_* → enum values)
- **Files modified:** tests/Feature/Widget/AuditLogTest.php
- **Verification:** AuditLogTest passes; full suite green
- **Committed in:** e674f9d (Task 3 commit)

**2. [Rule 1 - Bug] SessionTokenServiceTest assertion matched old BeforeValidException message**
- **Found during:** Task 3 (verify() rewrite eliminated BeforeValidException dependency)
- **Issue:** `test_verify_rejects_not_yet_valid_token` expected `/Cannot handle token/` (firebase/php-jwt message); new impl returns `'Token not yet valid (iat in future)'`
- **Fix:** Updated assertion to `/not yet valid/i`
- **Files modified:** tests/Unit/Services/Widget/SessionTokenServiceTest.php
- **Verification:** Test passes with new message pattern; behavior identical
- **Committed in:** e674f9d (Task 3 commit)

**3. [Rule 2 - Missing Critical] Seeder bypassed api_key_hash via WithoutModelEvents**
- **Found during:** Task 4 overall verification (migrate:fresh --seed showed 1 tenant missing hash)
- **Issue:** DatabaseSeeder uses `WithoutModelEvents` trait which disables the `creating` hook; seeder-created tenant had null api_key_hash
- **Fix:** Added explicit `api_key_hash = hash('sha256', $apiKey . config('app.key'))` in DatabaseSeeder
- **Files modified:** database/seeders/DatabaseSeeder.php
- **Verification:** `migrate:fresh --seed` then tinker check shows 0 tenants missing hash
- **Committed in:** f7856fa (Task 4 commit)

**4. [Rule 1 - Bug] Task 3 plan body contradicted must_haves.truths #7**
- **Found during:** Planning review before Task 3 (advisor flagged contradiction)
- **Issue:** Plan body proposed PHP_INT_MAX approach (still mutates JWT::$timestamp); must_haves.truths #7 requires zero static mutation
- **Fix:** Implemented genuine elimination via manual HMAC-SHA256 + Carbon timing, bypassing JWT::decode() entirely
- **Files modified:** app/Services/Widget/SessionTokenService.php
- **Verification:** JWT::$timestamp asserted null throughout verify(); Carbon travel tests confirm exp/nbf checks work
- **Committed in:** e674f9d (Task 3 commit)

---

**Total deviations:** 4 auto-fixed (2 Rule 1 bugs, 1 Rule 2 missing critical, 1 Rule 1 plan-body contradiction)
**Impact on plan:** All auto-fixes necessary for correctness or to resolve plan contradictions. No scope creep.

## Issues Encountered

None beyond the deviations documented above.

## User Setup Required

None — no external service configuration required. All changes are code-only (migration, model, middleware, config).

## Next Phase Readiness

- Phase 14 (Data Encryption at Rest) is now unblocked: api_key_hash column exists with unique index; Phase 14 can encrypt the raw api_key column without breaking lookups
- All 5 ROADMAP SC criteria satisfied (SC1–SC5)
- Widget strict mode is default-on in production; WIDGET_SESSION_DUAL_ACCEPT=true env override available if rollback needed
- TrustProxies configured; if topology changes to load balancer / Cloudflare orange-cloud, set TRUSTED_PROXIES in env (no code change required)

## Self-Check: PASSED

- Migration file: FOUND at database/migrations/2026_05_20_000001_add_api_key_hash_to_tenants_table.php
- WidgetAuditEvent enum: FOUND at app/Enums/Widget/WidgetAuditEvent.php
- 10 new test files: ALL FOUND
- Task 1 commit 59b29c7: VERIFIED
- Task 2 commit 45152c2: VERIFIED
- Task 3 commit e674f9d: VERIFIED
- Task 4 commit f7856fa: VERIFIED
- Full suite: 1 skipped, 537 passed (1298 assertions)
- PHPStan: 0 errors
- Zero where('api_key') lookups remain in app/
- STRICT=OK (config default is false)

---
*Phase: 15-widget-session-token-hardening*
*Completed: 2026-05-20*
