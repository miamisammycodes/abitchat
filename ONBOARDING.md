# AbitChat — Onboarding

AI-powered WordPress chatbot SaaS. Multi-tenant, RAG-backed, with a widget API and a client + admin dashboard. Built on Laravel 13 + Vue 3 (Inertia/VILT).

## Read these in order

| File | What it gives you |
|---|---|
| `README.md` | One-paragraph pitch + setup commands |
| `CLAUDE.md` | Project rules: coding standards, test credentials, dev URLs, the brainstorm→spec→plan→subagent feature-dev flow, security/legacy-cleanup rules |
| `CONTEXT.md` | Domain glossary — every load-bearing term used by the codebase and ADRs |
| `prd.md` | Product requirements |
| `ROADMAP.md` | Build status |
| `docs/superpowers/specs/` | Every shipped feature has a dated design doc here (registration wizard, DK Bank QR, prompt injection, etc.) |
| `docs/superpowers/plans/` | Companion TDD plans that drove each implementation |
| `docs/superpowers/notes/` | Ad-hoc UAT probe results, vendor message drafts, etc. |

Read CLAUDE.md and CONTEXT.md before touching code. They're the "this is how we work" and "this is what each thing means" anchors.

## Stack

- **Backend**: Laravel 13+, PHP 8.3+, MySQL 8 + SQLite-vec (vectors), Redis
- **Frontend**: Vue 3 Composition API + Inertia.js + Tailwind v4 + shadcn/ui components
- **Multi-tenancy**: Spatie Laravel Multitenancy — single DB, `tenant_id` column on tenant-scoped tables
- **LLM**: Prism — Ollama (`gemma3:4b`) for dev, Groq (`llama-3.1-8b-instant`) for prod
- **Payments**: Laravel Cashier (Stripe) + custom DK Bank QR flow for Bhutan
- **Tests**: Pest (Pint for style, PHPStan/Larastan for static analysis — baseline is zero)

## Local setup

```bash
composer install && npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

Then in three terminals (or via your favorite multiplexer):

```bash
php artisan serve --port=8001          # http://127.0.0.1:8001
npm run dev                            # Vite on :5173
php artisan queue:work --queue=crawls,default
```

The queue worker is **required** for any flow that dispatches a job (registration with website URL, manual recrawl, DK QR polling, knowledge-item processing). If your local `.env` has `QUEUE_CONNECTION=sync`, change it to `database` — sync forces jobs to run inside the HTTP request and hangs registration for the full crawl duration.

### Test credentials (per CLAUDE.md)

- Client: `test@example.com` / `password`
- Admin: `admin@example.com` / `password`
- Widget test page: http://127.0.0.1:8001/widget/test.html

## Mental model

```
Marketing (public) → Auth → /dashboard (tenant-scoped) or /admin/* (platform-scoped)
                              └→ widget API at /api/v1/widget/* (api-key auth)
```

- **Tenant-scoped tables**: every model with a `tenant_id` uses the `BelongsToTenant` trait — provides `forTenant(Tenant)` scope and auto-stamps `tenant_id` on create from `Auth::user()->tenant_id`. **Larastan rule blocks raw `where('tenant_id', ...)` outside the trait** — everything goes through `forTenant`.
- **Single owner per concept**: see `CONTEXT.md` for the "this Module owns X" rules. UsageTracker owns all usage decisions; LeadScoring owns score computation; KnowledgeItemWorkflow owns KB status transitions; DocumentProcessor owns "URL/file → text → chunks"; KnowledgeCache owns RAG retrieval caching. Don't bypass these.
- **Crawler**: `SiteCrawler` orchestrates per-tenant crawls; `SitemapDiscoverer` produces the URL list (sitemap-first, BFS fallback); `RobotsTxtPolicy` enforces robots.txt + Crawl-delay; `CrawlWebsiteJob` runs the whole thing on the dedicated `crawls` queue. Daily refresh via `crawls:refresh-all` scheduled command. See spec `2026-05-18-registration-wizard-and-site-scraping-design.md`.
- **DK Bank QR**: alternative-to-Stripe payment for Bhutan. End-to-end DK→DK auto-verification is empirically validated against real UAT payments (Nu. 6 and Nu. 100 both auto-confirmed). Behind `DK_BANK_ENABLED` killswitch (default `false` in prod, flip when ready). Flow: `Subscribe.vue` → POST `dk-qr.start` → `DkBankQrController::start` creates Transaction + calls DK `/v1/generate_qr` + stores QR base64 → **302 redirects to GET `dk-qr.show`** → `DkQrSession.vue` renders + polls `dk-qr.status` every 3s → on `state: paid`, redirects to `/billing`. Service: `app/Services/Payment/DkBank/`. Spec: `2026-05-15-dk-bank-qr-design.md`.

### Local email (Mailpit)

This project sends transactional email via Resend in production and Mailpit (a local SMTP catcher) in development.

**Install via Homebrew** (no Docker needed):
```bash
brew install mailpit
brew services start mailpit
```

**Or via Docker** (if you prefer):
```bash
docker run -d --name chatbot-mailpit --restart unless-stopped \
  -p 1025:1025 -p 8025:8025 axllent/mailpit
```

Mail UI: http://localhost:8025

Verify with:
```bash
php artisan tinker --execute='\Mail::raw("test", fn ($m) => $m->to("test@example.com")->subject("Test"));'
```

## Sharp edges (things that bit us)

1. **`QUEUE_CONNECTION=sync`** — kills the registration UX because the crawl runs inside the HTTP request. Use `database` locally with a `queue:work` running, or `redis` (matches prod).
2. **`dispatchAfterResponse()`** — runs sync under any driver per vendor source. Documented as a landmine in memory. Don't use it to "speed things up."
3. **Tenant slug** is auto-set in the model boot hook from `tenant.name`. Don't set it manually in controllers.
4. **DK API response shape** for `/v1/intra-transaction/status` is an *object* with `meta_info` + `status` siblings, NOT an indexed array. `DkBankQrService::extractPaidStatusData()` is the single parse point — don't re-roll it in callers. Test fixtures use the real `['response_data' => ['status' => [...]]]` shape; mirror that.
5. **DK QR wait-page URLs**: POST `/billing/dk-qr/{plan}` creates the transaction and 302-redirects to GET `/billing/dk-qr/transaction/{transaction}`. The page lives at the GET URL, so refresh and post-re-auth survive. The wait page polls; on 401 it does `window.location.reload()` to bounce through `/login` and back via Laravel's intended-URL machinery. Don't add another POST-only page URL — refresh will 405.
6. **Email registration** doesn't require verification (User does not implement `MustVerifyEmail`) — this is a deliberate spec decision, not an oversight.
7. **`Tenant::saved` hook** is the single source of truth for `tenant:{id}:with_plan` cache invalidation. Don't duplicate explicit `Cache::forget`.
8. **`BustsTenantUsageCache` trait** fires on every `created` event automatically. Don't manually call `forgetCache` in test setup — it's misleading belt-and-suspenders.
9. **`Transaction::$hidden`** strips `dk_qr_image_base64` (~16KB base64) from default JSON serialization so list payloads stay light. `DkBankQrController::show` reads the attribute directly and passes it as an explicit `qrImageBase64` prop — that path is unaffected. If you need it elsewhere, pass it explicitly, don't `makeVisible()` globally.

## How to ship a feature

CLAUDE.md has the full flow. Summary: **brainstorm → spec → plan → branch → subagent-execute (TDD) → smoke → pint → simplify → pint → simplify → PR → merge → memory**.

Skip the formal flow for one-line fixes, typos, copy tweaks, or pure refactors with no behavior change.

For anything non-trivial:
- Specs land in `docs/superpowers/specs/YYYY-MM-DD-<topic>-design.md`
- Plans land in `docs/superpowers/plans/YYYY-MM-DD-<topic>.md`
- Browser-smoke with Playwright before the PR — Pest verifies code correctness, the browser verifies the *feature*
- After both Pint passes and both `/simplify` passes, open the PR with the "Deploy steps + ⚠️ behavior changes + Test plan" template

## Commit + PR conventions

- Format: `type(scope): description` where type ∈ {feat, fix, refactor, test, docs, chore}
- All commits include `🤖 Generated with Claude Code` + `Co-Authored-By: Claude <noreply@anthropic.com>` trailer
- PR titles under 70 chars; bodies use the template above
- Standard merge commits (not squash) — see `git log` for examples

## Where to look when…

- **"Why does this auth flow do X?"** → `app/Http/Controllers/Auth/RegisterController.php`, spec `2026-05-18-registration-wizard-and-site-scraping-design.md`
- **"How does the widget talk to the backend?"** → `app/Http/Controllers/Api/V1/Widget/`, `app/Http/Middleware/ValidateWidgetDomain.php`
- **"How are LLM calls tracked for billing?"** → `app/Services/Usage/UsageTracker.php` + `UsageRecord` model
- **"How do leads get scored?"** → `app/Services/Leads/LeadScoring.php`
- **"Where do crawl pages become knowledge items?"** → `SiteCrawler::crawl` → KB upsert → dispatches `ProcessKnowledgeItem` → `DocumentProcessor::process` → `GenerateEmbeddings`
- **"What does an Inertia page share by default?"** → `app/Http/Middleware/HandleInertiaRequests.php` (tenant, auth, flash, usageWarnings, latest_crawl_session, dkBankEnabled)
- **"How do I trace a DK QR payment end-to-end?"** → `Subscribe.vue` (Generate QR button, gated by `dkBankEnabled` shared prop) → `DkBankQrController::start` → `DkBankQrService::startQrSession` (signed DK call + transaction row with QR base64) → redirect to `DkBankQrController::show` → `DkQrSession.vue` (renders QR, polls `dk-qr.status`) → `DkBankQrService::checkDkIntraStatus` → `interpretStatusResponse` (parses via `extractPaidStatusData`) → on Paid: `Transaction::approveAndActivate`, plan flips on. Probe script for UAT: `scripts/dk-probe.php`.
- **"How do I do a UAT probe against DK?"** → `php scripts/dk-probe.php 1 --reference 77000010 --save` (generates a QR PNG into `tmp/`); `php scripts/dk-probe.php 2 <reference>` to check status. Use `DK_BANK_BENEFICIARY_ACCOUNT=110131018624 …` env override to send to DK's test account.
