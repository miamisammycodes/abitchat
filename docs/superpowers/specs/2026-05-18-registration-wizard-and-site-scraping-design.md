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
| Registration submit | URL format validation only; crawl async; reachability surfaces in KB | Always-succeed registration trumps fail-fast typo detection. Transient 5xx during submit cannot lose a signup. |
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
│    2. DB::transaction: Tenant + User created                              │
│    3. If website_url present: dispatch CrawlWebsiteJob (no HEAD check)    │
│    4. Auth::login + redirect /dashboard                                   │
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
│      │    ├─ Skip if in tenant's crawl_url_blocklist (tenant-deleted)     │
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
- **Strategy**: try `/sitemap.xml` → try sitemaps referenced from `/robots.txt` → fall back to BFS from `$rootUrl` (same-host only, depth 3)
- **Same-host definition**: exact case-insensitive host match after normalization. `www.example.com` and `example.com` are treated as the same host (the `www.` prefix is stripped during host comparison). `blog.example.com` is NOT same-host and is excluded from v1 crawls. Different schemes (`http` vs `https`) are treated as the same host; the crawler normalizes to `https` if both are reachable.
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
- **Tenant-awareness**: `implements NotTenantAware` (matches existing `ProcessKnowledgeItem` pattern). All tenant scoping inside the job is explicit via `->forTenant($tenant)`.
- **Handle**: creates `CrawlSession` (status=`Running`), delegates to `SiteCrawler::crawl()`, finalizes session status
- **Retry semantics**: on `handle()` throw, the existing in-flight session is marked `Failed` and `handle()` re-runs on retry, creating a NEW session. Items created by the first attempt are diff-skipped (`content_hash` match) by the second attempt — safe and idempotent.
- **Failed (final, after all retries exhausted)**: marks the most recent session `Failed` with exception message; banner surfaces it

### `App\Models\CrawlSession`
- Tenant-scoped via `BelongsToTenant`
- Statuses (enum `App\Enums\CrawlSessionStatus`): `Queued`, `Running`, `Completed`, `Partial`, `Failed`
- Modes (enum `App\Enums\CrawlMode`): `Initial`, `Refresh`, `Manual`
- Belongs to many `KnowledgeItem` indirectly via `metadata.crawl_session_id`

### `App\Console\Commands\RefreshAllCrawls`
- Signature: `crawls:refresh-all`
- Eligible tenant query: `Tenant::where('status', 'active')->whereNotNull('website_url')->where('auto_recrawl', true)->chunkById(100, ...)` — uses `where('status','active')` directly (no `scopeActive` exists yet; spec deliberately avoids adding one for v1)
- Dispatches one `CrawlWebsiteJob` per tenant onto the `crawls` queue (mode=`refresh`)
- Skips tenants with an in-flight crawl: `CrawlSession::forTenant($t)->whereIn('status',['queued','running'])->where('created_at','>', now()->subHours(6))->exists()` — the 6-hour window auto-expires stuck jobs from blocking forever (a stuck job from yesterday becomes stale; tomorrow's refresh proceeds)

### `App\Http\Controllers\Auth\RegisterController` (modified)
- `create()` now passes step config to Inertia (no behavior change today; data prepared for wizard)
- `store(RegisterRequest)`:
  1. Validate (RegisterRequest now includes optional `website_url`, format-only — no network call)
  2. Create Tenant (including `website_url`, `auto_recrawl=true`) + Owner User in transaction
  3. If `website_url` provided: `CrawlWebsiteJob::dispatch($tenant, 'initial')` on the `crawls` queue
  4. `Auth::login` + redirect to dashboard with flash `'website_indexing_started' => true` when a crawl was queued
  - Note: reachability is intentionally NOT checked at submit. Unreachable / 5xx / DNS-resolved-private hosts are surfaced as KB banner states once the crawl job runs. The trade-off: a typo'd URL surfaces ~10-30 seconds later in the dashboard, not at submit. This is preferred to losing signups on transient network blips.

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
- Trial-cap hint inline on step 3: *"Free trial indexes up to 10 pages of your site. Upgrade to crawl your full site."* (the `10` value is rendered from a shared Inertia prop reading `config('billing.trial_limits.knowledge_items')` so we don't hardcode it in the template)

### Frontend: `resources/js/Pages/Client/Dashboard.vue` (additive)
- Indexing-status banner reads from new shared prop `latest_crawl_session`
- States: indexing / completed / partial / failed; CTAs link to Knowledge Base filtered by `?crawl_session_id=…`
- **Sharing strategy**: `HandleInertiaRequests::share()` returns `latest_crawl_session` only when the authenticated user is on routes whose name starts with `dashboard` OR `knowledge.*` OR `widget.*`. Avoids one extra query on every authenticated page load. Implementation: pass the route name (`$request->route()?->getName()`) into a tiny helper that decides whether to query. Per-request memoization in the helper prevents double-query if multiple components read the prop on the same render.

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
    $table->timestamps();

    $table->index(['tenant_id', 'created_at']);
    $table->index(['tenant_id', 'status']);
});
```

### Migration: `create_crawl_url_blocklist_table`

Tenant-scoped blocklist for URLs the tenant has explicitly removed. Persists across crawl sessions so the daily refresh doesn't re-create pages the tenant deleted.

```php
Schema::create('crawl_url_blocklist', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->string('url_normalized', 2048);
    $table->timestamp('excluded_at');
    $table->timestamps();

    $table->unique(['tenant_id', 'url_normalized']);
    $table->index('tenant_id');
});
```

**How entries are created**: when a tenant deletes a `KnowledgeItem` with `type = 'webpage'` and `metadata.crawl_session_id IS NOT NULL` from the KB UI, the destroy action (a) deletes the item AND (b) inserts a `crawl_url_blocklist` row keyed on `metadata.url_normalized`. The KB UI displays a confirmation dialog with the option "Allow re-indexing on next crawl?" (default unchecked = stays blocklisted). If the user opts to allow re-indexing, the blocklist row is NOT inserted.

**How entries are consumed**: `SiteCrawler::crawl` loads the blocklist as a `Set<string>` at session start; the per-URL loop skips any URL in the set without a count (silent skip — already accounted for at deletion time).

**How entries are cleared**: settings page exposes a "Removed pages" list per tenant with a per-row "Restore on next crawl" button that deletes the blocklist row. v1 has no bulk-clear UI.

### Migration: `add_crawl_columns_to_knowledge_items_table`

`url_normalized` is promoted to a top-level indexed column so the per-page upsert lookup runs ~100 times per crawl per tenant daily without table-scanning JSON. The remaining diff fields stay in `metadata` (read once per page, no index needed).

```php
$table->string('url_normalized', 2048)->nullable()->after('source_url');
$table->index(['tenant_id', 'type', 'url_normalized'], 'kn_items_tenant_type_norm_idx');
```

Crawler upsert key: `KnowledgeItem::forTenant($tenant)->where('type','webpage')->where('url_normalized',$normalized)->first()` — uses the new composite index.

### Schema reuse: `knowledge_items.metadata` (no schema change)

Standardized keys on crawler-created `type = 'webpage'` items (read on diff, not indexed):
```json
{
  "crawl_session_id": 42,
  "content_hash": "sha256:…",
  "last_modified": "Wed, 12 Mar 2025 10:00:00 GMT",
  "etag": "W/\"abc123\""
}
```

### Schema reuse: `knowledge_items.source_url` (existing column)
The crawler stores the original (pre-normalize) URL in `source_url` for display; `url_normalized` is the dedup key.

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
**Not performed at submit.** URL is accepted as long as the format is valid (`url:http,https`) and `SafeExternalUrl::isSafe` passes. The crawl job (running on the `crawls` queue) handles all reachability outcomes and surfaces them in the KB banner. This keeps registration latency low and prevents transient network failures from losing signups.

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
2. **Find prior item**: `KnowledgeItem::forTenant($tenant)->where('type','webpage')->where('url_normalized', $normalized)->first()` (uses composite index).
3. **If prior exists**:
   - If `If-Modified-Since: prior.metadata.last_modified` returns 304: **skip** (pages_skipped_unchanged++).
   - If header `Last-Modified == prior.metadata.last_modified` AND `ETag == prior.metadata.etag`: **skip**.
4. **GET HTML**. Compute `sha256(html_body)`.
5. **If hash unchanged**: update `metadata.last_modified` and `metadata.etag` only, don't re-chunk/re-embed. (pages_skipped_unchanged++).
6. **Else**: update `content`, recompute chunks, dispatch `ProcessKnowledgeItem` → re-embed. Update all metadata fields. (pages_indexed++).

URLs in `crawl_url_blocklist` for this tenant: skipped silently (no count — already accounted for at deletion time).

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
  - blocklist honored: tenant previously deleted `/admin` (blocklist row present), current run skips it silently
  - Crawl-delay respected: between-request sleep observed
  - SafeExternalUrl SSRF rejected mid-crawl (sitemap pointed to `/internal`)

### Feature tests
- `RegistrationWizardTest`:
  - Wizard with all 3 steps submits successfully (smoke)
  - Step 3 skip: no `website_url` saved, no crawl dispatched
  - Step 3 with valid URL → Tenant created, `website_url` set, `CrawlWebsiteJob` queued on `crawls`
  - Step 3 with malformed URL → validation error, no Tenant created (rollback)
  - Step 3 with `http://localhost` or other SafeExternalUrl rejection → validation error
  - Step 3 with a syntactically valid but unreachable URL → Tenant IS created, crawl IS queued (failure surfaces later in KB banner — verified by asserting `CrawlWebsiteJob` was dispatched even though the URL would fail to resolve)
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
- `2026_05_18_000003_create_crawl_url_blocklist_table.php`
- `2026_05_18_000004_add_crawl_columns_to_knowledge_items_table.php` (adds `url_normalized` column + composite index)

### Models
- `app/Models/CrawlSession.php` (new)
- `app/Models/CrawlUrlBlocklist.php` (new)
- `app/Models/Tenant.php` (add `website_url`, `auto_recrawl` to `$fillable`)
- `app/Models/KnowledgeItem.php` (modify `delete` flow — KB controller adds blocklist row on `type=webpage` deletes)

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
- `app/Http/Controllers/Auth/RegisterController.php` (modified — no HEAD; format-only validation)
- `app/Http/Controllers/Client/WebsiteIndexingController.php` (new)
- `app/Http/Controllers/Client/KnowledgeBaseController.php` (modified — `index()` accepts `?crawl_session_id=` filter; `destroy()` of `type=webpage` items inserts blocklist row when user confirms)

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
- `tests/Feature/Auth/RegistrationTest.php` (UPDATE existing — wizard payload is now 3-step combined; add coverage for skip path, website_url, no-HEAD-check behavior)
- `tests/Feature/Auth/RegistrationWizardTest.php` (new — wizard-specific UI/UX flows distinct from the existing RegistrationTest's auth-correctness coverage)
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
11. **`crawl_sessions` row retention / pruning**. With daily refresh × 365 days × N tenants, this table grows linearly. v1 ships with no retention policy; defer pruning to an ops follow-up once we have real volume data.

---

## Open Operational Questions (not blockers for v1)

To resolve before deploying, but not blockers for code:

- Queue worker concurrency for the `crawls` queue (suggest start at 4, monitor and tune)
- Per-host or global rate ceiling for crawler (suggest 1 req/s per host already implemented; revisit if a single tenant has 1000s of small-host crawls)
- Production user-agent string final wording (legal/ToS review of `ChatbotIndexer/1.0`)
- Whether to expose the indexed page list as exportable (CSV) — defer until tenants ask
