<!-- refreshed: 2026-05-20 -->
# Architecture

**Analysis Date:** 2026-05-20

## System Overview

```text
┌─────────────────────────────────────────────────────────────────────────┐
│                        Browser / Widget JS                               │
│  Client Dashboard (Inertia/Vue)    public/widget/chatbot.js              │
└──────────────┬──────────────────────────────────┬───────────────────────┘
               │ HTTP (Inertia)                    │ HTTP (JSON/SSE)
               ▼                                   ▼
┌─────────────────────────┐    ┌──────────────────────────────────────────┐
│   Web Routes            │    │   API Routes (api/v1/widget/*)            │
│   routes/web.php        │    │   routes/api.php                          │
│                         │    │                                           │
│  Auth / Client / Admin  │    │  Middleware stack (in order):             │
│  Controllers            │    │  1. throttle:widget (global 20/min)       │
│  app/Http/Controllers/  │    │  2. validate.widget.domain (CORS+origin)  │
│    Admin/               │    │  3. widget.throttle_ip:daily (5k/day)     │
│    Client/              │    │  4. widget.session_token (JWT Bearer)     │
│    Auth/                │    │  5. widget.throttle_ip:message|init       │
└──────────────┬──────────┘    │  6. check.limits (usage quota gate)       │
               │               └──────────────────┬───────────────────────┘
               │                                  │
               ▼                                  ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                           Service Layer                                   │
│                                                                           │
│  LLM/ChatService       Knowledge/RetrievalService   Leads/LeadService    │
│  Knowledge/            Knowledge/DocumentProcessor  Leads/LeadScoring    │
│    KnowledgeItemWorkflow  Knowledge/EmbeddingService                     │
│    KnowledgeCache      Usage/UsageTracker           Widget/SessionTokenService│
│  Billing/ReceiptService  Services/Payment/DkBank/   Analytics/AnalyticsService│
│  Crawler/SiteCrawler     Services/Crawler/          Services/Billing/    │
└──────────┬──────────────────────────┬──────────────────────────────────┘
           │                          │ dispatch
           ▼                          ▼
┌────────────────────────┐  ┌─────────────────────────┐
│  Eloquent Models       │  │  Queue Jobs              │
│  app/Models/           │  │  ProcessKnowledgeItem    │
│  (BelongsToTenant      │  │  GenerateEmbeddings      │
│   + BustsTenantUsageCache│  │  CrawlWebsiteJob        │
│   traits on all tenant-│  └────────────┬────────────┘
│   scoped models)       │               │
└──────────┬─────────────┘               │
           │                             │
           ▼                             ▼
┌─────────────────────────────────────────────────────────────────────────┐
│  Data Layer                                                               │
│  MySQL (tenant_id single-DB multi-tenancy)  Redis (cache + queues)        │
│  SQLite-vec (pgvector-compatible embeddings for RAG)                      │
└─────────────────────────────────────────────────────────────────────────┘
```

## Component Responsibilities

| Component | Responsibility | File |
|-----------|----------------|------|
| `ChatController` (Widget) | Widget init, conversation lifecycle, message/stream dispatch | `app/Http/Controllers/Api/V1/Widget/ChatController.php` |
| `LeadController` (Widget) | Widget lead form capture | `app/Http/Controllers/Api/V1/Widget/LeadController.php` |
| `ChatService` | LLM prompt construction, Prism dispatch, retry logic, usage tracking | `app/Services/LLM/ChatService.php` |
| `RetrievalService` | RAG: vector search (pgvector cosine) → keyword fallback | `app/Services/Knowledge/RetrievalService.php` |
| `KnowledgeItemWorkflow` | Canonical state machine for KnowledgeItem (Pending→Processing→Ready→Failed) | `app/Services/Knowledge/KnowledgeItemWorkflow.php` |
| `KnowledgeCache` | Versioned retrieval result cache; invalidated on markReady | `app/Services/Knowledge/KnowledgeCache.php` |
| `DocumentProcessor` | Text extraction + chunking for document/faq/webpage/text types | `app/Services/Knowledge/DocumentProcessor.php` |
| `EmbeddingService` | Generates vector embeddings for chunks | `app/Services/Knowledge/EmbeddingService.php` |
| `LeadScoring` | Canonical scoring engine (0–100 signals+weights) and temperature classification | `app/Services/Leads/LeadScoring.php` |
| `LeadService` | Lead capture/upsert from conversation, delegates scoring to LeadScoring | `app/Services/Leads/LeadService.php` |
| `UsageTracker` | Token/conversation/lead/knowledge usage accounting and Redis caching | `app/Services/Usage/UsageTracker.php` |
| `SessionTokenService` | JWT HS256 mint/verify for widget session tokens (bound to api_key+origin+IP) | `app/Services/Widget/SessionTokenService.php` |
| `BelongsToTenant` | Trait: `forTenant()` query scope + `tenant()` relation + auto-stamp on creating | `app/Models/Concerns/BelongsToTenant.php` |
| `BustsTenantUsageCache` | Trait: fires `UsageTracker::forgetCacheForTenant` on `created` event | `app/Models/Concerns/BustsTenantUsageCache.php` |
| `ValidateWidgetDomain` | CORS preflight short-circuit + allowed_domains enforcement (closed by default) | `app/Http/Middleware/ValidateWidgetDomain.php` |
| `RequireWidgetSessionToken` | JWT Bearer verification; dual-accept mode adds Deprecation header for legacy | `app/Http/Middleware/RequireWidgetSessionToken.php` |
| `CheckUsageLimits` | Gate: checks UsageTracker::canRecordUsage before each metered widget endpoint | `app/Http/Middleware/CheckUsageLimits.php` |
| `AnalyticsService` | Aggregated per-tenant conversation/lead/token stats | `app/Services/Analytics/AnalyticsService.php` |
| `DkBankQrService` | DK Bank QR payment initiation, polling, RRN verification | `app/Services/Payment/DkBank/DkBankQrService.php` |
| `SiteCrawler` | Sitemap-first website crawler for knowledge ingestion | `app/Services/Crawler/SiteCrawler.php` |

## Pattern Overview

**Overall:** Multi-tenant SaaS — single-database multi-tenancy via `tenant_id` column and `BelongsToTenant` trait. No tenant schema switching; Spatie Multitenancy provides the base `Tenant` model; the app enforces isolation through `forTenant($tenant)` query scope (enforced by PHPStan rule `NoRawTenantIdWhere`).

**Key Characteristics:**
- All tenant-scoped models use `BelongsToTenant` trait; raw `where('tenant_id', ...)` is a PHPStan error
- `forTenant($tenant)` is the canonical query filter — use `whereKey($id)->forTenant($tenant)` for find-by-id
- `BustsTenantUsageCache` on Usage-counted models auto-invalidates the Redis usage cache on `created`
- Widget API is stateless JSON/SSE; session continuity handled by JWT Bearer tokens minted at init
- LLM provider is resolved at runtime: Ollama (dev) vs Groq (production) via `config('app.env')`
- Knowledge retrieval has a two-tier fallback: pgvector cosine similarity → keyword LIKE search

## Layers

**HTTP Layer:**
- Purpose: Route requests to services; enforce auth, rate limits, usage quotas
- Location: `app/Http/Controllers/`, `app/Http/Middleware/`
- Contains: Inertia controllers (Client, Admin), Widget JSON controllers, Form Requests
- Depends on: Service layer, Models
- Used by: Browser, widget JS, external HTTP callers

**Service Layer:**
- Purpose: Business logic, isolated from HTTP and persistence details
- Location: `app/Services/`
- Contains: `LLM/`, `Knowledge/`, `Leads/`, `Usage/`, `Widget/`, `Analytics/`, `Billing/`, `Crawler/`, `Payment/DkBank/`
- Depends on: Models, external clients (Prism, DkBankClient)
- Used by: Controllers, Jobs

**Job Layer:**
- Purpose: Async processing for slow operations (chunking, embedding, crawl)
- Location: `app/Jobs/`
- Contains: `ProcessKnowledgeItem`, `GenerateEmbeddings`, `CrawlWebsiteJob`
- Depends on: Service layer, Models
- Used by: Controllers (via `dispatch()`), failed-job callbacks

**Model Layer:**
- Purpose: Eloquent ORM, relations, attribute casts, boot hooks
- Location: `app/Models/`, `app/Models/Concerns/`
- Contains: `Tenant`, `User`, `Conversation`, `Message`, `Lead`, `KnowledgeItem`, `KnowledgeChunk`, `UsageRecord`, `Plan`, `Transaction`, `AdminUser`, `AdminActivityLog`, `CrawlSession`, `CrawlUrlBlocklist`
- Depends on: Database
- Used by: All layers

**Frontend Layer:**
- Purpose: Inertia-driven SPA (Vue 3 Composition API)
- Location: `resources/js/`
- Contains: Pages, Layouts, Components/ui/, composables/, utils/
- Depends on: Backend via Inertia props + standard HTTP
- Used by: Browser only

## Data Flow

### Widget Request Path (Chat Message)

1. Browser loads `public/widget/chatbot.js` which calls `POST /api/v1/widget/init` with `api_key`
2. `ValidateWidgetDomain` middleware verifies `Origin` against `tenant.settings.allowed_domains` (`app/Http/Middleware/ValidateWidgetDomain.php`)
3. `ChatController::init` finds tenant by `api_key` (cached 5 min at `tenant:api_key:{key}`), mints JWT session token via `SessionTokenService::mint` (`app/Services/Widget/SessionTokenService.php`)
4. Browser sends `POST /api/v1/widget/message` with JWT `Authorization: Bearer {token}` + `api_key`
5. `RequireWidgetSessionToken` verifies JWT: signature, issuer, audience (origin), IP binding (`app/Http/Middleware/RequireWidgetSessionToken.php`)
6. `CheckUsageLimits::handle` calls `UsageTracker::canRecordUsage($tenant, 'tokens')` — 403 if over quota (`app/Http/Middleware/CheckUsageLimits.php`)
7. `ChatController::sendMessage` calls `RetrievalService::retrieve($tenant, $message)` (`app/Services/Knowledge/RetrievalService.php`)
8. `RetrievalService` checks `KnowledgeCache` → pgvector cosine search on `knowledge_chunks` → keyword LIKE fallback → caches result
9. `ChatService::generateResponse` builds system prompt with RAG context + tenant bot persona, dispatches to Prism (Ollama/Groq) with 3-retry logic (`app/Services/LLM/ChatService.php`)
10. `UsageTracker::recordTokens` writes `UsageRecord`, busts Redis cache (`app/Services/Usage/UsageTracker.php`)
11. `LeadService::extractContactInfo` + `captureFromConversation` run opportunistically on every message (`app/Services/Leads/LeadService.php`)
12. JSON response with `{ response: "..." }` returned to widget

### Knowledge Item Processing Pipeline

1. Client uploads/creates `KnowledgeItem` via `KnowledgeBaseController::store` → status `Pending`
2. Controller dispatches `ProcessKnowledgeItem::dispatch($item)` (`app/Jobs/ProcessKnowledgeItem.php`)
3. Job calls `KnowledgeItemWorkflow::markProcessing($item)` → status transitions to `Processing`
4. `DocumentProcessor::process($item)` extracts text + chunks it by type (`app/Services/Knowledge/DocumentProcessor.php`)
5. Chunks written atomically via `DB::transaction` (old chunks deleted first, guards retry duplication)
6. `GenerateEmbeddings::dispatch($item)` dispatched — generates pgvector embeddings per chunk
7. Job calls `KnowledgeItemWorkflow::markReady($item)` → status `Ready`, invalidates `KnowledgeCache` via `Cache::increment(knowledge_version:{tenant_id})`
8. On job failure: `failed()` callback calls `KnowledgeItemWorkflow::markFailed($item, $exception)`

### Lead Capture Flow

1. `ChatController::captureLeadFromMessage` runs regex name/email/phone extraction on every user message
2. If contact info detected: `LeadService::captureFromConversation` called with `DB::transaction` + `lockForUpdate` on conversation row (prevents concurrent duplicate leads)
3. `LeadScoring::score($lead, $conversation)` runs full signal evaluation (contact info + keyword dictionaries + message count) → 0–100 int
4. `LeadScoring::temperature($score)` returns `hot` (≥61) / `warm` (≥31) / `cold`
5. Lead persisted; `conversation.lead_id` set (set-once — subsequent messages update existing lead)
6. `NewLeadNotification` dispatched to tenant

### DK Bank QR Payment Flow

1. Client POSTs `billing/dk-qr/{plan}` → `DkBankQrController::start` (`app/Http/Controllers/Client/DkBankQrController.php`)
2. `DkBankQrService` calls `DkBankClient` to generate QR image (`app/Services/Payment/DkBank/`)
3. Transaction record created with `status = 'pending'`
4. Client polls `GET billing/dk-qr/{transaction}/status` (throttled 60/min)
5. Admin approves/rejects via `AdminTransactionController` → `transaction.status` updated → `Tenant::extendPlan()` called on approval

**State Management:**
- Server-side: Redis for usage cache (`tenant:{id}:usage:{Y-m}`), API key cache (`tenant:api_key:{key}`), knowledge result cache (versioned `knowledge:{tenant}:v{N}:{md5(query)}`), tenant plan cache (`tenant:{id}:with_plan`)
- Client-side: Inertia shared props (tenant, auth user, usage stats injected via `HandleInertiaRequests`)

## Key Abstractions

**BelongsToTenant trait:**
- Purpose: Single source of truth for tenant isolation on any Eloquent model
- Files: `app/Models/Concerns/BelongsToTenant.php`
- Pattern: Applied via `use BelongsToTenant;` on every tenant-scoped model. Provides `scopeForTenant(Builder, Tenant|int)` and auto-stamps `tenant_id` on `creating` when `Auth::user()` has a `tenant_id`. Widget/admin/console contexts must pass `tenant_id` explicitly.

**KnowledgeItemWorkflow:**
- Purpose: Strict state machine for document processing lifecycle; prevents invalid transitions
- Files: `app/Services/Knowledge/KnowledgeItemWorkflow.php`
- Pattern: All KnowledgeItem status changes go through this service. Throws `InvalidTransitionException` on bad source state. `markReady` automatically invalidates `KnowledgeCache`.

**UsageTracker:**
- Purpose: All metering, quota checking, and usage cache management in one class
- Files: `app/Services/Usage/UsageTracker.php`
- Pattern: `canRecordUsage($tenant, $type)` is the gate; `recordTokens()` writes and busts cache; `monthlyUsage()` is cached 60s. `BustsTenantUsageCache` trait auto-busts on model `created` events.

**LeadScoring:**
- Purpose: Isolated scoring engine with no persistence; caller owns save
- Files: `app/Services/Leads/LeadScoring.php`
- Pattern: `score(Lead, ?Conversation): int` always does a full re-score from current state (not incremental — scores can go down on update). `temperature(int): string` maps to hot/warm/cold.

**SessionTokenService:**
- Purpose: JWT HS256 tokens binding widget sessions to tenant+origin+IP
- Files: `app/Services/Widget/SessionTokenService.php`
- Pattern: Registered as singleton in `AppServiceProvider` using `APP_KEY` as secret. Token `sub` is `sha256(api_key + secret)` — api_key rotation invalidates all outstanding tokens. Verify is O(active_tenants) in-PHP scan — see scaling concern.

## Entry Points

**Widget JS:**
- Location: `public/widget/chatbot.js`
- Triggers: WordPress embed script tag
- Responsibilities: Renders chat UI, calls `/api/v1/widget/init`, manages session token, sends messages

**API Routes:**
- Location: `routes/api.php`
- Widget group: `POST /api/v1/widget/init|conversation|message|message/stream|conversation/end|lead`
- Client API group: `GET|POST /api/v1/*` (auth:sanctum, currently placeholder)

**Web Routes:**
- Location: `routes/web.php`
- Public: `/`, `/login`, `/register`, password reset
- Client: `/dashboard`, `/knowledge`, `/widget-settings`, `/leads`, `/conversations`, `/analytics`, `/billing`
- Admin: `/admin/login`, `/admin/dashboard`, `/admin/clients`, `/admin/plans`, `/admin/transactions`, `/admin/inquiries`, `/admin/activity-logs`

**Queue Jobs:**
- `ProcessKnowledgeItem` — dispatched by `KnowledgeBaseController` on store/reprocess/retry
- `GenerateEmbeddings` — dispatched by `ProcessKnowledgeItem` after chunking
- `CrawlWebsiteJob` — dispatched by `WebsiteIndexingController::recrawl` and `RefreshAllCrawls` console command

## Architectural Constraints

- **Threading:** Single-threaded PHP per request; async via Laravel queue (Redis). SSE streaming uses `response()->stream()` with `ob_flush()/flush()`.
- **Global state:** `SessionTokenService` is a singleton bound to `APP_KEY`. `RobotsTxtPolicy` is scoped (per-request/job). `JWT::$timestamp` is set/reset per `verify()` call for test travel compatibility.
- **Tenant isolation:** PHPStan rule `App\Rules\PHPStan\NoRawTenantIdWhere` (`app/Rules/PHPStan/`) blocks raw `where('tenant_id', ...)` in new code. Zero grandfathered violations as of 2026-05-15.
- **Dual-accept mode:** Widget session tokens enforce JWT validation when Bearer is present; missing Bearer is allowed through with a `Deprecation: true` response header (controlled by `WIDGET_SESSION_DUAL_ACCEPT` env, default `true`). Strict-mode cutover requires `TrustProxies` to be configured first.
- **DK Bank killswitch:** `DK_BANK_ENABLED` env flag gates all DK Bank QR UI; backend routes remain active.

## Anti-Patterns

### Raw `where('tenant_id', ...)` Queries

**What happens:** Directly filtering by `where('tenant_id', $id)` on tenant-scoped models instead of using the `forTenant()` scope.
**Why it's wrong:** Bypasses the canonical abstraction; PHPStan will flag it; easy to miss when the column is renamed or the filter is accidentally dropped.
**Do this instead:** `KnowledgeItem::query()->forTenant($tenant)->...` or `Conversation::whereKey($id)->forTenant($tenant)->firstOrFail()`

### Direct `Cache::forget("tenant:{$id}:usage:...")` in Tests or Controllers

**What happens:** Manually busting the usage cache key string outside `UsageTracker`.
**Why it's wrong:** `BustsTenantUsageCache` fires automatically on `created`; a manual forget is redundant and misleads readers about who owns the key.
**Do this instead:** Let the trait and `UsageTracker::forgetCache($tenant)` / `forgetCacheForTenant($id)` be the only callers.

### Bypassing `KnowledgeItemWorkflow` for Status Updates

**What happens:** Calling `$item->update(['status' => KnowledgeItemStatus::Ready])` directly.
**Why it's wrong:** Skips cache invalidation (`KnowledgeCache::invalidate`) and transition guards.
**Do this instead:** Use `KnowledgeItemWorkflow::markReady($item)` — it invalidates the retrieval cache automatically.

## Error Handling

**Strategy:** Layered — widget API returns structured JSON errors (`{ error, code }`); Inertia routes return Blade/Inertia error pages; queue job failures trigger `failed()` callbacks that call `KnowledgeItemWorkflow::markFailed`.

**Patterns:**
- Widget controllers: `private function errorResponse(string $message, string $code, int $status): JsonResponse` — standardized error shape
- LLM failures: 3-retry with exponential backoff (1s/2s/4s) via Laravel `retry()`; graceful fallback string on exhaustion
- Knowledge job failures: `ProcessKnowledgeItem::failed()` → `KnowledgeItemWorkflow::markFailed()` with exception message stored on item
- Exception rendering: `bootstrap/app.php` configures JSON rendering for `api/*` routes via `shouldRenderJsonWhen`

## Cross-Cutting Concerns

**Logging:** Laravel `Log::` facade with structured arrays. Prefix convention: `[WidgetName] (IS $)` for billed calls, `(NO $)` for free/local calls. Widget security events use dedicated `widget_audit` log channel via `WidgetAudit::log()` (`app/Support/Widget/WidgetAudit.php`).

**Validation:** Form Requests for web (e.g., `UpdateWebsiteIndexingRequest`); inline `$request->validate()` in widget API controllers. PHPStan at level max with `BelongsToTenant` Larastan rule.

**Authentication:** Client dashboard — Laravel Sanctum session auth (`auth` middleware). Admin dashboard — separate `AdminUser` guard (`admin` guard, `AdminAuthenticate` middleware). Widget API — API key in request body + JWT Bearer session token (dual-accept).

**Tenant Isolation:** `BelongsToTenant::scopeForTenant()` on all tenant-scoped models. `CheckUsageLimits` resolves tenant from either `Auth::user()->tenant` (web) or `api_key` body param (widget). Admin routes are explicitly not tenant-scoped.

**Rate Limiting:** `throttle:widget` (20 req/min per api_key+IP, registered in `AppServiceProvider`); `widget.throttle_ip:init` (10 init/min per IP); `widget.throttle_ip:message` (30 msg/min per IP); `widget.throttle_ip:daily` (5000 req/day per IP); `throttle:dk-rrn-verify` (5/hr per transaction, 20/hr per tenant); `throttle:5,1,register` and `throttle:5,1,forgot` on auth routes.

---

*Architecture analysis: 2026-05-20*
