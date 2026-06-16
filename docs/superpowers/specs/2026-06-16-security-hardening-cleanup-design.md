# Security Hardening + Tech-Debt Cleanup — Design

**Date:** 2026-06-16
**Status:** Approved (design)
**Author:** Sameer + Claude

Batch remediation of the findings surfaced by the 2026-06-16 codebase exploration.
Every finding below was verified against live code before this spec was written.

---

## Decisions

| Question | Decision |
| --- | --- |
| Packaging | **Two PRs**: PR 1 = security & DK hardening (ships/deploys first), PR 2 = correctness, dead-code & infra |
| DK Bank scope | **Fix all DK issues now**, but as *tolerant/configurable* changes — never hardcode a guessed DK format. Real end-to-end DK verification stays out of scope (the killswitch keeps it dark). |
| Widget dual-accept | Fix `.env.example` default + TrustProxies guidance + **strict-mode test** + **passthrough telemetry**. No live behavior change in the PR; the prod flip remains an ops action. |
| Admin half-built features | **Wire up both** the audit-log writer and the client restore/sort UI. |
| Lead "hot" threshold | **70** (warm 40–69). `LeadScoring` becomes the single source of truth; `AnalyticsService` consumes it. |

---

## PR 1 — Security & DK hardening

Branch: `fix/security-hardening`. Self-contained; deployable without PR 2.

### 1.1 DK Bank server-side killswitch (security)

**Problem:** `config('services.dk_bank.enabled')` is read only in `HandleInertiaRequests:85`
(the `dkBankEnabled` Inertia prop) and `Subscribe.vue`. No controller or route checks it, so
`DkBankQrController::{start,show,status,verifyRrn}` reach the **live DK Bank APIs** when the flag
is off — anyone who guesses the route names can hit them.

**Fix:** New middleware `App\Http\Middleware\EnsureDkBankEnabled` (alias `dk.enabled` in
`bootstrap/app.php`) that `abort(404)` when the flag is false. Apply it to the DK route group in
`routes/web.php` (the four `dk-qr.*` routes). **404, not 403**, so a disabled feature stays invisible.

### 1.2 DK RRN validation (correctness/usability)

**Problem:** `verifyRrn` validates `rrn => 'required|alpha_num|min:4|max:32'`. The integration
reference (§9) warns real bank RRNs contain hyphens/slashes (e.g. `SEL-2309203`), so legitimate
cross-bank payers are rejected before reaching DK. The service already `strtoupper(trim())`s the
value, so loosening the rule is safe.

**Fix:** `rrn => ['required', 'string', 'regex:/^[A-Za-z0-9\/\- ]{4,32}$/']`. (Max length is
**32**, not 40, to match the `dk_rrn` `VARCHAR(32)` column — a longer value would risk MySQL
error 1406 in strict mode.)

### 1.3 DK credit-account match (correctness)

**Problem:** Both verify paths compare `(string) $status['credit_account'] !== (string)
config('beneficiary_account')`. The doc warns DK may return a masked/reformatted account, which
would false-reject genuine payments.

**Fix:** Extract a private `creditAccountMatches(string $reported): bool` helper. Add config
`services.dk_bank.account_match` (`exact` | `suffix`, default **`exact`** — no behavior change
until DK confirms masking). Normalize both sides (strip spaces, uppercase) before comparing;
`suffix` mode compares the last N (config `account_match_digits`, default 4) digits.

### 1.4 DK MCC code (config hygiene)

**Problem:** `mcc_code` defaults to `5817`; DK may require `5734`. It is already config-driven.

**Fix:** No logic change. Confirm env override path works, document the choice in `.env.example`
and the deploy runbook (§ Deploy). Add an assertion test that `startQrSession` sends the configured
MCC.

### 1.5 DK status-envelope parsing (robustness)

**Problem:** `extractPaidStatusData()` reads `response_data.status` (object shape). The doc flags
that DK has also been seen returning `response_data[0].status` (array-indexed); the indexed shape
silently yields `null` and the payment never flips to paid.

**Fix:** Defensive parse — try `response_data.status`, fall back to `response_data[0].status`.
Unit test both shapes plus the "neither present" case.

### 1.6 Widget JWT strict-mode hardening (security)

**Problem:** `config('widget.session_dual_accept')` defaults `false`, but `.env.example:107` ships
`WIDGET_SESSION_DUAL_ACCEPT=true`. When true, `RequireWidgetSessionToken` passes token-less
requests through (api_key + domain only) with a `Deprecation` header — the widget write surface is
reachable without a JWT.

**Fix (code only; no live flip):**
- `.env.example` → `WIDGET_SESSION_DUAL_ACCEPT=false`, with a comment that the strict flip requires
  `TRUSTED_PROXIES` to be set first (else IP-binding/throttles collapse to the proxy IP).
- **Passthrough telemetry:** when dual-accept passes a token-less request, increment a cache
  counter (`widget_dual_accept_passthrough`) and `WidgetAudit` it — mirrors the existing
  `widget_audit_failures` pattern — so ops can see when zero passthroughs occur and the live flip
  is safe.
- **Strict-mode test:** with `session_dual_accept=false`, a token-less `POST /api/v1/widget/message`
  returns 401 `session_token_required`.

### 1.7 Crawler SSRF gap on manual add (security)

**Problem:** `DocumentProcessor::fetchUrl` uses raw `Http::timeout(30)->withOptions(['allow_redirects'
=> false])` + name-time `SafeExternalUrl::isSafe` — **no `CURLOPT_RESOLVE` IP-pinning**, so the
manual single-URL "add webpage" path is DNS-rebind-vulnerable, unlike the bulk crawler.

**Fix:** Inject `GuardedHttpClient` into `DocumentProcessor`; replace the raw call with
`$this->http->get($url)->body()` (the same IP-pinned, redirect-revalidating client the crawler
uses). Keep the early `SafeExternalUrl::isSafe` guard as cheap pre-validation. Update the
`DocumentProcessor` constructor and its container resolution; fix any test instantiation.

### 1.8 Lead list authorization (security)

**Problem:** `Client\LeadController::index()` has no `authorize()` call; every other method gates on
`Ability::ManageLeads`. It relies entirely on route-group middleware.

**Fix:** Add `$this->authorize(Ability::ManageLeads->value)` as the first line of `index()`.

---

## PR 2 — Correctness, dead-code & infra

Branch: `fix/tech-debt-cleanup`, off `main` after PR 1 merges.

### 2.1 Lead scoring threshold — single source of truth

**Problem:** `LeadScoring::temperature()` uses hot≥61 / warm≥31; `AnalyticsService` hardcodes
hot≥70 / warm 40–69 / cold<40 and `high_quality ≥70`. The dashboard "hot" count disagrees with the
`temperature()` label for scores 61–69.

**Fix:** Add `LeadScoring::HOT_THRESHOLD = 70` and `WARM_THRESHOLD = 40` constants;
`temperature()` uses them (canonical hot≥70 / warm≥40). Refactor `AnalyticsService::
{getLeadScoreDistribution, getStats}` to consume the constants (or call `temperature()`), removing
the duplicated literals. Update the affected analytics tests for the new buckets.

### 2.2 Admin audit-log writer

**Problem:** `AdminActivityLog::log()` has zero callers — the Activity Logs page is always empty.

**Fix:** Call `AdminActivityLog::log()` (wrapped best-effort: `try/catch` + `Log::warning`, so an
audit failure never breaks the admin action — mirrors `WidgetAudit`'s never-throw posture) at the
admin mutation chokepoints:

| Controller::method | action_type |
| --- | --- |
| `TransactionController::approve` / `reject` | `approve_transaction` / `reject_transaction` |
| `ClientController::updateStatus` / `updatePlan` / `updateBotPersonality` / `restore` | `update_client_status` / `update_client_plan` / `update_client_bot_personality` / `restore_client` |
| `PlanController::store` / `update` / `toggleStatus` | `create_plan` / `update_plan` / `toggle_plan` |
| `EnterpriseInquiryController::update` | `update_inquiry` |

Extend `getActionLabelAttribute`'s label map for the new action types. (Login/logout auditing is
deferred — those happen outside the admin route group.)

### 2.3 Admin client restore/sort UI

**Problem:** `ClientController` supports `trashed` filter, `sort`/`direction`, and `restore` — but
`Clients/Index.vue` only sends `search/status/plan` and renders no restore button or sort headers,
so the features are unreachable.

**Fix (frontend only; backend already done):** In `Clients/Index.vue` add a trashed filter
toggle (active / with / only), sortable column headers driving `sort`+`direction`, and a Restore
button on trashed rows (`router.post(route('admin.clients.restore', id))`). Wire all into
`applyFilters` and the `watch` list.

### 2.4 Test bootstrap portability

**Problem:** `tests/bootstrap.php:12` hardcodes `'/Users/sam/Dev/laravel/chatbot/vendor'`, breaking
every other checkout/CI.

**Fix:** Resolve the main-repo vendor dynamically — `git rev-parse --git-common-dir` → parent dir →
`/vendor` — and cleanly **no-op on a normal (non-worktree) checkout or when the path doesn't exist**,
falling back to the worktree's own vendor. The worktree-isolation behavior is preserved; the absolute
path is removed.

### 2.5 Stream retry parity

**Problem:** `ChatService::generateResponse` wraps the provider call in `retry(3)` backoff;
`streamResponse` has no retry.

**Fix:** Wrap the streamed provider dispatch in the same retry/backoff (retry only on
429/500/503/connection/timeout). Verify streaming semantics are preserved (retry wraps the
connection establishment, not mid-stream chunks).

### 2.6 Deploy runbook

**Problem:** Ops gaps are tracked only in agent memory: the `widget_audit_failures` monitor, the
new dual-accept passthrough counter, the egress-proxy prod process manager (no in-repo unit), and
carried-over pending deploys (free-plan lifecycle migrate/scheduler/queue, post-scraping re-crawl).

**Fix:** A `docs/superpowers/security-hardening-deploy.md` runbook consolidating: the two widget
monitors + alert thresholds, a sample systemd/supervisor unit for `egress-proxy.mjs`, the
`TRUSTED_PROXIES` + dual-accept live-flip sequence, and the MCC/credit-account config decisions to
confirm with DK.

---

## File map

**PR 1**
- `app/Http/Middleware/EnsureDkBankEnabled.php` *(new)*
- `bootstrap/app.php` — register `dk.enabled` alias
- `routes/web.php` — apply `dk.enabled` to DK route group
- `app/Http/Controllers/Client/DkBankQrController.php` — RRN regex
- `app/Services/Payment/DkBank/DkBankQrService.php` — `creditAccountMatches()`, defensive `extractPaidStatusData()`
- `config/services.php` — `account_match`, `account_match_digits`
- `app/Http/Middleware/RequireWidgetSessionToken.php` — passthrough telemetry
- `.env.example` — dual-accept=false + TrustProxies/MCC comments
- `app/Services/Knowledge/DocumentProcessor.php` — inject `GuardedHttpClient`
- `app/Http/Controllers/Client/LeadController.php` — `index()` authorize
- Tests: DK killswitch 404, RRN hyphen, credit-account suffix, envelope shapes, MCC sent, widget strict-mode 401 + passthrough counter, DocumentProcessor guarded fetch, lead index 403

**PR 2**
- `app/Services/Leads/LeadScoring.php` — threshold constants
- `app/Services/Analytics/AnalyticsService.php` — consume constants
- `app/Models/AdminActivityLog.php` — label map additions
- `app/Http/Controllers/Admin/{Transaction,Client,Plan,EnterpriseInquiry}Controller.php` — audit calls
- `resources/js/Pages/Admin/Clients/Index.vue` — restore/sort/trashed UI
- `tests/bootstrap.php` — dynamic vendor resolution
- `app/Services/LLM/ChatService.php` — stream retry
- `docs/superpowers/security-hardening-deploy.md` *(new)*

---

## Out of scope (deliberate)

- **DK end-to-end production verification** — blocked on DK answering the open questions (RRN T+1
  settlement, masked credit_account, production MCC/BASE_URL/api-key). Killswitch keeps it dark.
- **Live prod flip of `dual_accept`** — ops action, gated on `TRUSTED_PROXIES` being configured.
- **pgvector retrieval in the SQLite test path** — large; keyword-fallback coverage stays as-is.
- **`probeHeaders` ETag-OR-LastModified refinement** — minor; defer to a future crawler PR.
- **Login/logout admin auditing** — outside the admin route group; revisit if needed.

---

## Test strategy

Three layers (per CLAUDE.md):
1. **TDD per task** — failing test first, then implementation.
2. **Full suite between tasks** — `php artisan test` (not feature-scoped) to catch regressions.
3. **Browser smoke before each PR** — PR 1: widget strict-mode rejection + DK-disabled 404;
   PR 2: admin restore/sort/trashed flow + an audit row appearing in the Activity Logs page.

Pint (`./vendor/bin/pint --test` → fix → commit) then `/simplify`, run twice interleaved, before
each PR. PHPStan baseline must stay at zero.
