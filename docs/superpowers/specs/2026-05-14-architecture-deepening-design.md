# Architecture Deepening — Master Design

**Date:** 2026-05-14
**Status:** Scoping approved; per-cluster plans to be written one-at-a-time.
**Source:** `/improve-codebase-architecture` skill review on `main` after the medium backlog closed (PRs #12–#17). Six deepening candidates surfaced and grilled; six locked specs below.

## Goal

Deepen six shallow / leaky module clusters identified by architectural review, in the vocabulary of `superpowers:improve-codebase-architecture` (module / interface / seam / leverage / locality). Concentrate dispersed logic behind single canonical interfaces; tighten one safety-critical seam (tenant scoping). All six are **in-process** (Category 1) or **local-substitutable** (Category 2) per `DEEPENING.md` — none introduce a port + adapter pair, because none has two real adapters to justify the seam.

## Scoping decisions

| Decision | Choice | Rationale |
|---|---|---|
| Plan granularity | One plan per cluster (4 plans, not 6) | Knowledge cluster (#3, #5, #6) ships as one PR because all three rewrite `ProcessKnowledgeItem::handle()` and the same `app/Services/Knowledge/` files; splitting them would create three back-to-back merge conflicts. |
| Order | Risk / blast-radius first | A=Tenant scoping (latent CVE), then independent clusters B/C/D. |
| Spec scope | One master spec (this doc) + per-cluster plans | Decisions in one place; plans written when prior cluster merges, against fresh `main`. |
| Process | Full CLAUDE.md feature-dev process per cluster | TDD per task, full Pest suite between tasks, browser smoke for any UI-affecting change, two `/simplify` passes, behavior-change PR section. |
| Adapter discipline | **No new ports/adapters in v1.** | DEEPENING.md categories 1+2 only. Reach for adapters when a second adapter exists to justify the seam — not yet. |

## Clusters & sequence

| # | Name | Candidates | Why this position |
|---|---|---|---|
| A | Tenant-scoping enforcement | #1 | Safety; latent cross-tenant data-leak surface. Highest stakes. |
| B | Knowledge pipeline | #3 + #5 + #6 | All three rewrite `ProcessKnowledgeItem::handle()` and `app/Services/Knowledge/`. Must ship together. |
| C | Lead scoring | #2 | Two competing scoring engines; merge into one canonical `LeadScoring`. |
| D | UsageTracker enforcement | #4 | Small honest deepening — decision threshold moves to canonical owner. |

CONTEXT.md updated inline during grilling with five new canonical-module entries: `BelongsToTenant`, `LeadScoring`, `KnowledgeItemWorkflow`, `KnowledgeCache`, `DocumentProcessor`. UsageTracker's existing entry expanded to cover `canRecordUsage`.

---

## Cluster A — Tenant-scoping enforcement

**Friction.** Spatie multitenancy is installed but unwired (no middleware calls `makeCurrent()`, `currentTenant` container key is never populated). Tenant context is derived from `Auth::user()->tenant` via `Controller::getTenant()` — pure convention, not enforcement. ~30+ raw `where('tenant_id', $tenant->id)` call sites across controllers + services. One forgotten clause = silent cross-tenant data leak. Writes (`Lead::create(...)`) are equally exposed — `tenant_id` can be omitted with no protection.

**Scope of this cluster — prevention, not cure.** Cluster A's PR ships the prevention infrastructure: trait + boot hook + Larastan rule + baseline. At merge moment, *zero* of the 30+ existing violations are fixed — they all enter the baseline as known debt. New code is blocked from day one; old code converts opportunistically as Clusters B/C/D touch the relevant files. Files that no subsequent cluster touches (notably `AnalyticsService`'s 10 sites) remain in the baseline indefinitely until an explicit Cluster E cleanup pass. The "highest stakes" framing reflects long-term blast radius, not immediate-merge impact.

**Decisions (locked).**

| | Choice |
|---|---|
| Approach | Fork C — explicit query scopes + PHPStan rule (no global scopes; no Spatie wire-up; no repository pattern) |
| Trait | `App\Models\Concerns\BelongsToTenant` — provides `scopeForTenant(Tenant\|int)`, `tenant()` relation, boot-hook auto-stamp on `creating` when `tenant_id` is null and `Auth::user()?->tenant_id` exists |
| Applied to | User, Transaction, KnowledgeItem, UsageRecord, EnterpriseInquiry, Lead, Conversation. Message is transitive (joins via Conversation). |
| Public read API | `Lead::forTenant($tenant)` everywhere — client passes `$this->getTenant($req)`, admin passes `$client->id` |
| Public write API | `Lead::create([...])` — trait auto-stamps when authed user has a tenant_id |
| Static analysis | Larastan custom rule under `app/Rules/PHPStan/NoRawTenantIdWhere.php` — bans `where*('tenant_id', ...)` outside the trait file. Baseline generated on day one; baseline shrinks per migration PR. |
| Migration cadence | M-baseline — grandfather all current violations, fix incrementally, rule blocks new violations from day one |

**G-1 — Boot hook in non-auth contexts.** Verified safe:
- Admin routes use `Auth::guard('admin')` with a separate `AdminUser` model. Default `Auth::user()` is null → hook does nothing → admin must pass `tenant_id` explicitly (existing convention).
- Queue / console contexts have no authed user → hook does nothing → caller passes `tenant_id` explicitly. If they forget, DB NOT NULL constraint catches loudly.
- No code change beyond what's spec'd.

**G-2 — Larastan rule coverage.** Rule A is AST-targeted at `where*('tenant_id', ...)` `MethodCall` nodes on Eloquent builders. It will **not** catch `DB::table(...)->where('ki.tenant_id', ...)` (qualified-column + facade). The two existing such sites (`RetrievalService.php:55, 95`) are rewritten in Cluster B to use Eloquent (`KnowledgeChunk` with `whereHas('knowledgeItem', fn ($q) => $q->forTenant($tenant))`). After Cluster B, no `DB::table` queries with `'*.tenant_id'` exist; Rule A's Eloquent-only scope is sufficient.

**A→B window.** Between Cluster A merging and Cluster B merging, new `DB::table()->where('*.tenant_id', ...)` code would be **unchecked** by Rule A. The window is one PR cycle; relying on convention here. If the window widens for any reason (Cluster B blocked, rollback), extend Rule A to cover `DB::table` + qualified columns as a follow-up rather than letting the convention gap persist.

**G-6 — Existing `Conversation::forTenant`.** Trait wins; delete the model-local scope when adding the trait (one-line conflict otherwise).

**Files.**
- New: `app/Models/Concerns/BelongsToTenant.php`, `app/Rules/PHPStan/NoRawTenantIdWhere.php`, `phpstan-baseline.neon`
- Touched (apply trait): the 7 models above
- Touched (delete redundant scope): `app/Models/Conversation.php`
- Touched (rewrite to `forTenant`): batched per-file in the baseline-shrinking PRs after Cluster B

**Out of scope.** Spatie wire-up (`makeCurrent()` semantics); per-tenant DB connections; repository pattern; admin "log in as client" feature.

---

## Cluster B — Knowledge pipeline (workflow + cache + processor merge)

**Friction.** Three overlapping problems in `ProcessKnowledgeItem` and `app/Services/Knowledge/`:
1. KnowledgeItem state transitions are split across model methods, job `handle()`, and job `failed()` callbacks. No precondition guards, no error capture, no retry path. Race possible where `markReady` lands after `markFailed`.
2. `RetrievalService` owns cache key shape *and* cache version invalidation is duplicated in `KnowledgeBaseController` (both files build `"knowledge_version:{tenant}"` strings).
3. `TextChunker` and `DocumentProcessor` are halves of one job; both have exactly one caller (`ProcessKnowledgeItem::handle()`); the job also owns a third concern (the `extractContent()` type-switch).

**Decisions (locked).**

### B-1 — `KnowledgeItemWorkflow` + `KnowledgeItemStatus` enum

| | Choice |
|---|---|
| Approach | E2 — workflow service + PHP 8.1 backed enum |
| Files | `app/Services/Knowledge/KnowledgeItemWorkflow.php`, `app/Enums/KnowledgeItemStatus.php` |
| Enum values | `Pending`, `Processing`, `Ready`, `Failed` (string-backed) |
| Transitions (PS-strict) | `markProcessing` from {Pending, Failed}; `markReady` from {Processing} only; `markFailed(Throwable)` from {any non-Ready}; `retry` from {Failed} → {Pending} + dispatch `ProcessKnowledgeItem` |
| Error capture (EC1) | Migration: `error_message` (text, nullable), `failed_at` (timestamp, nullable). Captured on `markFailed`; cleared on `retry`. |
| Model changes | `markAsProcessing/Ready/Failed` deleted from `KnowledgeItem`. `is*()` predicates kept as enum comparisons. `status` cast to enum. |
| Workflow dependencies | Injects `KnowledgeCache` (per B-2 below); `markReady($item)` calls `$cache->invalidate($item->tenant)` so newly-ready chunks become retrievable immediately (G-5). One extra `tenant` lookup per ready transition — acceptable cost; cache eager-load on the item if hot. |
| UI changes | Knowledge listing surfaces `error_message` + `failed_at` for failed items. New `POST /knowledge/{id}/retry` route + button. |
| Illegal-transition behavior | Throws `App\Exceptions\InvalidTransitionException` (subclass of `\DomainException`). |

**G-4 — Double `markFailed()`.** Both `ProcessKnowledgeItem::failed()` and `GenerateEmbeddings::failed()` may fire. Resolution: **overwrite**. Latest failure is most useful in UI. `markFailed` is idempotent on destination (no exception from already-Failed → Failed); PS-strict only enforces the valid-from-states rule (any non-Ready).

### B-2 — `KnowledgeCache`

| | Choice |
|---|---|
| Approach | G3 — extract cache only; vector + keyword stay inline in `RetrievalService` |
| Files | New: `app/Services/Knowledge/KnowledgeCache.php` |
| Public interface | `get(Tenant, string $query): ?array<string>`, `put(Tenant, string $query, array<string> $chunks): void`, `invalidate(Tenant): void` |
| Owned constants | Result-cache key `"knowledge:{tenant}:v{version}:{md5(query)}"`, version key `"knowledge_version:{tenant}"`, TTL 600s |
| `RetrievalService` change | Drops inline `Cache::remember`. Calls `$cache->get()` → on miss, computes chunks → `$cache->put()`. Vector + keyword + fallback logic unchanged in behavior, but DB queries rewritten to Eloquent (G-2 dependency for Cluster A's PHPStan rule). |
| `KnowledgeBaseController` change | Private `clearKnowledgeCache()` deleted. Three call sites (`store`, `update`, `reprocess`) call `$cache->invalidate($tenant)` directly. |
| `KnowledgeItemWorkflow` change | `markReady` also calls `$cache->invalidate($tenant)` (G-5). |

### B-3 — `DocumentProcessor::process()`

| | Choice |
|---|---|
| Approach | H1 — merge `TextChunker` into existing `DocumentProcessor`; rename keeps churn low |
| New method | `DocumentProcessor::process(KnowledgeItem $item): array<string>` — owns type-switch, extraction, chunking; returns chunk strings |
| Existing methods | `extractFromFile`, `extractFromUrl` → `private` |
| `TextChunker` fate | Deleted. 137 lines of word-aware splitting move to private methods on `DocumentProcessor`. |
| `ProcessKnowledgeItem::handle()` change | Becomes `$chunks = $processor->process($this->item);` — the `extractContent()` type-switch (lines 110-118) deleted; the `$chunker` constructor parameter dropped. |
| Chunk size | Hardcoded as today: size 500, overlap 50. No caller varies it; no v1 config surface. |
| Cache invalidation handoff | Stays in job: processor returns chunks; job writes them in DB transaction + dispatches `GenerateEmbeddings`. `KnowledgeItemWorkflow::markReady` (called by `GenerateEmbeddings`) handles the cache invalidate. |

**Files (cluster total).**
- New: `KnowledgeItemWorkflow.php`, `KnowledgeItemStatus.php`, `KnowledgeCache.php`, `InvalidTransitionException.php`, migration `add_error_message_and_failed_at_to_knowledge_items_table`, retry-route controller method
- Touched: `KnowledgeItem.php` (status cast + delete markAs methods), `RetrievalService.php` (Eloquent rewrite + cache delegation), `ProcessKnowledgeItem.php` (workflow + processor delegation), `GenerateEmbeddings.php` (workflow delegation), `KnowledgeBaseController.php` (cache delegation + retry route), Knowledge listing Vue page (error display + retry button)
- Deleted: `TextChunker.php`, `KnowledgeBaseController::clearKnowledgeCache`

**Out of scope.** Strategy split (`VectorRetriever` / `KeywordRetriever`); decorator pattern; multilingual stopwords; hybrid retrieval; BM25; configurable chunk size; alternate chunking strategies; multi-step Processing substates; auto-retry with exponential backoff.

---

## Cluster C — Lead scoring merge

**Friction.** Two parallel scoring services with overlapping-but-divergent signals, weights, and keyword dictionaries:
- `LeadService::calculateScore` (private) fires on chat message processing (`ChatController::captureLeadFromMessage`).
- `LeadScoringService::updateLeadScore` fires on explicit widget lead-form submission (`Widget/LeadController::store`).
Same Lead, different entry points, different scoring math. Whichever runs last wins.

**Decisions (locked).**

| | Choice |
|---|---|
| Approach | D2 — new canonical `LeadScoring` module (rename `LeadScoringService` → `LeadScoring`) |
| Location | `app/Services/Leads/LeadScoring.php` |
| Public interface | `score(Lead $lead, ?Conversation $conversation = null): int`, `temperature(int $score): string` |
| Owns | All signals, weight tables, keyword dictionaries, temperature thresholds (hot/warm/cold) |
| Signal reconciliation (R2) | LeadScoringService signal set as baseline; add LeadService's `provided_company`, `message_sent`, `long_conversation`, `return_visitor`. Drop `high_engagement` to avoid double-counting with the concrete engagement signals. |
| Weight overlap | LeadScoringService values win for divergences: pricing=25 (not 15), demo=30 (not 20). Contact-info weights unchanged (all three agree). |
| Negative signal | `negative_sentiment(-10)` preserved |
| Keyword dictionaries | Union: pricing, demo, timeline, competitor, negative, contact, purchase |
| `LeadService` changes | `captureFromConversation` + `updateLead` delegate to `LeadScoring::score()`. Private `calculateInitialScore` / `calculateScore` / `scoreHighIntentKeywords` / `SCORE_WEIGHTS` / `HIGH_INTENT_KEYWORDS` deleted from `LeadService`. |
| `Widget/LeadController` change | Injects `LeadScoring` instead of `LeadScoringService` |

**Files.**
- New: `app/Services/Leads/LeadScoring.php` (combined signal set + keywords + temperature)
- Touched: `LeadService.php` (delete scoring guts, delegate), `Api/V1/Widget/LeadController.php` (DI swap)
- Deleted: `LeadScoringService.php`

**Out of scope.** R3 fresh-from-product redesign of signal set; sentiment analysis beyond keyword matching; LLM-based intent detection; multi-language keyword sets.

---

## Cluster D — UsageTracker.canRecordUsage

**Friction.** The decision threshold (`remaining() === 0`) for "may this tenant use more of type X" lives in middleware (`CheckUsageLimits.php`) — not in the canonical `UsageTracker`. If product later adds grace periods, soft caps, or overage allowances, that logic has nowhere natural to live. Honest deepening: pull the decision into the canonical owner.

**Decisions (locked).**

| | Choice |
|---|---|
| Approach | F2 — small honest deepening; not F1 (no `UsageDecision` result type), not F3 (no new `UsageGate` module) |
| New method | `UsageTracker::canRecordUsage(Tenant $tenant, string $type): bool` — returns `false` when `remaining($tenant, $type) !== null && remaining(...) <= 0`, `true` otherwise (including unlimited / null). **Note:** this tightens the existing middleware check from `=== 0` to `<= 0`, fixing a latent bug where over-consumed tenants (`remaining` somehow negative) currently slip through. No known production case; semantic correctness. |
| Middleware change | `CheckUsageLimits::handle()` replaces `$remaining = $this->tracker->remaining(...)` + `$remaining === 0` test with `! $this->tracker->canRecordUsage(...)`. Net ~3 lines simpler. |
| What stays in middleware | Tenant resolution (auth + api_key + cache), `isActive()` check, `hasPlan() OR isOnTrial()` check, type→message map, JSON-vs-redirect response formatting. These are HTTP-layer or Tenant-state concerns, not usage. |
| Future hook | Grace periods / soft caps / overage allowances all live in `canRecordUsage` if/when product asks. |

**Files.**
- Touched: `app/Services/Usage/UsageTracker.php` (add method), `app/Http/Middleware/CheckUsageLimits.php` (use it)

**Out of scope.** Grace period rules themselves (no product driver yet); `UsageDecision` result type (F1); `UsageGate` module (F3); rate-limit-style overage allowances.

---

## Behavior changes deployable users should know about

| Cluster | Change | Who's affected | Mitigation |
|---|---|---|---|
| A | Larastan baseline rule blocks new `where('tenant_id', ...)` calls outside `BelongsToTenant` trait | Developers writing new tenant-scoped queries | PR description shows the lint failure with the `forTenant($tenant)` replacement |
| A | Boot hook auto-stamps `tenant_id` on `Lead::create([...])` etc. when authed | Code that intentionally creates tenant_id=NULL rows | None — only fires when authed user has a tenant_id; admin/console/queue contexts pass through unchanged |
| B | `KnowledgeItem` rows now carry `error_message` + `failed_at` after a failed processing | Anyone reading the table | Backfill not required — nullable columns; old failed rows show "Failed (no detail)" until reprocessed |
| B | Failed Knowledge Items now have a retry button | Tenants with previously-failed items | UX improvement — no manual re-upload needed |
| B | Newly-ready Knowledge Items invalidate the retrieval cache immediately | Chat visitors querying right after a new upload | UX improvement — chunks visible immediately, not 10min-TTL'd |
| B | `TextChunker` class deleted, `DocumentProcessor` constructor signature for `ProcessKnowledgeItem` job changes | Any code injecting `TextChunker` (only `ProcessKnowledgeItem`) | Single internal job; one-file rewrite in the PR |
| C | `LeadScoringService` class deleted, replaced by `LeadScoring` | Code referencing `LeadScoringService` (one controller) | DI swap in the same PR |
| C | Existing leads keep their stored scores; future score recalculations use the merged signal set | Tenants — minor score drift on next recalc | Acceptable; scores are advisory, not contractual |
| D | Threshold tightened from `remaining === 0` to `remaining <= 0` — over-consumed tenants now blocked instead of slipping through | Tenants with negative `remaining` (no known production case) | None — fixes a semantic bug |

## Cross-cluster patterns

- **One adapter ≠ a seam.** Cluster B explicitly rejected the strategy/decorator split for retrieval (`VectorRetriever` / `KeywordRetriever`) because there's exactly one caller and no runtime variation — `LANGUAGE.md`'s "one adapter means a hypothetical seam" rule. Apply this discipline if new candidates arise mid-execution.
- **Canonical-owner pattern.** Each cluster names a single module that owns its concern (`BelongsToTenant`, `LeadScoring`, `KnowledgeItemWorkflow`, `KnowledgeCache`, `DocumentProcessor`, `UsageTracker`). CONTEXT.md entry explicitly forbids direct manipulation of the underlying primitives outside that module.
- **PS-strict everywhere applicable.** State machines and enforcement gates fail loud on violations. Idempotent on destination, strict on source.
- **Migration sequencing.** A → B → C → D, each as one PR. A's baseline file shrinks across B/C/D's PRs as touched files convert raw `where('tenant_id'` calls to `forTenant()`.
- **All work follows CLAUDE.md feature-dev process.** TDD per task (RED → GREEN → commit), full Pest suite between tasks, browser smoke before PR for any UI-affecting change (B and the retry button), Pint → `/simplify` → Pint → `/simplify` → PR.

## Out of scope (master-level)

- **Repository pattern.** Considered for Cluster A (Fork D); rejected. Reach for repositories when ORM-active-record genuinely blocks testability — not when convention is the problem.
- **Spatie multitenancy wire-up.** Considered for Cluster A (Fork A); rejected. Single-DB story is Spatie's weaker half; explicit scopes serve us better.
- **Strategy + decorator split for retrieval.** Considered for Cluster B (G1); rejected per "one adapter = hypothetical seam."
- **`PromptBuilder` extraction from `ChatService`.** Surfaced during exploration; rejected — `ChatService` is already deep, extraction wouldn't concentrate complexity, just move it.
- **`EmbeddingService` deepening.** Surfaced during exploration; rejected — Prism is already the adapter; the current `EmbeddingService` is a thin wrapper. Future: either add real caching/batching, or delete and inline. Not v1.
- **`AnalyticsService` simplification.** Surfaced during exploration; deferred — 296 lines of stateless query methods with no urgent driver. Revisit when a performance complaint or signal-set change appears.
- **Production deploys & migration tooling.** Each cluster's PR description carries the deploy steps; orchestrating the rollout itself is an ops task.
- **Any new architectural friction discovered mid-execution.** Flag in PR description, add to backlog for next review; do not expand scope.

## What ships next

**Plan A — Tenant-scoping enforcement** — to be written at `docs/superpowers/plans/2026-05-14-tenant-scoping.md`. Tasks:

- **Task 0 — Verifications (re-confirm spec assumptions against current `main`):**
  - Admin `Auth::user()` is null on admin routes (separate `AdminUser` guard).
  - Every tenant-scoped factory in `database/factories/` specifies `tenant_id` explicitly (or via a `forTenant` factory state). If any factory creates a tenant-scoped model without `tenant_id` and relies on auth context, flag for fix before Task 1. **This prevents the boot hook from breaking unrelated tests.**
  - No existing code calls `Lead::create([...])` without `tenant_id` in an authed context where the auto-stamp would change behavior (grep `->create\(` near tenant-scoped models).
- **Task 1** — `BelongsToTenant` trait + boot hook + RED test in `tests/Unit/Models/BelongsToTenantTest.php` (write before model, then implement).
- **Task 2** — Apply trait to 7 models (User, Transaction, KnowledgeItem, UsageRecord, EnterpriseInquiry, Lead, Conversation); delete redundant `Conversation::forTenant` scope (G-6).
- **Task 3** — Larastan `NoRawTenantIdWhere` rule + RED rule-self-test under `tests/Unit/Rules/NoRawTenantIdWhereTest.php`.
- **Task 4** — Generate baseline via `./vendor/bin/phpstan analyse --generate-baseline=phpstan-baseline.neon`; include baseline in PR with a note in the PR description acknowledging the ~30 grandfathered violations.
- **Task 5** — Browser smoke — **verify trait boot-hook non-regression**: lead capture via widget chat, knowledge upload, conversation start, register flow — each create path runs to completion and stamps `tenant_id` correctly. Not testing query correctness (no queries changed in this PR).
- **Task 6** — PR with `⚠️ Behavior changes` section explicitly noting the Larastan baseline rule and the boot hook's contextual no-op in admin/queue/console.

Plans B/C/D written when prior cluster merges, in order.

**Cluster E — baseline cleanup sweep (follow-up).** After D merges, file an explicit plan to convert remaining baseline entries to `forTenant()` — chiefly `AnalyticsService`'s 10 sites and any other files not touched by B/C/D. Scope: mechanical refactor, file-by-file, shrink the baseline to zero. Likely 1-2 PRs depending on file count remaining. Not blocking A-D; ships at lower priority once the canonical infrastructure is proven in production.
