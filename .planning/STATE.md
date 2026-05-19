# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-05-20)

**Core value:** Enable any WordPress site owner to deploy an AI-powered chatbot — trained on their own content — that captures leads, answers visitor questions, and delivers measurable ROI, without technical expertise.
**Current focus:** v1.1 Backlog Completion — phase selection is user-driven (run `/gsd:plan-phase N` for any phase 14–22)

## Current Position

Phase: Phases 15 + 14 — context gathered, planning next (parallel)
Plan: None active (2 parallel plan-phase runs about to dispatch)
Status: 15-CONTEXT.md + 14-CONTEXT.md locked; ready for parallel planning
Last activity: 2026-05-20 — discuss-phase complete for Phase 15 (widget hardening) and Phase 14 (data encryption); shared api_key_hash contract established between them

Progress: [██░░░░░░░░] ~18% (Phases 1–13 complete; Phases 14–22 pending)

## Performance Metrics

**Velocity:**
- Total plans completed: 0 (v1.1 milestone not yet started)
- v1.0 baseline: PRs #3–#29 merged; 482 tests; 15 Playwright E2E cases

**By Phase:** No v1.1 plans complete yet.

## Accumulated Context

### Decisions

See PROJECT.md Key Decisions table for full log. Critical decisions affecting v1.1 work:

- **DEC-05**: BelongsToTenant + NoRawTenantIdWhere — all new tenant-scoped models must use the trait; PHPStan enforces at CI
- **DEC-09**: PHPStan baseline = zero — all new code must pass at level-max; no grandfathering
- **DEC-12**: Widget JWT tokens — TrustProxies must be configured BEFORE WIDGET_SESSION_DUAL_ACCEPT is flipped to false (Phase 15 prerequisite)
- **DEC-14**: pgvector is the vector store (not SQLite-vec per PRD)
- **DEC-15**: BTN is authoritative currency; PRD USD pricing is stale

### Phase Ordering Notes

- Phase 15 (Widget Hardening) has an internal ordering constraint: TrustProxies first → CONS-22 hardening → flip DUAL_ACCEPT last
- Phase 20 (Conversation Polish) must follow Phase 17 (Team Mgmt) for REQ-ccm-08 (conversation assignment)
- Phase 21 (Analytics) must follow Phase 20 for REQ-can-05 (quality metrics depend on REQ-ccm-07 ratings)
- All other phases are independently selectable

### Pending Todos

None yet.

### Blockers/Concerns

- **Widget strict-mode cutover (Phase 15)**: TrustProxies not yet configured — do not flip WIDGET_SESSION_DUAL_ACCEPT to false before Phase 15 completes
- **WP.org (Phase 18)**: Remaining work is process/admin (compliance review, asset submission, review board) — no code changes expected
- **DK Bank (Phase 19)**: 4 open questions with DK (UAT app, BoB submit, ref-no format, dual API key) — may require Task 0 verification before billing work
- **Widget allowed domains**: Tenants with no allowed_domains receive 403; onboarding flow needs to prompt domain config (related to Phase 15 or onboarding follow-up)

## Deferred Items

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| Crawler | Headless renderer for JS-heavy pages | Out of scope | PR #25 |
| Crawler | DNS-TXT domain ownership verification | Out of scope | PR #25 |
| Crawler | Per-tenant crawl cadence configuration | Out of scope | PR #25 |
| Crawler | Crawled content retention policy | Out of scope | PR #25 |

## Session Continuity

Last session: 2026-05-20
Stopped at: Phase 15 & Phase 14 context gathered; dispatching 2 parallel plan-phase runs. Cross-phase contract: Phase 14 depends on Phase 15's `api_key_hash` blind-index; execute 15→14.
Resume file: .planning/phases/15-widget-session-token-hardening/15-CONTEXT.md, .planning/phases/14-data-encryption-at-rest/14-CONTEXT.md
