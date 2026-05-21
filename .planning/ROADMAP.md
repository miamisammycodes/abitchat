# Roadmap: AI-Powered WordPress Chatbot SaaS

## Milestones

- ✅ **v1.0 Shipped** - Phases 1–13 (complete as of 2026-05-20; PRs #3–#29 merged)
- 🚧 **v1.1 Backlog Completion** - Phases 14–22 (in progress — user-driven phase selection)

---

## Phases

<details>
<summary>✅ v1.0 Shipped (Phases 1–13) — COMPLETE as of 2026-05-20</summary>

### Phase 1: Core Foundation

**Goal**: Laravel 13+, Vue 3 + Inertia, Tailwind v4, MySQL, pgvector, Redis, Spatie multi-tenancy
**Plans**: Complete

### Phase 2: Widget & Chat Interface

**Goal**: Embeddable chatbot.js, REST API, real-time streaming
**Plans**: Complete

### Phase 3: AI Integration

**Goal**: Prism abstraction, Ollama (dev), Groq (prod), token tracking
**Plans**: Complete

### Phase 4: Knowledge Base

**Goal**: File upload, URL import, chunking, pgvector embeddings, RAG
**Plans**: Complete

### Phase 5: Lead Capture

**Goal**: Lead form, scoring (LeadScoring::score), dashboard, export, notifications
**Plans**: Complete

### Phase 6: Admin Dashboard

**Goal**: Tenant management, analytics, billing overview
**Plans**: Complete

### Phase 7: Billing & Payments

**Goal**: Stripe Cashier foundation, Transaction model, plan activation
**Plans**: Complete

### Phase 8: Client Dashboard

**Goal**: Conversations, leads, knowledge base, bot config, analytics pages
**Plans**: Complete

### Phase 9: Admin Dashboard Extensions (partial)

**Goal**: M9.1/M9.2/M9.4 complete; M9.3/M9.5/M9.6/M9.7 carried forward to Phase 16
**Plans**: Partial

### Phase 10: Security Hardening

**Goal**: All critical + high audit findings resolved; medium backlog complete (PRs #3–#17)
**Plans**: Complete

### Phase 10.5: Architecture Deepening

**Goal**: BelongsToTenant trait, NoRawTenantIdWhere Larastan rule, PHPStan baseline = 0 (PRs #18–#23)
**Plans**: Complete

### Phase 11: DK Bank QR

**Goal**: DK Bank QR payment flow behind DK_BANK_ENABLED killswitch (PR #24)
**Plans**: Complete

### Phase 12: Registration Wizard + Site Scraping

**Goal**: 3-step wizard, sitemap-first crawler, daily diff-only refresh (PR #25)
**Plans**: Complete

### Phase 12.5: Crawl Polling + DK Parser Fixes

**Goal**: Crawl status polling UI, DK response shape fix, 405 fix (PRs #26–#28)
**Plans**: Complete

### Phase 12.6: WP.org Submission

**Goal**: WordPress plugin WP.org submission (~85% complete, process/admin work)
**Plans**: In progress — carried forward to Phase 18

### Phase 13: Widget Session Tokens + Rate Limiting

**Goal**: JWT HS256 session tokens (IP+origin binding), per-IP throttling, audit logging (PR #29)
**Plans**: Complete

</details>

---

### 🚧 v1.1 Backlog Completion

**Milestone Goal:** Resolve all remaining PARTIAL and PENDING PRD requirements across security, admin capabilities, team management, WP.org distribution, billing, conversation/lead UX, analytics, and compliance.

**Note:** All phases in this milestone are independently selectable. Run `/gsd:plan-phase N` for whichever phase is the priority. No single phase is locked as "active focus."

---

## Phase Details

### Phase 14: Data Encryption at Rest

**Goal**: Sensitive data fields are encrypted at rest, so a database compromise does not expose API keys, tokens, or PII in plaintext.
**Depends on**: Phase 13 (baseline — no prior encryption layer)
**Requirements**: REQ-sec-08
**Success Criteria** (what must be TRUE):

  1. API keys stored in the database are encrypted at rest; reading the raw column returns ciphertext
  2. Widget session-related tokens and any designated PII fields are encrypted before persist
  3. Application reads and writes the fields transparently — existing functionality unchanged
  4. A migration exists that encrypts all existing rows on deploy; rollback path documented

**Plans**: 3 plans in 2 waves
Plans:

- [ ] 14-01-PLAN.md — Lead PII encryption (leads.email, phone, name → encrypted cast + migration + backfill)
- [ ] 14-02-PLAN.md — Message content encryption (messages.content → encrypted cast + content_hash nulled + D-06 acceptance)
- [x] 14-03-PLAN.md — tenants.api_key encryption (Phase 15 precondition gate + unique index drop + widen column + backfill) (completed 2026-05-20)

### Phase 15: Widget Session Token Hardening

**Goal**: The widget session token system is hardened to production-grade reliability and ready for strict-mode cutover, with TrustProxies correctly configured so IP-binding and rate limits work through all proxy layers.
**Depends on**: Phase 13 (JWT infrastructure shipped in PR #29)
**Requirements**: CONS-22-a, CONS-22-b, CONS-22-c, CONS-22-d, CONS-22-e, CONS-22-f, CONS-22-g
**Success Criteria** (what must be TRUE):

  1. TrustProxies middleware is configured; per-IP rate limits and IP-binding behave correctly when requests arrive through a load balancer or reverse proxy
  2. `WidgetAudit::log()` calls are wrapped in try/catch so a logging failure never bubbles up to the widget response
  3. The `api_key` null guard is in place in the middleware chain; a missing api_key returns a structured 401 rather than an unhandled exception
  4. `WIDGET_SESSION_DUAL_ACCEPT` is flipped to `false` (strict mode cutover complete); legacy api_key-only requests return 401 with a clear error code
  5. `api_key_hash` column has a database index; lookup performance does not degrade under load

**Plans**: 2 plans (1 + 1 gap-closure)
Plans:

- [x] 15-PLAN.md — Task 0 (verify) + Task 1 (api_key_hash + lookup migration) + Task 2 (TrustProxies + audit guard + null guard) + Task 3 (Octane race fix + enum cleanup) + Task 4 (strict-mode cutover) (completed 2026-05-20)
- [x] 15-02-PLAN.md — Gap closure: Task 1 (CR-01 cache-key rename + Tenant::hashApiKey helper) + Task 2 (CR-02 saved-hook invalidation + WR-02 null branch + WidgetController duplicate-forget cleanup) + Task 3 (WR-01 hash_equals + WR-04 seeder strict_types + WR-05 behavioral TrustProxies + WR-07 dual_accept fallback)

### Phase 16: Admin Dashboard Extensions

**Goal**: Platform administrators have full operational visibility — system health, failed job management, tenant impersonation, broadcast messaging, complete audit logs, and advanced analytics — so they can operate the platform without SSH access.
**Depends on**: Phase 13 (shipped baseline)
**Requirements**: REQ-adh-04, REQ-adh-05, REQ-adh-06, REQ-ash-01, REQ-ash-02, REQ-ash-03, REQ-ash-04, REQ-ash-05, REQ-ash-06, REQ-ash-07, REQ-ash-08, REQ-ash-09, REQ-ash-10, REQ-acm-10, REQ-acm-11, REQ-atu-02, REQ-apa-04, REQ-apa-05, REQ-apa-07, REQ-apa-08
**Success Criteria** (what must be TRUE):

  1. Admin can click "Impersonate" on any tenant and be switched into their dashboard context; impersonation is logged in the audit trail
  2. Admin health dashboard shows live queue depth (per queue), worker up/down status, Redis hit rate, LLM provider latency badge, and server memory — all on one screen
  3. Admin can view failed queue jobs, inspect the error, and retry or delete individual jobs
  4. Admin can send a broadcast message; all tenants see it on their next dashboard login
  5. Admin audit log captures all admin actions (suspend, delete, override, impersonate) with actor, target, and timestamp; searchable; 90-day retention
  6. Admin receives alert (email or in-app) when any tenant hits 80% or 100% of token quota

**Plans**: TBD
**UI hint**: yes

### Phase 16.1: Role Foundation (INSERTED)

**Goal:** Unify the existing two-table/two-guard auth split into a single-guard four-role RBAC foundation (super_admin, owner, manager, agent) so one user can hold multiple roles (e.g. super_admin + owner-of-tenant), every controller/policy/UI surface is gated by the canonical permission matrix, and the trigger that exposed the half-baked role system (UpdateWebsiteIndexingRequest::authorize() calling deleted isOwner()) is resolved end-to-end.
**Requirements**: D-01..D-21 (CONTEXT.md decisions; this is a foundation phase with no formal REQ-IDs in REQUIREMENTS.md — it prepares Phase 17's REQ-ctm-* / REQ-cdh-08 / REQ-ccm-08)
**Depends on:** Phase 16
**Plans:** 8/8 plans complete

Plans:

- [x] 16.1-01-PLAN.md — Wave 1: Backed Role + Ability enums, RolePermissions decision engine, Wave 0 test scaffolds
- [x] 16.1-02-PLAN.md — Wave 2: Migration sequence (drop FKs, drop admin_users, re-point FKs, drop users.role, make tenant_id nullable, create user_roles), UserRole model, User multi-role methods, Transaction::approvedBy retarget, AdminUser deletion
- [x] 16.1-03-PLAN.md — Wave 3: Single-guard cutover (drop admin guard), RequireSuperAdmin middleware, /login/choose chooser, role-aware LoginController redirect, webhook safety (Stripe + DK Bank stay anonymous)
- [x] 16.1-04-PLAN.md — Wave 4: Gate registration in AppServiceProvider, 4 policy upgrades stacking ownership + role-tier, UpdateWebsiteIndexingRequest fix (THE TRIGGER)
- [x] 16.1-05-PLAN.md — Wave 4: Client controllers authorize sweep (10 controllers), RegisterController writes UserRole(owner), AdminActivityLog::log() rewrite
- [x] 16.1-06a-PLAN.md — Wave 5: HandleInertiaRequests share() emits auth.user.can flat map, RoleBadge + ContextSwitchChip components, ChooseRole.vue page, AdminLayout + ClientLayout updates
- [x] 16.1-06b-PLAN.md — Wave 5: per-page v-if sweep across 16 Client Vue surfaces (admin pages stay gated by RequireSuperAdmin middleware), VueCanKeyAlignmentTest static guard catches template typos against Ability::cases()
- [x] 16.1-07-PLAN.md — Wave 6: DatabaseSeeder rebuild (4-account matrix + Demo Co), CLAUDE.md test creds update, 52-cell role×ability matrix tests (Gate + RolePermissions levels), manual D-20 browser smoke checkpoint, end-of-phase PHPStan baseline=0 + Pint + cross-cutting grep guards

### Phase 17: Team Management

**Goal**: Tenant owners can invite team members to their dashboard with role-based access control, and assign conversations to team members, enabling collaborative use of the platform.
**Depends on**: Phase 16 (admin audit log in place for member activity logging)
**Requirements**: REQ-cdh-08, REQ-ctm-01, REQ-ctm-02, REQ-ctm-03, REQ-ctm-04, REQ-ctm-05, REQ-ctm-06, REQ-ctm-07, REQ-ccm-08
**Success Criteria** (what must be TRUE):

  1. Owner can invite a team member by email; the invitee receives an email and creates an account scoped to the tenant
  2. Owner can assign roles (owner, editor, viewer) and change them; permissions are enforced immediately on next request
  3. Owner can remove a team member; their access is revoked within seconds
  4. Owner can transfer ownership to another member; previous owner becomes editor and must confirm
  5. Client can assign a conversation to any team member; the assignee receives an in-app notification
  6. Enterprise tenants can configure an SSO provider (SAML or OIDC); team members authenticate without a separate password

**Plans**: TBD
**UI hint**: yes

### Phase 18: WP.org Submission Completion

**Goal**: The WordPress plugin is published on WordPress.org, enabling discovery, installation, and auto-update for any WordPress site owner worldwide.
**Depends on**: Phase 14 (no code changes expected, but encryption must be in place before public release)
**Requirements**: REQ-wp-01, REQ-wp-07
**Success Criteria** (what must be TRUE):

  1. Plugin listing is live on wordpress.org/plugins with correct metadata, screenshots, and description
  2. WordPress.org reviewer approval received; plugin passes compliance review
  3. WordPress sites can install the plugin directly from the WP admin "Add Plugin" screen
  4. When a new plugin version is released, the WP admin update badge appears and one-click update works

**Plans**: TBD

### Phase 19: Billing Completion

**Goal**: The billing layer is production-complete — Stripe subscription lifecycle (create, upgrade, downgrade, cancel, reactivate, grace period, refunds), invoice history, payment method management, and prorated upgrades all work end-to-end in BTN-aligned flows.
**Depends on**: Phase 13 (billing foundation shipped; DK Bank shipped)
**Requirements**: REQ-aba-01, REQ-aba-03, REQ-aba-04, REQ-aba-06, REQ-bl-01, REQ-bl-02, REQ-bl-06, REQ-bl-07, REQ-bl-08, REQ-bl-09, REQ-cbs-03, REQ-cbs-04, REQ-cbs-05, REQ-cbs-07, REQ-anc-03, REQ-anc-06, REQ-atu-07, REQ-es-08
**Success Criteria** (what must be TRUE):

  1. Client can upgrade or downgrade their plan from the billing page; plan change takes effect immediately with correct proration
  2. Client can cancel their subscription; the plan reverts to free/trial at period end with a confirmation email
  3. Client can view a paginated invoice history with date, amount, and plan; PDF download works
  4. Failed payment triggers a 7-day grace period with email reminders on days 1, 3, and 7; account suspends on day 8 if unpaid
  5. Admin can issue a refund for any transaction from the admin panel; Stripe refund is reflected in invoice history
  6. Client sees a warning banner in the dashboard when token usage reaches 80% of their quota; a quota-warning email is sent once per billing period

**Plans**: TBD
**UI hint**: yes

### Phase 20: Conversation & Lead UX Polish

**Goal**: The conversation and lead management experience is feature-complete — full-text search, tags, export, live chat takeover, conversation ratings, streaming responses, citations, lead notes, lead merge, and bot avatar/offline message are all available.
**Depends on**: Phase 17 (team management — conversation assignment requires team context; can partially run in parallel)
**Requirements**: REQ-ccm-03, REQ-ccm-04, REQ-ccm-06, REQ-ccm-07, REQ-ccm-09, REQ-ccm-10, REQ-ccm-11, REQ-clm-04, REQ-clm-06, REQ-clm-07, REQ-cbr-03, REQ-cbr-06, REQ-ckb-12, REQ-cbc-09, REQ-cbc-10
**Success Criteria** (what must be TRUE):

  1. Client can search conversations by keyword and see matching results with highlighted snippets
  2. Client can tag conversations and filter the list by tag
  3. Widget user can rate a conversation thumbs up or down; rating is visible to the client in the conversation detail
  4. Client can take over a live conversation; the AI stops responding and the client can reply via the dashboard
  5. Bot responses stream token-by-token to the widget (words appear progressively, not all at once)
  6. Client can merge duplicate leads; conversation history is combined and the canonical lead is preserved

**Plans**: TBD
**UI hint**: yes

### Phase 21: Analytics & Notifications Completion

**Goal**: Client analytics are complete (top questions, quality metrics, geographic distribution, session duration, bounce rate, KB hit rate, real-time feed), and the notification system is fully functional (in-app center, preferences, digest emails, webhook failure alerts, notification history).
**Depends on**: Phase 20 (REQ-can-05 depends on REQ-ccm-07 conversation ratings from Phase 20)
**Requirements**: REQ-can-04, REQ-can-05, REQ-can-06, REQ-can-07, REQ-can-08, REQ-can-09, REQ-can-11, REQ-cnc-01, REQ-cnc-02, REQ-cnc-03, REQ-cnc-04, REQ-cnc-06, REQ-cdh-07, REQ-cwc-07
**Success Criteria** (what must be TRUE):

  1. Client analytics page shows top 10 questions by frequency, response quality score (thumbs up/down ratio), session duration, and bounce rate
  2. Client can see a country-level map of where widget users are located (top 10 countries)
  3. Client sees a real-time activity feed showing active conversations with last-message previews, auto-updating
  4. Client can configure which notification types they receive by email; changes take effect immediately
  5. Client has an in-app notification center (bell icon, unread count) showing new lead, quota warning, and system alert events
  6. Client receives a weekly digest email with conversation count, lead count, and token usage summary

**Plans**: TBD
**UI hint**: yes

### Phase 22: Account, Compliance & Enterprise Completion

**Goal**: Enterprise and compliance capabilities are complete — 2FA, GDPR data export, account deletion with recovery window, complete audit logs (login, data export, admin actions), SLA documentation, and enterprise support channels are all available.
**Depends on**: Phase 19 (billing must be stable before account deletion flows safely)
**Requirements**: REQ-cac-03, REQ-cac-04, REQ-cac-05, REQ-es-05, REQ-es-07, REQ-aal-01, REQ-aal-03, REQ-aal-04, REQ-aal-05
**Success Criteria** (what must be TRUE):

  1. Client can enable TOTP-based 2FA from account settings; a QR code is provided for setup and recovery codes are shown once
  2. Client can request account deletion; a 30-day recovery window is enforced; data is purged after the window
  3. Client can download a GDPR data export — a ZIP file containing their conversations, leads, and knowledge base items as JSON/CSV
  4. Audit log captures all login attempts (success and failure) with IP, user agent, and outcome; all data export events are logged; 90-day retention policy is enforced
  5. Enterprise SLA document is available at /legal/sla; enterprise clients have dedicated support contact details in their dashboard

**Plans**: TBD
**UI hint**: yes

---

## Progress

| Phase | Milestone | Plans Complete | Status | Completed |
|-------|-----------|----------------|--------|-----------|
| 1–13. v1.0 Phases | v1.0 | - | Complete | 2026-05-20 |
| 14. Data Encryption | v1.1 | 0/TBD | Not started | - |
| 15. Widget Hardening | v1.1 | 2/2 | Complete   | 2026-05-20 |
| 16. Admin Dashboard Extensions | v1.1 | 0/TBD | Not started | - |
| 17. Team Management | v1.1 | 0/TBD | Not started | - |
| 18. WP.org Submission | v1.1 | 0/TBD | Not started | - |
| 19. Billing Completion | v1.1 | 0/TBD | Not started | - |
| 20. Conversation & Lead Polish | v1.1 | 0/TBD | Not started | - |
| 21. Analytics & Notifications | v1.1 | 0/TBD | Not started | - |
| 22. Account, Compliance & Enterprise | v1.1 | 0/TBD | Not started | - |
