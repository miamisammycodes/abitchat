# Phase 15: Widget Session Token Hardening - Context

**Gathered:** 2026-05-20
**Status:** Ready for planning

<domain>
## Phase Boundary

Production-harden the shipped PR #29 JWT widget-session system and complete the strict-mode cutover. Scope is fixed by ROADMAP.md success criteria and the seven CONS-22 items (a–g):

- **a** TrustProxies configured for proxy-aware client-IP resolution
- **b** `WidgetAudit::log()` failures wrapped so they never bubble into the widget response
- **c** `api_key` null guard in the middleware chain → structured 401
- **d** Octane race condition on the `SessionTokenService` singleton
- **e** Enum / value-object cleanup for JWT token claims + audit events
- **f** Indexed `api_key_hash` column (replace the O(active_tenants) in-PHP scan in `SessionTokenService::verify()`)
- **g** Flip `WIDGET_SESSION_DUAL_ACCEPT=false` (strict-mode cutover)

Touchpoints: `app/Services/Widget/SessionTokenService.php`, `app/Support/Widget/WidgetAudit.php`, `app/Http/Middleware/RequireWidgetSessionToken.php`, `app/Http/Middleware/ThrottleWidgetPerIp.php`, `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `config/widget.php`, a new migration for `api_key_hash`.

This is hardening of existing code — **no new widget capabilities**.

</domain>

<decisions>
## Implementation Decisions

### TrustProxies (CONS-22-a)
- **D-01:** Implement `trustProxies()` in `bootstrap/app.php` reading a new `TRUSTED_PROXIES` env var (CIDR list). **Default empty = trust no proxy.**
- **D-02:** Empty/trust-none is the *correct* production setting for the planned topology — **Laravel Forge single server (nginx → PHP-FPM over FastCGI) + Cloudflare DNS-only (grey cloud, NOT the Cloudflare proxy)**. There is no upstream HTTP proxy injecting `X-Forwarded-For`; nginx→FPM already hands PHP the real client IP via `REMOTE_ADDR`, so `$request->ip()` is correct without trusting XFF. Trusting XFF in this topology would only create an IP-spoofing vector that defeats DEC-12 IP-binding and the DEC-13 per-IP rate caps.
- **D-03:** The env mechanism is wired now so a *future* topology change needs config only, not code. See Deferred Ideas for the ops note.

### Strict-Mode Cutover (CONS-22-g)
- **D-04:** **No production is deployed yet** → no legacy embedded widgets, no merchant comms, no rollback risk. Do a **clean break**: change the `config/widget.php` `session_dual_accept` default to `false` in this phase (still env-overridable via `WIDGET_SESSION_DUAL_ACCEPT`). Legacy api_key-only requests return 401 with a clear error code.
- **D-05:** Ordering constraint (DEC-12) still holds within the phase: TrustProxies (a) lands before the cutover (g). With trust-none default this is satisfied trivially, but keep the task ordering explicit.

### api_key_hash Column & Pepper (CONS-22-f)
- **D-06:** Add a nullable, **indexed** `api_key_hash` column to `tenants`; `verify()` looks the tenant up by it instead of scanning all active tenants in PHP.
- **D-07:** **Keep `APP_KEY` as the pepper** for both the JWT `sub` claim and the new `api_key_hash` value (`sha256(api_key . APP_KEY)`) — consistent with the shipped `sub` derivation in `SessionTokenService::mint()`, fewest moving parts. A dedicated `WIDGET_TOKEN_PEPPER` is explicitly deferred (see Deferred).
- **D-08:** Column must be maintained wherever `api_key` is set/rotated (model boot hook or the api_key mutator) so it never drifts from `sub`. Backfill existing rows in the migration. (No prod data exists yet — backfill is trivial but must still be correct for dev/test/seeded tenants.)

### Audit-Log Failure Behavior (CONS-22-b)
- **D-09:** `WidgetAudit::log()` (and the `ipHash()` `RuntimeException` path when `APP_KEY` is empty) must be caught so a logging failure **never** bubbles into the widget response. On failure: **swallow + increment a monitoring failure metric/counter** for alerting. Do not silently discard with no signal.
- **D-10:** The specific metrics sink/counter facility is the planner's call — if no metrics facility exists, a lightweight counter (cache/Redis increment or a dedicated `Log::channel` alert line as the counter source) is acceptable; the requirement is "detectable in ops," not a specific vendor.

### Claude's Discretion
- **D-01/D-02/D-03 (TrustProxies)** and **D-07 (pepper choice)** were explicitly delegated ("you decide"); rationale captured above so the planner does not re-litigate.
- **CONS-22-c** (api_key null-guard placement in the `ValidateWidgetDomain` → `widget.session_token` → `widget.throttle_ip` chain and the exact 401 error-code string/envelope), **CONS-22-d** (Octane fix: `scoped` rebinding — mirroring the existing `RobotsTxtPolicy` scoped pattern in `AppServiceProvider` — vs. keeping the immutable-secret singleton and instead hardening the `JWT::$timestamp` static), and **CONS-22-e** (depth of enum/value-object modeling for claims + the `WidgetAudit::EVENT_*` consts) are left to the researcher/planner as pure implementation, per the user's earlier scoping choice. Constraint: all refactors MUST keep the PHPStan/Larastan baseline at zero (DEC-09) and use `forTenant`/`BelongsToTenant` for any tenant query (DEC-05).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Phase scope & requirements
- `.planning/ROADMAP.md` § "Phase 15: Widget Session Token Hardening" — goal + 5 success criteria
- `.planning/REQUIREMENTS.md` § "Widget Hardening (from CONS-22)" — CONS-22-a..g table
- `.planning/intel/constraints.md` § CONS-22 — deferred-followups source list

### Locked decisions
- `.planning/PROJECT.md` § DEC-12 (JWT HS256 architecture + TrustProxies-before-cutover constraint), § DEC-13 (per-IP rate limits), § DEC-09 (PHPStan baseline = zero), § DEC-05 (BelongsToTenant / NoRawTenantIdWhere)

### Original design spec (shipped system being hardened)
- `docs/superpowers/specs/2026-05-19-widget-session-tokens-design.md` — the PR #29 design; deferred-followups list that became CONS-22
- `docs/superpowers/plans/2026-05-19-widget-session-tokens.md` — the executed implementation plan (reference for how the shipped code is structured)

### Codebase map
- `.planning/codebase/ARCHITECTURE.md`, `.planning/codebase/CONCERNS.md` — widget security model + flagged hardening debt

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `App\Services\Widget\SessionTokenService` — `mint()`/`verify()`; `verify()` has an explicit `TODO(perf)` marking the exact O(active_tenants) scan that CONS-22-f replaces. `sub` = `hash('sha256', $tenant->api_key.$this->secret)`; the `api_key_hash` column must use the identical derivation.
- `App\Support\Widget\WidgetAudit` — static `log()` + `ipHash()`; `ipHash()` throws `RuntimeException` if `APP_KEY` is empty (the realistic failure path CONS-22-b must guard).
- `App\Providers\AppServiceProvider` — already uses `$this->app->scoped(RobotsTxtPolicy::class)` as a precedent pattern for the Octane-safe binding option (CONS-22-d); `SessionTokenService` is currently `$this->app->singleton(...)`.
- `bootstrap/app.php` — middleware aliases registered here; `trustProxies()` belongs in this same `withMiddleware` closure.
- `config/widget.php` — `session_dual_accept` default lives here (CONS-22-g flip target).

### Established Patterns
- DEC-05: tenant lookups go through `forTenant()` / `BelongsToTenant`; the new `api_key_hash` lookup must not introduce a raw `where('tenant_id', …)` (Larastan-enforced).
- JWT timestamp handling uses `JWT::$timestamp = Carbon::now()->timestamp` with a `finally { JWT::$timestamp = null; }` — relevant to the Octane static-state analysis (CONS-22-d).

### Integration Points
- New `api_key_hash` column → `tenants` table; maintenance hook wherever `api_key` is assigned/rotated.
- `RequireWidgetSessionToken` middleware chain → api_key null guard (CONS-22-c) and the dual-accept→strict switch (CONS-22-g).

</code_context>

<specifics>
## Specific Ideas

- Infra is concrete: **Laravel Forge** (single server, nginx + PHP-FPM/FastCGI) and **Cloudflare for DNS only — the Cloudflare proxy will NOT be enabled**. This is the basis for the trust-none TrustProxies default; downstream agents should treat it as the authoritative deployment assumption for Phase 15.

</specifics>

<deferred>
## Deferred Ideas

- **Dedicated `WIDGET_TOKEN_PEPPER`** — a stable secret separate from `APP_KEY` so APP_KEY rotation does not invalidate all live widget sessions or orphan the `api_key_hash` column. Not needed now (no prod, APP_KEY rotation is not an operational concern). Revisit when production APP_KEY rotation becomes real. Belongs in a future widget/ops hardening phase, not Phase 15.
- **Ops/deploy note (not a code task):** If the topology ever changes — a Forge load balancer / multiple app servers behind an HTTP LB, or Cloudflare flipped to the orange-cloud proxy — `TRUSTED_PROXIES` MUST be set to the LB's / Cloudflare's published CIDR ranges, otherwise client-IP resolution breaks. Document this in the deploy runbook; no Phase 15 code change.

</deferred>

---

*Phase: 15-widget-session-token-hardening*
*Context gathered: 2026-05-20*
