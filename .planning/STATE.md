---
gsd_state_version: 1.0
milestone: v1.1
milestone_name: Backlog Completion
status: completed
stopped_at: Phase 16.1 context gathered
last_updated: "2026-05-20T10:17:46.970Z"
last_activity: 2026-05-20 -- Phase 16.1 inserted (Role Foundation)
progress:
  total_phases: 10
  completed_phases: 1
  total_plans: 5
  completed_plans: 2
  percent: 10
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-05-20)

**Core value:** Enable any WordPress site owner to deploy an AI-powered chatbot — trained on their own content — that captures leads, answers visitor questions, and delivers measurable ROI, without technical expertise.
**Current focus:** Phase 15 — widget-session-token-hardening

## Current Position

Phase: 15 (widget-session-token-hardening) — MERGED to main (85baf40)
Plan: 2 of 2 shipped (wave 1 + wave 2 gap closure)
Status: Phase 15 complete. Phase 16.1 (Role Foundation) inserted as urgent slice ahead of Phase 17 to unblock isOwner() guard on UpdateWebsiteIndexingRequest. Available next: `/gsd:plan-phase 16.1` or `/gsd:execute-phase 14`.
Last activity: 2026-05-20 -- Phase 16.1 inserted (Role Foundation)

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

### Roadmap Evolution

- 2026-05-20 — Phase 16.1 (Role Foundation) inserted after Phase 16 (URGENT). Splits Phase 17 (Team Management) into a foundation slice (role taxonomy + Gate/Policy enforcement + seeder/UI gates) and the full team layer (invites, SSO, ownership transfer, conversation assignment). Triggered by isOwner() 403 on UpdateWebsiteIndexingRequest exposing the half-baked role system already partially scaffolded in `users.role`.
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
| Phase 15 P02 | 35 | 3 tasks | 11 files |

## Session Continuity

Last session: 2026-05-20T10:17:46.963Z
Stopped at: Phase 16.1 context gathered
Resume file: .planning/phases/16.1-role-foundation/16.1-CONTEXT.md
