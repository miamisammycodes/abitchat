# Medium Backlog — Master Design

**Date:** 2026-05-12
**Status:** Scoping approved; per-cluster plans to be written one-at-a-time.
**Source audits:**
- `docs/superpowers/audits/2026-05-09-bug-audit.md` — findings M-NEW-1..15.
- 11 mediums (M1–M11) catalogued in `~/.claude/projects/-Users-sam-Dev-laravel-chatbot/memory/audit_aftermath.md`; never committed to a repo doc, copied verbatim below for durability.

## Goal

Close the still-open medium-severity findings from both May 2026 audits as six sequential PRs, ordered by risk and $ impact, so the medium backlog matches the already-closed critical/high backlog.

## Scoping decisions

| Decision | Choice | Rationale |
|---|---|---|
| Plan granularity | One plan per theme cluster (6 plans) | Bigger than per-finding (avoids ceremony cost); smaller than one bundle (avoids review blast radius). Mirrors how PRs #5–#11 shipped the highs. |
| Order | Risk / $ impact, highest first | Same prioritization the highs followed. |
| Spec scope | One master spec (this doc) + per-cluster plans | Keep decisions in one place; defer plan-writing so each plan executes against fresh `main`. |
| Plan-writing cadence | Plan #N written when plan #N-1 merges | Plans 4–6 would go stale during the multi-PR sequence. |
| Process | Full CLAUDE.md feature-dev process per cluster | Brainstorm-spec-plan already done at the bundle level; each plan still gets TDD-per-task, full-suite between tasks, browser smoke, two `/simplify` passes, behavior-change PR section. |

## Clusters & sequence

| # | Name | Items | Why this position |
|---|---|---|---|
| 1 | Billing races & integrity | M2, M-NEW-1, M-NEW-3, M-NEW-7 | Real $ — duplicate-charge, under-credit, trial bypass, duplicate leads. |
| 2 | Usage tracking cache | M-NEW-4, M-NEW-5, M-NEW-6 | Tenants exceed paid limits silently; single-class refactor. |
| 3 | Abuse / rate limiting + CORS | M10, M11, M-NEW-2, M4 | Public-surface security; CORS wildcard is a known wrong default. |
| 4 | Knowledge / RAG quality | M9, M-NEW-8, M-NEW-9, M-NEW-11 | Feature reliability — OOM, silent failures, bad extraction. |
| 5 | Misc operational | M6, M8, M-NEW-10, M3 (verify-first) | Leftover bin; mostly one-file fixes. |
| 6 | Frontend polish & a11y | M1, M5, M7, M-NEW-12, M-NEW-15 | Lowest urgency; ship last. |

## Items — verbatim

### Cluster 1 — Billing races & integrity

- **M2 — transaction-number TOCTOU race** (`BillingController::submitPayment`). Two parallel submissions with the same `transaction_number` both pass the existence check, both insert; one of the inserts violates the unique index *if* one exists, otherwise both rows survive.
- **M-NEW-1 — cross-transaction race on plan extension for same tenant** (`Admin/TransactionController::approve` + `Tenant::extendPlan`). Two admins approve two pending txns simultaneously; each row-locks its own `Transaction`, neither blocks the other; both compute `extendPlan` from the same base; second write overwrites first. Tenant pays for two months, receives one.
- **M-NEW-3 — `activateTrial` doesn't check `plan.is_active`** (`BillingController::activateTrial`). Inconsistent with `subscribe()` which aborts on inactive plans; exploitable post-deactivation.
- **M-NEW-7 — concurrent first messages create duplicate leads** (`LeadService::captureFromConversation` → `findExistingLead` → `createLead`). Two parallel widget messages on the same conversation both see `lead_id IS NULL`, both `createLead`. One ends up orphaned; tenant gets dupe notifications.

### Cluster 2 — Usage tracking cache

- **M-NEW-4 — usage cache missed-invalidated on conversation/lead/knowledge create** (`UsageTracker`). `forgetCache` is only called from `recordTokens`. Creating a conversation doesn't bust `tenant:{id}:usage`. For up to 60s after hitting a cap, more conversations slip through.
- **M-NEW-5 — usage cache key omits the period — month boundary bleed** (`UsageTracker`). `tenant:{id}:usage` carries last month's totals into the first 60s of the new month.
- **M-NEW-6 — `countByPeriod` non-sargable** (`UsageTracker`). `whereYear`/`whereMonth` wrap the column; index unused. At a million rows per tenant, each middleware check is a slow scan.

### Cluster 3 — Abuse / rate limiting + CORS

- **M10 — CORS `allowed_origins=['*']`** (`config/cors.php`). Default wildcard. Widget origin enforcement is separate, but the wildcard means any other API endpoint is fetchable from anywhere.
- **M11 — widget rate limit too generous** (`AppServiceProvider::boot` defines `RateLimiter::for('widget')` as `perMinute(60)->by(api_key ?? ip)`). 60/min is high for a chat widget; abuse possible. Also, falling back to `ip` alone when `api_key` is missing means a single IP can exhaust 60/min across multiple tenants (or, conversely, a malicious actor can drain one tenant's quota from many IPs as long as they have the api_key).
- **M-NEW-2 — no IP rate limit on `POST /register` or `POST /forgot-password`** (`routes/web.php`). Trial-farming and tenant-row spam from one IP. Forgot-password only has a per-email broker throttle.
- **M4 — analytics `days` param unbounded** (`AnalyticsController` or wherever the query lives). DoS via large `days` value driving large scans.

**Locked targets (final numbers in cluster 3 plan after the live baseline check):**
- Widget rate limit: **20/min, keyed by `api_key . ':' . ip`** (composite key — both required, neither falls back). Drops the 60→20 ceiling *and* fixes the keying so abuse from one IP across tenants is bounded per (tenant, IP). Per-conversation cap deferred unless `RateLimiter::for` permits it cleanly.
- `POST /register`: **5/min/IP**.
- `POST /forgot-password`: **5/min/IP**.
- Analytics `days`: **max 90** (matches typical analytics windows).
- CORS: **drop `'*'`; populate with `config('app.url')` plus widget-handled origins via `paths` filtering** — widget API stays public via its origin-check path, not via CORS wildcard.

### Cluster 4 — Knowledge / RAG quality

- **M9 — `sync` queue + slow webpage URL = user-visible 500** (`KnowledgeController` or wherever URL ingestion enqueues). When `QUEUE_CONNECTION=sync` (dev or misconfigured prod), the HTTP request hangs and 500s.
- **M-NEW-8 — vector dimension mismatch → hard pgvector error** (`RetrievalService` + `EmbeddingService::toPgVector`). Some Ollama builds return 384-dim from `nomic-embed-text` while column is `vector(768)`; pgvector throws.
- **M-NEW-9 — `GenerateEmbeddings` loads all chunks at once** (`GenerateEmbeddings::handle`). `->get()` materializes every NULL-embedding chunk. Large PDF imports OOM the worker; `tries=3` means it OOMs three times.
- **M-NEW-11 — DOCX extractor merges adjacent words** (`DocumentProcessor::extractFromDocx`). `strip_tags` on raw OOXML produces `pricelist` from `<w:t>price</w:t><w:t>list</w:t>`. RAG silently fails on those terms.

### Cluster 5 — Misc operational

- **M6 — widget fails when loaded async / via tag manager**. Loader assumes synchronous DOM ready state.
- **M8 — soft-deleted tenants invisible to admin**. Admin client index uses default query scope; deleted tenants can't be inspected or restored.
- **M-NEW-10 — `getTopQuestions` group-by full message body** (`AnalyticsService`). `GROUP BY content` on unindexed text column = full scan + filesort. Active tenants gateway-time-out the analytics page.
- **M3 (verify-first) — streaming chat orphans on LLM failure** (`ChatController::streamMessage`). Memory says C-NEW-4 in PR #6 restructured `streamMessage` with delete-on-throw on the user message. Plan's Task 0 greps for the current shape; if user-msg is inside the streaming closure with delete-on-throw, drop M3 from the cluster.

### Cluster 6 — Frontend polish & a11y

- **M1 — modal forms not reset on Cancel** (`Lead/Show.vue`, `Admin/Clients/Show.vue`). User opens modal, types, cancels, reopens — old data still there.
- **M5 — lead Show transcript misleading for multi-conversation leads** (`Lead/Show.vue`). Shows only the latest conversation's transcript without indicating others exist.
- **M7 — disabled paginator links keyboard-focusable** (`Conversations/Index.vue`). Disabled state isn't `tabindex=-1` / `aria-disabled`.
- **M-NEW-12 — plan toggle no in-flight guard** (`Admin/Plans/Index.vue`). Double-click sends two PATCHes; final state depends on which lands last.
- **M-NEW-15 — `ClientLayout.vue` Settings link 404s** (`ClientLayout.vue:~186`). Link target `/dashboard/settings` doesn't have a route.

## Verify-first / drop-list

Each cluster's plan opens with a 5-minute Task 0 verification pass against current `main`:

- **M3** — drop if `streamMessage` already has the orphan-safe closure pattern (very likely per PR #6 memory).
- **M-NEW-13, M-NEW-14** — already shipped per PR #5 ride-along; excluded from cluster 6 above (not listed). If verification finds them un-shipped, fold them in.
- Any other item — if grepping shows the fix already landed, drop it with a one-line note in the plan; don't fabricate work.

## Behavior changes deployable users should know about

| Cluster | Change | Who's affected | Mitigation |
|---|---|---|---|
| 3 | CORS wildcard → app-url allowlist | Any tenant integration calling the non-widget API from a third-party origin | Document the change in the PR description; widget remains accessible (origin-checked separately) |
| 3 | Widget rate limit 60→20/min keyed by `api_key:ip` composite | Tenants with chatty visitors *and* shared-IP scenarios (corporate NAT) | Conservative target; widget shows existing "too many messages" UX on 429 |
| 3 | Register/forgot-password 5/min/IP | Sign-up storms from a single egress IP | Conservative; legitimate users retry-after-1min |
| 3 | Analytics `days` capped at 90 | Anyone calling analytics with `days>90` | 422 with explicit error; doc the cap |
| 2 | Usage cache key gains `:YYYY-MM` suffix | None — existing entries orphan and expire in 60s | None needed |
| 4 | Vector dim mismatch becomes an early reject | Tenants on misconfigured Ollama builds | Better signal than current pgvector crash |

## Cross-cluster patterns

- **Race fixes** use `lockForUpdate` on the contested row inside existing `DB::transaction`. Don't introduce new transactions.
- **Cache-key changes** never use `:v2` version suffixes — partition by the data dimension that changed (period, tenant, etc.).
- **Front-end disable-during-flight** uses a per-row id ref (`togglingId.value`), not a global `isSubmitting` boolean.
- **All work follows the CLAUDE.md feature-dev process** — TDD per task (RED → GREEN → commit), full suite between tasks, browser smoke before PR, `/simplify` twice, behavior-change section in PR description.
- **Behavior-change discipline** — every item in the table above gets a one-line callout in its PR's `⚠️ Behavior changes` section.

## Out of scope

- **M-NEW-13, M-NEW-14** — already shipped in PR #5; do not include.
- **Production deploys & merchant comms** about new rate limits / CORS allowlist — separate ops task, not part of these PRs.
- **Shared `Modal.vue` component + `useClientFormatters`** — flagged as "uncovered during simplification" follow-ups in the May 7 audit aftermath memory; touches unmodified files; defer.
- **Any new audit findings discovered while doing this work** — flag in PR description, file for the next audit round; do not expand scope mid-plan.
- **Performance load-testing of the new rate limits / cache changes** — sufficient if Pest tests pass and browser smoke shows no regression; production telemetry handles the rest.

## What ships next

Plan #1 — Billing races & integrity — to be written next to `docs/superpowers/plans/2026-05-12-billing-races.md`. Four items, all in the `app/Http/Controllers/Admin/`, `app/Http/Controllers/Client/`, `app/Models/Tenant.php`, and `app/Services/Leads/LeadService.php` orbit. Each item gets a failing test, then implementation, then commit. Browser smoke covers: (a) two admin tabs approving the same tenant's two txns near-simultaneously, (b) trial activation against a deactivated plan, (c) duplicate submission of the same transaction number.
