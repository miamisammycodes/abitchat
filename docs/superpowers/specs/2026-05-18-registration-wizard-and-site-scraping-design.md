# Registration Wizard + Website Auto-Indexing

**Date**: 2026-05-18
**Status**: Design (pre-implementation)
**Owner**: sameer@abit.bt
**Related**: Existing `RegisterController`, `DocumentProcessor`, `KnowledgeItem` pipeline, `UsageTracker`

---

## Problem

Today's registration is a single-page form (`Auth/Register.vue` → `RegisterController::store`) that creates a Tenant + Owner User with a 14-day trial, then drops the user on the dashboard. There is no on-ramp to populate the knowledge base — new tenants land with an empty bot that can answer nothing about their business.

We want a multi-step registration wizard that captures (optionally) the company's website URL, then auto-indexes the entire site so the chatbot can answer questions on day one. A daily scheduler keeps the index fresh.

The existing `DocumentProcessor::extractFromUrl()` fetches one URL at a time. We need a multi-page crawler that is polite (robots.txt, crawl-delay, rate-limited), budget-aware (respects plan limits), and resilient (partial-success states, retries, idempotent re-runs).

---

## Decisions Locked

| Decision | Choice | Rationale |
|---|---|---|
| V1 scope | All three pieces in one spec (wizard + crawler + scheduler) | Naturally coupled — wizard's payoff IS the crawl |
| Crawl strategy | `sitemap.xml` first, BFS fallback | Best coverage; degrades gracefully on sites without sitemaps |
| Crawl caps | 100 pages, depth 3, 5 MB/page | SMB-appropriate; ~$0.20-1 embedding cost worst case |
| Politeness | 1 req/sec per host, strict robots.txt + Crawl-delay | Avoid being flagged abusive |
| Registration submit | Validate format + 3s sync HEAD reachability check; crawl async | Fast registration, fail-fast on typos |
| Daily refresh | Diff-only via `Last-Modified` / `ETag` / content hash | Cheap (~$0.01-0.05/tenant/day); only re-embeds changed pages |
| KB integration | One `KnowledgeItem` per page, tagged with `crawl_session_id` in metadata | Reuses existing UI/model; per-page traceability |
| Wizard shape | 3 steps: Account → Company → Website (Skip button on step 3) | Cleanest progression; plan selection NOT in wizard (all → trial) |
| Post-reg edit | Settings page with manual re-crawl + auto-recrawl toggle | Self-serve; required for users who skipped step 3 |
| Failure UX | Registration always succeeds; crawl status surfaces in KB | Transient site issues don't lose signups |
| robots.txt | Strict (Disallow + Crawl-delay) | Ethical default; spec-locked |
| Ownership / abuse | Light-touch (URL format + reachability + caps + ToS clause); no DNS-TXT | Defer heavy verification to v2 |
| JS-rendered sites | No headless renderer in v1; surface as distinct "site appears empty" state | Out of scope for v1; explicit failure mode |
| Email verification | Out of scope (User does not implement `MustVerifyEmail`) | Matches current state |
| Queue | Dedicated `crawls` queue (Redis) | Don't starve embedding pipeline |

---

## Architecture

```
┌───────────────────────────────────────────────────────────────────────────┐
│  Wizard (Vue/Inertia: Auth/Register.vue)                                  │
│    Step 1: Account   →  Step 2: Company   →  Step 3: Website (optional)   │
│    Client-side multi-step; one POST /register at end                      │
└───────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌───────────────────────────────────────────────────────────────────────────┐
│  RegisterController::store (RegisterRequest)                              │
│    1. Validate fields (+ SafeExternalUrl rule on website_url)             │
│    2. If website_url present: 3s HEAD reachability check                  │
│    3. DB::transaction: Tenant + User created                              │
│    4. If website_url present + reachable: dispatch CrawlWebsiteJob        │
│    5. Auth::login + redirect /dashboard                                   │
└───────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌───────────────────────────────────────────────────────────────────────────┐
│  Queue: crawls (Redis)                                                    │
│    CrawlWebsiteJob(tenant, mode = initial|refresh|manual)                 │
│      ├─ Create crawl_sessions row (status = queued → running)             │
│      ├─ SitemapDiscoverer.discover(website_url) → ordered URL list        │
│      ├─ RobotsTxtPolicy.fetch(website_url) → Disallow + Crawl-delay       │
│      ├─ For each URL in list (≤100, depth ≤3, ≤5MB):                      │
│      │    ├─ Budget gate: canRecordUsage(KNOWLEDGE_ITEMS), canRec(TOKENS) │
│      │    │   → if exhausted: stop early, status=partial                  │
│      │    ├─ Normalize URL (strip fragment, tracking params, lowercase)   │
│      │    ├─ Skip if in session.excluded_urls (tenant-deleted)            │
│      │    ├─ HEAD → compare Last-Modified/ETag vs prior session           │
│      │    │   → unchanged: pages_skipped_unchanged++, continue            │
│      │    ├─ GET HTML (SafeExternalUrl SSRF re-check, 5MB cap)            │
│      │    ├─ Content hash compare → unchanged: skip                       │
│      │    ├─ Sleep crawl_delay (default 1s)                               │
│      │    └─ Upsert KnowledgeItem (find by tenant+url_normalized)         │
│      │         metadata = { crawl_session_id, url_normalized,             │
│      │                      content_hash, last_modified, etag }           │
│      │         → ProcessKnowledgeItem dispatched (existing pipeline)      │
│      ├─ Finalize: session.status = completed|partial|failed               │
│      └─ Counts: discovered, indexed, failed, skipped_budget, skipped_unch │
└───────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌───────────────────────────────────────────────────────────────────────────┐
│  ProcessKnowledgeItem (existing job, default queue)                       │
│    DocumentProcessor::process()  →  chunks  →  GenerateEmbeddings         │
└───────────────────────────────────────────────────────────────────────────┘

┌───────────────────────────────────────────────────────────────────────────┐
│  Scheduler (routes/console.php)                                           │
│    Schedule::command('crawls:refresh-all')->daily()                       │
│      └─ Iterates Tenant::active()                                         │
│              ->whereNotNull('website_url')                                │
│              ->where('auto_recrawl', true)                                │
│         → dispatches CrawlWebsiteJob($tenant, mode='refresh')             │
└───────────────────────────────────────────────────────────────────────────┘
```

---

## Components

### `App\Services\Crawler\SiteCrawler` (service, orchestrator)
- **Purpose**: end-to-end crawl orchestration for one tenant + session
- **Public surface**: `crawl(Tenant $tenant, CrawlSession $session): void`
- **Deps**: `SitemapDiscoverer`, `RobotsTxtPolicy`, `UrlNormalizer`, `UsageTracker`, `SafeExternalUrl`
- **Owns**: per-page loop, budget gating, hash compare, KB upsert, session finalization

### `App\Services\Crawler\SitemapDiscoverer`
- **Purpose**: produce the prioritized list of URLs to fetch
- **Surface**: `discover(string $rootUrl): iterable<string>`
- **Strategy**: try `/sitemap.xml` → try sitemaps referenced from `/robots.txt` → fall back to BFS from `$rootUrl` (same-origin only, depth 3)
- **Output**: deduplicated, normalized URLs ordered by sitemap priority (or BFS order)

### `App\Services\Crawler\RobotsTxtPolicy`
- **Purpose**: per-host robots.txt enforcement
- **Surface**: `fetchFor(string $rootUrl): RobotsPolicy` returning `isAllowed(string $url): bool` and `crawlDelaySeconds(): int`
- **User-agent**: `ChatbotIndexer/1.0`
- **Default crawl-delay**: 1 second if robots specifies none

### `App\Services\Crawler\UrlNormalizer`
- **Purpose**: canonical-form URL string for dedup + diff
- **Rules**: lowercase host; strip fragment (`#…`); strip tracking params (`utm_*`, `fbclid`, `gclid`, `ref`, `_ga`, `mc_eid`, `mc_cid`); collapse `/` trailing slash on root paths; remove default ports
- **Surface**: `normalize(string $url): string`

### `App\Jobs\CrawlWebsiteJob`
- **Connection**: Redis, queue=`crawls`
- **Tries**: 2 (low — most failures are not transient)
- **Backoff**: 300s
- **Constructor**: `(Tenant $tenant, string $mode = 'initial')`
- **Handle**: creates `CrawlSession`, delegates to `SiteCrawler::crawl()`, finalizes session
- **Failed**: marks session `failed` with exception message

### `App\Models\CrawlSession`
- Tenant-scoped via `BelongsToTenant`
- Statuses (enum `App\Enums\CrawlSessionStatus`): `Queued`, `Running`, `Completed`, `Partial`, `Failed`
- Modes (enum `App\Enums\CrawlMode`): `Initial`, `Refresh`, `Manual`
- Belongs to many `KnowledgeItem` indirectly via `metadata.crawl_session_id`

### `App\Console\Commands\RefreshAllCrawls`
- Signature: `crawls:refresh-all`
- Iterates eligible tenants, chunked (100 at a time) to avoid memory blow-up
- Dispatches one `CrawlWebsiteJob` per tenant onto the `crawls` queue
- Skips tenants with an in-flight crawl (status `Queued` or `Running`) from the past 6 hours — prevents pile-up if a refresh stalls

### `App\Http\Controllers\Auth\RegisterController` (modified)
- `create()` now passes step config to Inertia (no behavior change today; data prepared for wizard)
- `store(RegisterRequest)`:
  1. Validate (RegisterRequest now includes optional `website_url`)
  2. If `website_url` provided: HEAD with 3s timeout via `Http::timeout(3)->head($url)` inside `try`; on failure throw `ValidationException` keyed to step 3
  3. Create Tenant (including `website_url`, `auto_recrawl=true`) + Owner User in transaction
  4. If website provided + reachable: `CrawlWebsiteJob::dispatch($tenant, 'initial')`
  5. `Auth::login` + redirect to dashboard with flash `'website_indexing_started' => true`

### `App\Http\Controllers\Client\WebsiteIndexingController` (new)
- `update(UpdateWebsiteIndexingRequest)`: set/change/clear `website_url`, toggle `auto_recrawl`
- `recrawl()`: dispatch manual `CrawlWebsiteJob` (mode=`manual`); enforce 1/hour cooldown via DB check on `crawl_sessions.started_at`
- Routes mounted under existing `widget-settings` namespace

### Frontend: `resources/js/Pages/Auth/Register.vue` (rewrite to wizard)
- `useForm` holds all fields across steps
- Local `currentStep` ref (1 | 2 | 3); back/next buttons with step-validity gates
- Step indicator (`Step 2 of 3`)
- Skip button on step 3 clears `website_url` and submits
- All errors from server snap form to offending step

### Frontend: `resources/js/Pages/Client/Dashboard.vue` (additive)
- Indexing-status banner reads from new shared prop `tenant.latest_crawl_session` (HandleInertiaRequests middleware addition)
- States: indexing / completed / partial / failed; CTAs link to Knowledge Base filtered by `?crawl_session_id=…`

### Frontend: `resources/js/Pages/Client/WidgetSettings.vue` (additive section)
- "Website indexing" card: URL input, auto-recrawl toggle, "Re-crawl now" button (disabled during cooldown), last session summary

### Frontend: `resources/js/Pages/Client/KnowledgeBase/Index.vue` (additive)
- Filter chip: `crawl_session_id`
- Group header when filtered: "Website: example.com — N pages indexed on YYYY-MM-DD"

---

## Data Model

### Migration: `add_website_url_and_auto_recrawl_to_tenants_table`
```php
$table->string('website_url', 2048)->nullable()->after('domain');
$table->boolean('auto_recrawl')->default(true)->after('website_url');
```
**Note**: `website_url` is distinct from existing `domain` column. `domain` is the unique widget-embed origin; `website_url` is the company URL we crawl. They MAY match but conceptually serve different purposes.

### Migration: `create_crawl_sessions_table`
```php
Schema::create('crawl_sessions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->string('mode'); // initial | refresh | manual
    $table->string('status'); // queued | running | completed | partial | failed
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->unsignedInteger('pages_discovered')->default(0);
    $table->unsignedInteger('pages_indexed')->default(0);
    $table->unsignedInteger('pages_failed')->default(0);
    $table->unsignedInteger('pages_skipped_budget')->default(0);
    $table->unsignedInteger('pages_skipped_unchanged')->default(0);
    $table->text('error_message')->nullable();
    $table->json('excluded_urls')->nullable(); // tenant-deleted URLs not to re-create
    $table->timestamps();

    $table->index(['tenant_id', 'created_at']);
    $table->index(['tenant_id', 'status']);
});
```

### Schema reuse: `knowledge_items.metadata` (no migration)
New standardized keys when `type = 'webpage'` and the item was crawler-created:
```json
{
  "crawl_session_id": 42,
  "url_normalized": "https://example.com/about",
  "content_hash": "sha256:…",
  "last_modified": "Wed, 12 Mar 2025 10:00:00 GMT",
  "etag": "W/\"abc123\""
}
```
Crawler upserts by `(tenant_id, type=webpage, metadata->>'url_normalized')`.

### Schema reuse: `knowledge_items.source_url` (existing column)
The crawler stores the original (pre-normalize) URL in `source_url` for display; `metadata.url_normalized` is the dedup key.

---

## Validation Rules

### `RegisterRequest` additions
```php
'website_url' => [
    'nullable',
    'url:http,https',
    'max:2048',
    new SafeExternalUrl,
],
```

### `UpdateWebsiteIndexingRequest` (new)
```php
'website_url' => ['nullable', 'url:http,https', 'max:2048', new SafeExternalUrl],
'auto_recrawl' => ['required', 'boolean'],
```

### Submit-time reachability
Inside `RegisterController::store` after validation, if `website_url` present:
```php
try {
    $response = Http::timeout(3)->withHeaders([
        'User-Agent' => 'ChatbotIndexer/1.0',
    ])->head($request->website_url);
    if ($response->status() >= 400 && $response->status() !== 405) {
        throw ValidationException::withMessages([
            'website_url' => ['We couldn\'t reach this site. Check the URL or skip this step.'],
        ]);
    }
} catch (ConnectionException $e) {
    throw ValidationException::withMessages([
        'website_url' => ['We couldn\'t reach this site. Check the URL or skip this step.'],
    ]);
}
```
Note: 405 (Method Not Allowed) is accepted as a valid "site exists" signal — many origins block HEAD but accept GET.

---

## Budget / Limits / Abuse

### Per-crawl budget gate
Every loop iteration in `SiteCrawler`:
```php
if (! $this->usageTracker->canRecordUsage($tenant, UsageTracker::TYPE_KNOWLEDGE_ITEMS)) {
    $session->pages_skipped_budget++;
    break; // hard stop — no point continuing
}
if (! $this->usageTracker->canRecordUsage($tenant, UsageTracker::TYPE_TOKENS)) {
    $session->pages_skipped_budget++;
    break;
}
```
If we exit the loop with `pages_skipped_budget > 0`, session status becomes `partial`.

### Manual re-crawl cooldown
`WebsiteIndexingController::recrawl` rejects if a session in any mode exists with `started_at > now()->subHour()`. Returns 429 with flash message "Please wait — last crawl started less than an hour ago."

### Scheduled refresh exempt from cooldown
The 1-hour cooldown applies only to manual triggers. Scheduled `refresh` mode runs always.

### Concurrency
`RefreshAllCrawls` chunks tenants by 100 and dispatches sequentially. The `crawls` queue runs with a configured worker concurrency (default 4 — to be tuned in operations doc, not in spec).

### Skip-if-in-flight check
`RefreshAllCrawls` skips any tenant with `CrawlSession::where('status', 'IN', ['queued', 'running'])->where('created_at', '>', now()->subHours(6))->exists()`. The 6-hour window auto-expires stuck jobs from blocking forever.

---

## Daily Refresh — Diff Logic

For each URL in the prioritized list:

1. **HEAD pre-check**: fetch `Last-Modified` and `ETag` headers.
2. **Find prior item**: `KnowledgeItem::forTenant($tenant)->where('type','webpage')->where('metadata->url_normalized', $normalized)->first()`.
3. **If prior exists**:
   - If `If-Modified-Since: prior.metadata.last_modified` returns 304: **skip** (pages_skipped_unchanged++).
   - If header `Last-Modified == prior.metadata.last_modified` AND `ETag == prior.metadata.etag`: **skip**.
4. **GET HTML**. Compute `sha256(html_body)`.
5. **If hash unchanged**: update `metadata.last_modified` and `metadata.etag` only, don't re-chunk/re-embed. (pages_skipped_unchanged++).
6. **Else**: update `content`, recompute chunks, dispatch `ProcessKnowledgeItem` → re-embed. Update all metadata fields. (pages_indexed++).

URLs in `crawl_session.excluded_urls`: skipped silently (no count).

---

## Failure States — User-Facing

| State | Trigger | KB Banner |
|---|---|---|
| `Queued` | Job dispatched, not yet running | "Indexing your site… we'll let you know when it's done." |
| `Running` | `SiteCrawler::crawl()` mid-loop | "Indexing in progress — X of Y pages…" (refresh every 30s via Inertia partial reload) |
| `Completed` | All pages processed within budget | "Indexed N pages from example.com." (dismissible) |
| `Partial — budget` | Budget hit mid-crawl | "Indexed N of M pages — upgrade your plan to crawl more." |
| `Partial — empty site` | All pages returned <50 chars extracted text | "Your site appears to require JavaScript to render. v1 can only index static content. Contact support if you need help." |
| `Partial — robots-restricted` | Some pages blocked by robots.txt | "Indexed N pages; M pages disallowed by your site's robots.txt." |
| `Failed — unreachable` | Initial HTTP fetch failed | "Couldn't reach your site. [Retry]" |
| `Failed — robots-block-all` | robots.txt disallows all paths | "Your site's robots.txt blocks indexing. [Edit URL] [Contact support]" |
| `Failed — exception` | Unhandled error in job | "Indexing failed. [Retry]" with truncated error |

---

## Testing

### Unit tests
- `UrlNormalizerTest`: 12+ cases (fragments, tracking params, trailing slash, case, ports, query order)
- `RobotsTxtPolicyTest`: Disallow precedence, multiple user-agents, Crawl-delay parsing, missing robots.txt → permissive default
- `SitemapDiscovererTest`: `/sitemap.xml` happy path, robots-referenced sitemaps, BFS fallback, depth cap, same-origin enforcement, dedup
- `CrawlSessionTest`: state transitions enum guard

### Service tests (with HTTP fakes)
- `SiteCrawlerTest`:
  - Happy path: 10-URL sitemap → 10 KnowledgeItems created
  - Diff refresh: prior session with hashes → 8 skipped unchanged, 2 re-embedded
  - Budget hit at page 7 of 10 → status=partial, pages_skipped_budget=3
  - robots.txt blocks 3 of 10 → 7 indexed, 3 pages_failed (robots-block reason)
  - Empty extraction (all pages return < 50 chars after strip) → status=partial-empty
  - excluded_urls honored: prior crawl excluded `/admin`, current run skips it
  - Crawl-delay respected: between-request sleep observed
  - SafeExternalUrl SSRF rejected mid-crawl (sitemap pointed to `/internal`)

### Feature tests
- `RegistrationWizardTest`:
  - Wizard with all 3 steps submits successfully (smoke)
  - Step 3 skip: no `website_url` saved, no crawl dispatched
  - Step 3 with unreachable URL → validation error, no Tenant created (rollback)
  - Step 3 with valid URL → Tenant created, `website_url` set, `CrawlWebsiteJob` queued
  - SafeExternalUrl rejection (e.g. `http://localhost`) blocked at validation
- `WebsiteIndexingControllerTest`:
  - Update URL → new manual session dispatched
  - Manual re-crawl within cooldown → 429
  - Manual re-crawl after cooldown → succeeds
  - Toggle `auto_recrawl=false` → next scheduler tick skips tenant
- `RefreshAllCrawlsCommandTest`:
  - Active tenants with `website_url` + `auto_recrawl=true` get jobs dispatched
  - Tenants with in-flight crawls skipped
  - Cancelled / suspended tenants skipped
  - Chunked dispatch (100 at a time) doesn't OOM with 10k tenants

### Browser smoke (manual, pre-PR)
- Fresh registration with real test URL (e.g. `https://example.com`)
- KB page shows progress banner → transitions to completed
- Filter chip by `crawl_session_id` shows the crawled pages
- Settings page: toggle off auto-recrawl, manual re-crawl button enforced cooldown

---

## File Map

### Migrations (database/migrations/)
- `2026_05_18_000001_add_website_url_and_auto_recrawl_to_tenants_table.php`
- `2026_05_18_000002_create_crawl_sessions_table.php`

### Models
- `app/Models/CrawlSession.php` (new)
- `app/Models/Tenant.php` (add `website_url`, `auto_recrawl` to `$fillable`)

### Enums
- `app/Enums/CrawlSessionStatus.php` (new)
- `app/Enums/CrawlMode.php` (new)

### Services (new)
- `app/Services/Crawler/SiteCrawler.php`
- `app/Services/Crawler/SitemapDiscoverer.php`
- `app/Services/Crawler/RobotsTxtPolicy.php`
- `app/Services/Crawler/RobotsPolicy.php` (DTO returned by RobotsTxtPolicy::fetchFor)
- `app/Services/Crawler/UrlNormalizer.php`

### Jobs / Commands
- `app/Jobs/CrawlWebsiteJob.php` (new)
- `app/Console/Commands/RefreshAllCrawls.php` (new)

### Controllers
- `app/Http/Controllers/Auth/RegisterController.php` (modified)
- `app/Http/Controllers/Client/WebsiteIndexingController.php` (new)

### Requests
- `app/Http/Requests/Auth/RegisterRequest.php` (add `website_url`)
- `app/Http/Requests/Client/UpdateWebsiteIndexingRequest.php` (new)

### Middleware
- `app/Http/Middleware/HandleInertiaRequests.php` (share `latest_crawl_session` when user is auth'd)

### Routes
- `routes/web.php` (add `/widget-settings/website-indexing` PATCH + `/recrawl` POST inside auth group)
- `routes/console.php` (add `Schedule::command('crawls:refresh-all')->daily()`)

### Frontend
- `resources/js/Pages/Auth/Register.vue` (rewrite to wizard)
- `resources/js/Pages/Client/Dashboard.vue` (add banner)
- `resources/js/Pages/Client/WidgetSettings.vue` (add Website indexing section)
- `resources/js/Pages/Client/KnowledgeBase/Index.vue` (add session filter)
- `resources/js/Components/IndexingStatusBanner.vue` (new shared component)

### Tests
- `tests/Unit/Services/Crawler/UrlNormalizerTest.php`
- `tests/Unit/Services/Crawler/RobotsTxtPolicyTest.php`
- `tests/Unit/Services/Crawler/SitemapDiscovererTest.php`
- `tests/Unit/Services/Crawler/SiteCrawlerTest.php`
- `tests/Unit/Models/CrawlSessionTest.php`
- `tests/Feature/Auth/RegistrationWizardTest.php`
- `tests/Feature/Client/WebsiteIndexingControllerTest.php`
- `tests/Feature/Console/RefreshAllCrawlsCommandTest.php`

### Documentation
- `CONTEXT.md` — add `CrawlSession` to domain glossary
- README/CHANGELOG entry on widget setup flow (existing onboarding docs)

---

## Out of Scope (explicit)

These are intentionally NOT in v1. Defer to follow-up specs:

1. **Headless browser rendering** for JS-hydrated sites (Webflow/Wix/Squarespace). v1 surfaces "site appears empty" as a distinct failure state.
2. **DNS-TXT ownership verification** of the submitted website URL. v1 relies on URL validation + caps + ToS clause.
3. **Tenant-configurable crawl cadence**. v1 is daily-only.
4. **Plan selection in the wizard**. All signups land on 14-day trial; existing Billing page handles plan upgrades.
5. **Email verification at registration**. `User` does not implement `MustVerifyEmail` today.
6. **Cross-origin / sub-domain crawling**. v1 is same-origin only.
7. **PDF / document fetching from the website**. v1 indexes HTML pages only.
8. **Per-tenant or per-plan caps that differ from the global 100-page limit**. v1 has one global cap.
9. **Resume-from-failure for partial crawls**. v1 retries are full-restart.
10. **Webhook / email notification on crawl completion**. v1 surfaces via dashboard banner only.

---

## Open Operational Questions (not blockers for v1)

To resolve before deploying, but not blockers for code:

- Queue worker concurrency for the `crawls` queue (suggest start at 4, monitor and tune)
- Per-host or global rate ceiling for crawler (suggest 1 req/s per host already implemented; revisit if a single tenant has 1000s of small-host crawls)
- Production user-agent string final wording (legal/ToS review of `ChatbotIndexer/1.0`)
- Whether to expose the indexed page list as exportable (CSV) — defer until tenants ask
