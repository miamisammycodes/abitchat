# Phase 15: Widget Session Token Hardening - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-20
**Phase:** 15-widget-session-token-hardening
**Areas discussed:** TrustProxies trust scope, Strict-mode cutover strategy, api_key_hash & APP_KEY rotation, Audit-log failure behavior

---

## TrustProxies trust scope (CONS-22-a)

| Option | Description | Selected |
|--------|-------------|----------|
| Env-configurable `TRUSTED_PROXIES`, default empty | `trustProxies()` reads CIDR env; trust-none default; value set at deploy | ✓ |
| Trust all (`*`) | Always read X-Forwarded-For; simplest; spoofable if directly reachable | |
| Hardcoded proxy CIDRs | Bake CIDRs in middleware; needs code deploy to retarget | |

**User's choice:** "you decide" + key infra context: "we will use Forge and might use Cloudflare for domain but we won't [use] the cloudflare proxy."
**Notes:** User asked for two explanations (what a proxy is / how it affects the system; tenant-level vs system-level). Established TrustProxies is system/infrastructure-level (bootstrap/app.php, applies to all tenants — not a tenant or widget setting). Planned topology = Forge single server (nginx → PHP-FPM/FastCGI) + Cloudflare DNS-only (no proxy) → no upstream XFF, so trust-none is the *correct* prod value, not just a placeholder. Env mechanism wired for future LB/proxy changes (ops note in CONTEXT Deferred).

---

## Strict-mode cutover strategy (CONS-22-g)

| Option | Description | Selected |
|--------|-------------|----------|
| Land a–f now, flip g separately (gated deploy) | Keep default true; flip via env later after verifying no legacy traffic | |
| Flip config default this phase (clean break) | Change config/widget.php default to false in Phase 15 | ✓ |
| Flip this phase + kill-switch + alerting | Flip with fast-rollback env + legacy-401 alerts | |

**User's choice:** "there is no prod deployed for now"
**Notes:** No production → no legacy embedded widgets, no merchant comms, no rollback risk. The gated/staged option existed only to protect live traffic that doesn't exist, so the clean break is appropriate. Ordering constraint (TrustProxies before cutover, DEC-12) still recorded explicitly.

---

## api_key_hash & APP_KEY rotation (CONS-22-f)

| Option | Description | Selected |
|--------|-------------|----------|
| Dedicated stable `WIDGET_TOKEN_PEPPER` | Separate secret; APP_KEY rotation no longer invalidates sessions/orphans column | |
| Keep APP_KEY as pepper | Consistent with shipped `sub` derivation; rotation invalidates all (rare); fewest moving parts | ✓ |
| You decide | Defer to researcher/planner | |

**User's choice:** "You decide"
**Notes:** Claude discretion. No prod (no live sessions to preserve, no real-data backfill, APP_KEY rotation not an operational concern) → keep APP_KEY as pepper for both `sub` and the new indexed `api_key_hash` column. Dedicated pepper recorded as Deferred for when prod APP_KEY rotation becomes real.

---

## Audit-log failure behavior (CONS-22-b)

| Option | Description | Selected |
|--------|-------------|----------|
| Swallow + fallback breadcrumb (default log channel) | Catch; one-line warning to default channel | |
| Swallow silently | Catch + fully discard; invisible audit gap | |
| Swallow + monitoring metric/counter | Catch + increment failure counter for alerting | ✓ |

**User's choice:** "Swallow + monitoring metric"
**Notes:** `WidgetAudit::log()` / `ipHash()` failures never bubble into the widget response; failure increments a monitoring counter for alerting. Exact metrics sink left to planner (lightweight counter acceptable if no metrics facility exists).

---

## Claude's Discretion

- TrustProxies implementation (D-01/02/03) — delegated "you decide"; rationale locked in CONTEXT.md so planner does not re-litigate.
- api_key_hash pepper choice (D-07) — delegated "you decide".
- CONS-22-c (api_key null-guard placement + 401 envelope), CONS-22-d (Octane fix: scoped vs singleton+static-hardening), CONS-22-e (enum/value-object depth) — left to researcher/planner as pure implementation, per the user's gray-area selection. Must keep PHPStan baseline zero (DEC-09) and use forTenant/BelongsToTenant (DEC-05).

## Deferred Ideas

- Dedicated `WIDGET_TOKEN_PEPPER` — separate stable secret so APP_KEY rotation doesn't invalidate live widget sessions / orphan `api_key_hash`. Future widget/ops hardening phase; not Phase 15.
- Ops/deploy runbook note (not code): if topology changes to an HTTP load balancer / multi-server Forge / Cloudflare orange-cloud proxy, `TRUSTED_PROXIES` MUST be set to the LB's/Cloudflare's CIDRs.
