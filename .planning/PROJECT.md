# Project: AI-Powered WordPress Chatbot SaaS

**Last updated:** 2026-05-20

## Core Value

Enable any WordPress site owner to deploy an AI-powered chatbot — trained on their own content — that captures leads, answers visitor questions, and delivers measurable ROI, without requiring technical expertise.

## Project Context

**Status:** Brownfield — actively shipped production codebase (PRs #3–#29 merged as of 2026-05-20). Phases 1–13 are complete baseline. This PROJECT.md governs the next milestone: v1.1 Backlog Completion (Phases 14–22).

**Team:** Solo developer (user) + Claude (implementer).

## Tech Stack

- **Backend:** Laravel 13+, PHP 8.3+
- **Frontend:** Vue 3 (Composition API), Inertia.js, Tailwind CSS v4
- **Database:** MySQL 8.0+ (primary), Redis (cache + queues)
- **Vector store:** Postgres + pgvector (production) — see Spec Corrections below
- **Multi-tenancy:** Spatie Laravel Multitenancy (single DB, tenant_id columns)
- **LLM:** Prism abstraction layer — Ollama/gemma3:4b (dev), Groq/llama-3.1-8b-instant (prod)
- **Payments:** Laravel Cashier (Stripe) + DK Bank QR (behind DK_BANK_ENABLED killswitch)
- **Auth:** Laravel Sanctum (client), separate AdminUser guard (admin), JWT HS256 (widget sessions)

## Spec Corrections (SPEC wins over PRD)

Two PRD values are superseded by shipped implementation facts:

| PRD value | Correct value | Source |
|-----------|---------------|--------|
| SQLite-vec for vector store | **pgvector (Postgres)** | Architecture SPEC + shipped codebase |
| bot_custom_instructions max 2000 chars | **max 1000 chars** | Prompt injection defense SPEC (PR #11) |

These corrections are locked. The PRD's original values must NOT be used in new planning.

## Currency Decision (LOCKED)

**BTN (Bhutanese Ngultrum) is the authoritative currency for all new pricing and billing work.**

The PRD specifies USD pricing (Starter $29, Business $79, Enterprise $199). This is stale. The operational market is Bhutan; DK Bank QR settles in BTN; live pricing is BTN. New pricing requirements, billing UI, and payment flows MUST use BTN.

Current BTN pricing:
- Free Trial: Nu. 0 (full features during trial)
- Starter: Nu. 5,000/month
- Business: Nu. 7,000/month
- Enterprise: Contact sales

## Locked Architectural Decisions

### DEC-05 — BelongsToTenant Trait + NoRawTenantIdWhere Larastan Rule

**Decision:** All tenant-scoped Eloquent models MUST use the `BelongsToTenant` trait. Direct `where('tenant_id', ...)` calls are banned by a custom Larastan rule (`App\Rules\PHPStan\NoRawTenantIdWhere`). The canonical query API is `Model::forTenant($tenant)`.

**Rationale:** Prevents cross-tenant data leaks. PHPStan enforcement catches violations at CI time, not runtime.

**Affected models:** User, Transaction, KnowledgeItem, UsageRecord, EnterpriseInquiry, Lead, Conversation.

**Status:** Shipped (PR #18). Zero grandfathered violations.

---

### DEC-09 — PHPStan/Larastan Baseline = Zero (CI-Enforced)

**Decision:** The PHPStan baseline file is drained to zero violations. New violations block CI. The baseline must remain empty.

**Rationale:** Any new code must meet the same type-safety standard as the refactored codebase. A non-zero baseline is technical debt that erodes over time.

**Enforcement:** Larastan level-max with custom rules. `phpstan.neon` baseline: empty.

**Status:** Shipped (PRs #22, #23). Zero violations as of 2026-05-15.

---

### DEC-12 — Widget Session Tokens: JWT HS256 Architecture

**Decision:** Widget sessions use JWT HS256 tokens signed with `APP_KEY`. Token claims bind to `api_key`, canonical `Origin`, and client IP. TTL: 30 minutes. Stored in-memory only (never localStorage/sessionStorage). Dual-accept migration window: 7 days (`WIDGET_SESSION_DUAL_ACCEPT=true` default).

**Rationale:** Prevents session hijacking, API key reuse from unauthorized origins, and cross-tenant token replay. In-memory-only storage prevents XSS exfiltration.

**Critical constraint:** `TrustProxies` middleware MUST be configured before `WIDGET_SESSION_DUAL_ACCEPT` is flipped to `false` (strict mode). Without it, IP-binding and rate limits collapse to the proxy IP.

**Status:** Shipped (PR #29). Strict mode cutover blocked on Phase 15 hardening.

---

### DEC-14 — Vector Store: Postgres + pgvector

**Decision:** Postgres with the pgvector extension is the production vector store. SQLite-vec was the original PRD specification but was superseded during build.

**Rationale:** pgvector integrates natively with the primary MySQL/Postgres data layer, supports cosine similarity search at scale, and avoids a separate embedded-DB dependency.

**Status:** Shipped. Two-tier retrieval: pgvector cosine → keyword LIKE fallback.

---

## Baseline: Shipped Phases (v1.0)

The following phases are complete and serve as baseline context. They are NOT new work.

| Phase | Name | Status | Key PRs |
|-------|------|--------|---------|
| 1 | Core Foundation | Complete | - |
| 2 | Widget & Chat Interface | Complete | - |
| 3 | AI Integration | Complete | - |
| 4 | Knowledge Base | Complete | - |
| 5 | Lead Capture | Complete | - |
| 6 | Admin Dashboard | Complete | - |
| 7 | Billing & Payments | Complete | - |
| 8 | Client Dashboard | Complete | - |
| 9 | Admin Dashboard Extensions | Partial | PRs #3-#17 |
| 10 | Security Hardening | Complete | PRs #3-#17 |
| 10.5 | Architecture Deepening | Complete | PRs #18-#23 |
| 11 | DK Bank QR | Complete | PR #24 |
| 12 | Registration Wizard + Site Scraping | Complete | PR #25 |
| 12.5 | Crawl Polling + DK Parser Fixes | Complete | PRs #26-#28 |
| 12.6 | WP.org Submission | In Progress (~85%) | - |
| 13 | Widget Session Tokens + Rate Limiting | Complete | PR #29 |

## Key Decisions Log

| Decision | Description | Phase | Status |
|----------|-------------|-------|--------|
| DEC-01 | Structural prompt injection defense (XML delimiters, no regex) | Phase 10 | Shipped |
| DEC-02 | bot_custom_instructions: partial trust only (persona/flavor, sandboxed) | Phase 10 | Shipped |
| DEC-03 | Knowledge chunk caps: 1500 chars/chunk, 5 chunks max, 7500 total | Phase 10 | Shipped |
| DEC-04 | Canonical prompt section order (bot-type → tone → lead → persona → knowledge → STRICT RULES) | Phase 10 | Shipped |
| DEC-05 | BelongsToTenant + NoRawTenantIdWhere Larastan rule | Phase 10.5 | Shipped |
| DEC-06 | KnowledgeItemWorkflow state machine (Pending→Processing→Ready→Failed) | Phase 10.5 | Shipped |
| DEC-07 | LeadScoring as canonical full-rescore service (scores can decrease) | Phase 10.5 | Shipped |
| DEC-08 | UsageTracker::canRecordUsage threshold: <=0 (not ===0) | Phase 10.5 | Shipped |
| DEC-09 | PHPStan baseline = zero, CI-enforced | Phase 10.5 | Shipped |
| DEC-10 | DK Bank QR: backend polling, DK_BANK_ENABLED killswitch, approveAndActivate() | Phase 11 | Shipped |
| DEC-11 | Website crawler: sitemap-first, 100 pages max, depth 3, 1 req/sec, diff-only refresh | Phase 12 | Shipped |
| DEC-12 | Widget JWT session tokens (HS256, APP_KEY, IP+origin binding) | Phase 13 | Shipped |
| DEC-13 | Widget per-IP rate limits: 10/min init, 30/min message, 5000/day | Phase 13 | Shipped |
| DEC-14 | pgvector as production vector store (supersedes PRD SQLite-vec) | Phase 4 | Shipped |
| DEC-15 | BTN is authoritative currency; PRD USD pricing is stale | Phase 19 | Locked |
