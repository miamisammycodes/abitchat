# Requirements: AI-Powered WordPress Chatbot SaaS

**Last updated:** 2026-05-20
**Source:** prd.md (canonical PRD), cross-referenced with shipped codebase and SPEC documents.
**Status legend:** SHIPPED | PARTIAL | PENDING | DEFERRED

Shipped requirements are baseline context. PARTIAL and PENDING requirements map to Phases 14–22 (v1.1 milestone).

---

## Requirement Status Summary

| Status | Count |
|--------|-------|
| SHIPPED | ~155 |
| PARTIAL | ~35 |
| PENDING | ~30 |
| DEFERRED | 0 |
| **Total** | **~220** |

---

## PARTIAL and PENDING Requirements (Active Scope)

### Security (SEC)

| ID | Description | Status | Phase |
|----|-------------|--------|-------|
| REQ-sec-08 | Data encryption at rest for API keys, tokens, and sensitive fields | PENDING | Phase 14 |
| REQ-sec-10 | Security audit trail for auth failures and permission violations | PARTIAL | Phase 22 |

### Widget Hardening (from CONS-22)

| ID | Description | Status | Phase |
|----|-------------|--------|-------|
| CONS-22-a | TrustProxies middleware configured for proxy-aware IP resolution | SATISFIED | Phase 15 |
| CONS-22-b | Audit-log try/catch hardening (WidgetAudit::log unguarded) | SATISFIED | Phase 15 |
| CONS-22-c | api_key null guard in middleware chain | SATISFIED | Phase 15 |
| CONS-22-d | Octane race condition on SessionTokenService singleton | SATISFIED | Phase 15 |
| CONS-22-e | Enum/value-object cleanup for JWT token claims | SATISFIED | Phase 15 |
| CONS-22-f | Indexed api_key_hash column for lookup performance | SATISFIED | Phase 15 |
| CONS-22-g | Flip WIDGET_SESSION_DUAL_ACCEPT=false (strict mode cutover) | SATISFIED | Phase 15 |

### Admin Dashboard Extensions (ADH / ASH / ACM / ATU / AAL / APA)

| ID | Description | Status | Phase |
|----|-------------|--------|-------|
| REQ-adh-04 | Admin can impersonate any tenant for support | PENDING | Phase 16 |
| REQ-adh-05 | System health dashboard (queue depth, error rate, cache hit rate) | PENDING | Phase 16 |
| REQ-adh-06 | Failed jobs management (view and retry failed queue jobs) | PENDING | Phase 16 |
| REQ-ash-01 | Queue depth monitor (per queue: default, crawls, embeddings) | PENDING | Phase 16 |
| REQ-ash-02 | Worker health status (up/down, last heartbeat) | PENDING | Phase 16 |
| REQ-ash-03 | Redis cache hit rate display | PENDING | Phase 16 |
| REQ-ash-04 | Database query performance metrics (slow queries, avg time) | PENDING | Phase 16 |
| REQ-ash-05 | LLM provider availability and latency status | PENDING | Phase 16 |
| REQ-ash-06 | Admin error log viewer (tail, filter by severity/date) | PENDING | Phase 16 |
| REQ-ash-07 | Scheduled job status (last-run time and success/fail) | PENDING | Phase 16 |
| REQ-ash-08 | Storage usage display (total and per-tenant) | PENDING | Phase 16 |
| REQ-ash-09 | Server memory usage display | PENDING | Phase 16 |
| REQ-ash-10 | System health alerts when metrics cross threshold | PENDING | Phase 16 |
| REQ-acm-10 | General admin activity audit log (not just widget) | PARTIAL | Phase 16 |
| REQ-acm-11 | Admin broadcast message to all clients | PENDING | Phase 16 |
| REQ-atu-02 | Admin alerted at 80% / 100% tenant token quota | PENDING | Phase 16 |
| REQ-apa-04 | Churn analysis (churned tenants with plan and tenure) | PARTIAL | Phase 16 |
| REQ-apa-05 | Trial-to-paid conversion funnel | PARTIAL | Phase 16 |
| REQ-apa-07 | LLM cost tracking per tenant (tokens × per-token rate) | PARTIAL | Phase 16 |
| REQ-apa-08 | Error rate monitoring (failed LLM calls, crawler errors) | PENDING | Phase 16 |

### Team Management (CTM / CDH / CCM)

| ID | Description | Status | Phase |
|----|-------------|--------|-------|
| REQ-cdh-08 | Client can invite team members to their dashboard | PENDING | Phase 17 |
| REQ-ctm-01 | Invite team member by email; creates account scoped to tenant | PENDING | Phase 17 |
| REQ-ctm-02 | Remove team member; immediate access revocation | PENDING | Phase 17 |
| REQ-ctm-03 | Role management: owner, editor, viewer | PENDING | Phase 17 |
| REQ-ctm-04 | Team member list with names, emails, roles, joined date | PENDING | Phase 17 |
| REQ-ctm-05 | Transfer ownership to another team member | PENDING | Phase 17 |
| REQ-ctm-06 | Activity log per team member; last login shown | PENDING | Phase 17 |
| REQ-ctm-07 | SSO for enterprise team authentication (SAML or OIDC) | PENDING | Phase 17 |
| REQ-ccm-08 | Assign conversation to team member; assignee notified | PENDING | Phase 17 |

### WP.org Submission (WP)

| ID | Description | Status | Phase |
|----|-------------|--------|-------|
| REQ-wp-01 | WordPress plugin installable via WP.org repository | PARTIAL | Phase 18 |
| REQ-wp-07 | Plugin auto-update via WP.org auto-update mechanism | PENDING | Phase 18 |

### Billing Completion (ABA / BL / CBS / ES / ANC)

| ID | Description | Status | Phase |
|----|-------------|--------|-------|
| REQ-aba-01 | Stripe subscription creation (checkout session + webhook) | PARTIAL | Phase 19 |
| REQ-aba-03 | Invoice history with PDF download | PARTIAL | Phase 19 |
| REQ-aba-04 | Payment method management via Stripe Customer Portal | PARTIAL | Phase 19 |
| REQ-aba-06 | Grace period on payment failure (7-day; suspend on day 8) | PARTIAL | Phase 19 |
| REQ-bl-01 | Stripe subscription creation webhook flow | PARTIAL | Phase 19 |
| REQ-bl-02 | Stripe webhook handler (payment_intent.succeeded, subscription.*) | PARTIAL | Phase 19 |
| REQ-bl-06 | Plan downgrade on cancellation (reverts to free/trial at period end) | PARTIAL | Phase 19 |
| REQ-bl-07 | Payment failure handling with retry logic and suspension | PARTIAL | Phase 19 |
| REQ-bl-08 | Admin-initiated refund support via Stripe | PARTIAL | Phase 19 |
| REQ-bl-09 | Multi-currency billing (BTN for DK Bank; Stripe currency handling) | PARTIAL | Phase 19 |
| REQ-cbs-03 | Client can update payment method | PARTIAL | Phase 19 |
| REQ-cbs-04 | Client can cancel subscription | PARTIAL | Phase 19 |
| REQ-cbs-05 | Client can reactivate cancelled subscription | PARTIAL | Phase 19 |
| REQ-cbs-07 | Proration on plan upgrade | PARTIAL | Phase 19 |
| REQ-anc-03 | Payment failure email to client (within 60s of webhook) | PARTIAL | Phase 19 |
| REQ-anc-06 | Quota warning email at 80% token usage | PENDING | Phase 19 |
| REQ-atu-07 | Client in-app warning banner at 80% token quota | PARTIAL | Phase 19 |
| REQ-es-08 | API access for enterprise (elevated rate limits, documented endpoints) | PARTIAL | Phase 19 |

### Conversation & Lead UX Polish (CCM / CLM / CBR)

| ID | Description | Status | Phase |
|----|-------------|--------|-------|
| REQ-ccm-03 | Conversation tags (multi-tag support, filter by tag) | PENDING | Phase 20 |
| REQ-ccm-04 | Conversation export as CSV or PDF | PARTIAL | Phase 20 |
| REQ-ccm-06 | Full-text conversation search by keyword | PARTIAL | Phase 20 |
| REQ-ccm-07 | Widget user can rate conversation (thumbs up/down) | PENDING | Phase 20 |
| REQ-ccm-09 | Unread conversation indicator (badge count, real-time or on load) | PARTIAL | Phase 20 |
| REQ-ccm-10 | Conversation webhook (POST on new conversation event) | PENDING | Phase 20 |
| REQ-ccm-11 | Live chat takeover (suppress AI; client replies via dashboard) | PENDING | Phase 20 |
| REQ-clm-04 | Lead internal notes (timestamped, not visible to end user) | PARTIAL | Phase 20 |
| REQ-clm-06 | Lead notification threshold setting (Hot/Warm/All) | PARTIAL | Phase 20 |
| REQ-clm-07 | Lead merge (duplicate leads combined; canonical preserved) | PENDING | Phase 20 |
| REQ-cbr-03 | Response streaming (token-by-token to widget) | PARTIAL | Phase 20 |
| REQ-cbr-06 | Response citation (source document/URL shown below response) | PARTIAL | Phase 20 |
| REQ-ckb-12 | Chunk preview per knowledge item (list with char count) | PARTIAL | Phase 20 |
| REQ-cbc-09 | Bot avatar upload (image in widget header; fallback to default) | PARTIAL | Phase 20 |
| REQ-cbc-10 | Offline message when LLM provider is unreachable | PARTIAL | Phase 20 |

### Analytics & Notifications Completion (CAN / CNC / CDH)

| ID | Description | Status | Phase |
|----|-------------|--------|-------|
| REQ-can-04 | Top questions report (top 10 by frequency, monthly reset) | PARTIAL | Phase 21 |
| REQ-can-05 | Response quality metrics (thumbs up/down ratios; depends on REQ-ccm-07) | PENDING | Phase 21 |
| REQ-can-06 | Geographic distribution of widget users (country-level map) | PENDING | Phase 21 |
| REQ-can-07 | Session duration analytics (avg in minutes, histogram) | PARTIAL | Phase 21 |
| REQ-can-08 | Bounce rate analytics (1-message sessions %) | PARTIAL | Phase 21 |
| REQ-can-09 | KB hit rate analytics (% using KB content, per-document breakdown) | PARTIAL | Phase 21 |
| REQ-can-11 | Real-time activity feed (live list of active conversations) | PENDING | Phase 21 |
| REQ-cnc-01 | Email notification preferences (toggle per type) | PARTIAL | Phase 21 |
| REQ-cnc-02 | In-app notification center (bell icon, unread count, list) | PARTIAL | Phase 21 |
| REQ-cnc-03 | Webhook delivery failure notifications (in-app + email on 3 failures) | PENDING | Phase 21 |
| REQ-cnc-04 | Weekly activity digest email | PENDING | Phase 21 |
| REQ-cnc-06 | Notification history (last 90 days in settings) | PENDING | Phase 21 |
| REQ-cdh-07 | Client notification preferences in settings | PARTIAL | Phase 21 |
| REQ-cwc-07 | Rate limit display in widget settings (non-configurable in v1) | PARTIAL | Phase 21 |

### Account, Compliance & Enterprise Completion (CAC / ES / AAL / SEC)

| ID | Description | Status | Phase |
|----|-------------|--------|-------|
| REQ-cac-03 | Two-factor authentication (TOTP, QR setup, recovery codes) | PENDING | Phase 22 |
| REQ-cac-04 | Account deletion (soft delete, 30-day recovery, data purge) | PARTIAL | Phase 22 |
| REQ-cac-05 | GDPR data export (ZIP of conversations, leads, KB as JSON/CSV) | PENDING | Phase 22 |
| REQ-es-05 | Enterprise SLA document at /legal/sla | PENDING | Phase 22 |
| REQ-es-07 | Dedicated support channel for enterprise clients | PENDING | Phase 22 |
| REQ-aal-01 | General admin action audit log (searchable, 90-day retention) | PARTIAL | Phase 22 |
| REQ-aal-03 | Login audit log (success/failure, IP, user agent, outcome) | PARTIAL | Phase 22 |
| REQ-aal-04 | Data export audit (CSV downloads logged with user, timestamp, count) | PENDING | Phase 22 |
| REQ-aal-05 | Audit log retention policy (90-day default, configurable) | PENDING | Phase 22 |

---

## Shipped Requirements (Baseline)

All requirements not listed above are SHIPPED. Key shipped clusters:

- WP-02..06, WP-08..10 (WordPress plugin features)
- KB-01..10 (Knowledge base, complete)
- AI-01..10 (AI chat, complete)
- LC-01..10 (Lead capture, complete)
- ES-01..04, ES-06 (Enterprise inquiry, partial)
- ADH-01..03 (Admin login, tenant list, tenant detail)
- ACM-01..09 (Admin client management, complete)
- APA-01..03, APA-06, APA-09 (Admin platform analytics, partial)
- ATU-01, ATU-03..06 (Token usage, partial)
- ABA-02, ABA-05 (Subscription management, partial)
- ANC-01, ANC-02, ANC-04, ANC-05, ANC-07 (Notifications, partial)
- AAL-02 (Widget API audit log)
- CDH-01..06 (Client dashboard core)
- CCM-01, CCM-02, CCM-05 (Conversation management, partial)
- CLM-01..03, CLM-05, CLM-08 (Lead management, partial)
- CKB-01..11 (Client knowledge base, partial)
- CBC-01..08, CBC-11 (Bot configuration, partial)
- CWC-01..06, CWC-08 (Widget configuration, partial)
- CAN-01..03, CAN-10 (Analytics, partial)
- CAC-01, CAC-02 (Account settings, partial)
- CNC-05 (Transactional email templates)
- CBS-01..02, CBS-06 (Billing, partial)
- CBR-01, CBR-02, CBR-04, CBR-05 (Bot responses, partial)
- SEC-01..07, SEC-09 (Security, partial)
- BL-03..05 (Billing layer, partial)

---

## Traceability

| Phase | Requirements | Status |
|-------|-------------|--------|
| Phase 14 — Data Encryption | REQ-sec-08 | Pending |
| Phase 15 — Widget Hardening | CONS-22-a..g | Pending |
| Phase 16 — Admin Dashboard Extensions | REQ-adh-04/05/06, REQ-ash-01..10, REQ-acm-10/11, REQ-atu-02, REQ-apa-04/05/07/08 | Pending |
| Phase 17 — Team Management | REQ-cdh-08, REQ-ctm-01..07, REQ-ccm-08 | Pending |
| Phase 18 — WP.org Submission | REQ-wp-01, REQ-wp-07 | Pending |
| Phase 19 — Billing Completion | REQ-aba-01/03/04/06, REQ-bl-01/02/06/07/08/09, REQ-cbs-03/04/05/07, REQ-anc-03/06, REQ-atu-07, REQ-es-08 | Pending |
| Phase 20 — Conversation & Lead Polish | REQ-ccm-03/04/06/07/09/10/11, REQ-clm-04/06/07, REQ-cbr-03/06, REQ-ckb-12, REQ-cbc-09/10 | Pending |
| Phase 21 — Analytics & Notifications | REQ-can-04/05/06/07/08/09/11, REQ-cnc-01/02/03/04/06, REQ-cdh-07, REQ-cwc-07 | Pending |
| Phase 22 — Account, Compliance & Enterprise | REQ-cac-03/04/05, REQ-es-05/07, REQ-aal-01/03/04/05 | Pending |
