# Codebase Structure

**Analysis Date:** 2026-05-20

## Directory Layout

```
chatbot/
├── app/
│   ├── Console/
│   │   └── Commands/                  # Artisan commands
│   │       ├── CleanupAbandonedDkQrSessions.php
│   │       └── RefreshAllCrawls.php
│   ├── Enums/                         # PHP enums
│   │   ├── CrawlMode.php
│   │   ├── CrawlSessionStatus.php
│   │   └── KnowledgeItemStatus.php
│   ├── Exceptions/
│   │   ├── Billing/                   # Billing-specific exceptions
│   │   └── Widget/                    # Widget-specific exceptions
│   │       └── InvalidSessionTokenException.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/                 # Platform admin (not tenant-scoped)
│   │   │   │   ├── Auth/LoginController.php
│   │   │   │   ├── ActivityLogController.php
│   │   │   │   ├── ClientController.php
│   │   │   │   ├── DashboardController.php
│   │   │   │   ├── EnterpriseInquiryController.php
│   │   │   │   ├── PlanController.php
│   │   │   │   └── TransactionController.php
│   │   │   ├── Api/V1/Widget/         # Public widget JSON API
│   │   │   │   ├── ChatController.php
│   │   │   │   └── LeadController.php
│   │   │   ├── Auth/                  # Client auth (login, register, passwords)
│   │   │   └── Client/               # Client dashboard (Inertia)
│   │   │       ├── AnalyticsController.php
│   │   │       ├── BillingController.php
│   │   │       ├── ConversationController.php
│   │   │       ├── DashboardController.php
│   │   │       ├── DkBankQrController.php
│   │   │       ├── EnterpriseInquiryController.php
│   │   │       ├── KnowledgeBaseController.php
│   │   │       ├── LeadController.php
│   │   │       ├── WebsiteIndexingController.php
│   │   │       └── WidgetController.php
│   │   ├── Middleware/
│   │   │   ├── AdminAuthenticate.php
│   │   │   ├── CheckUsageLimits.php
│   │   │   ├── HandleInertiaRequests.php
│   │   │   ├── RequireWidgetSessionToken.php
│   │   │   ├── ThrottleWidgetPerIp.php
│   │   │   └── ValidateWidgetDomain.php
│   │   └── Requests/
│   │       ├── Admin/Auth/            # Admin form requests
│   │       ├── Auth/                  # Client auth form requests
│   │       └── Client/               # Client dashboard form requests
│   │           └── UpdateWebsiteIndexingRequest.php
│   ├── Jobs/
│   │   ├── CrawlWebsiteJob.php
│   │   ├── GenerateEmbeddings.php
│   │   └── ProcessKnowledgeItem.php
│   ├── Models/
│   │   ├── Concerns/                  # Reusable model traits
│   │   │   ├── BelongsToTenant.php    # forTenant scope + auto-stamp
│   │   │   └── BustsTenantUsageCache.php  # usage cache invalidation on created
│   │   ├── AdminActivityLog.php
│   │   ├── AdminUser.php
│   │   ├── Conversation.php
│   │   ├── CrawlSession.php
│   │   ├── CrawlUrlBlocklist.php
│   │   ├── EnterpriseInquiry.php
│   │   ├── KnowledgeChunk.php
│   │   ├── KnowledgeItem.php
│   │   ├── Lead.php
│   │   ├── Message.php
│   │   ├── Plan.php
│   │   ├── Tenant.php                 # Extends Spatie BaseTenant
│   │   ├── Transaction.php
│   │   ├── UsageRecord.php
│   │   └── User.php
│   ├── Notifications/
│   │   └── NewLeadNotification.php
│   ├── Policies/
│   │   ├── ConversationPolicy.php
│   │   ├── KnowledgeItemPolicy.php
│   │   ├── LeadPolicy.php
│   │   └── TransactionPolicy.php
│   ├── Providers/
│   │   └── AppServiceProvider.php     # Rate limiters + singleton bindings
│   ├── Rules/
│   │   ├── PHPStan/                   # Custom Larastan rules
│   │   │   └── NoRawTenantIdWhere.php # Blocks raw where('tenant_id', ...) 
│   │   └── SafeExternalUrl.php
│   ├── Services/
│   │   ├── Analytics/
│   │   │   └── AnalyticsService.php
│   │   ├── Billing/
│   │   │   └── ReceiptService.php
│   │   ├── Crawler/
│   │   │   ├── RobotsPolicy.php
│   │   │   ├── RobotsTxtPolicy.php
│   │   │   ├── SiteCrawler.php
│   │   │   ├── SitemapDiscoverer.php
│   │   │   └── UrlNormalizer.php
│   │   ├── Knowledge/
│   │   │   ├── DocumentProcessor.php  # Text extraction + chunking
│   │   │   ├── EmbeddingService.php   # Vector embedding generation
│   │   │   ├── KnowledgeCache.php     # Versioned retrieval result cache
│   │   │   ├── KnowledgeItemWorkflow.php  # State machine (Pending→Ready→Failed)
│   │   │   └── RetrievalService.php   # RAG: pgvector → keyword fallback
│   │   ├── Leads/
│   │   │   ├── LeadScoring.php        # Canonical scoring engine (0–100)
│   │   │   └── LeadService.php        # Lead capture/upsert from conversation
│   │   ├── LLM/
│   │   │   └── ChatService.php        # Prism dispatch, retry, prompt builder
│   │   ├── Payment/
│   │   │   └── DkBank/
│   │   │       ├── DTO/               # Request/response DTOs
│   │   │       ├── DkBankClient.php   # HTTP client for DK Bank API
│   │   │       └── DkBankQrService.php
│   │   ├── Usage/
│   │   │   └── UsageTracker.php       # All usage metering and quota logic
│   │   └── Widget/
│   │       └── SessionTokenService.php  # JWT HS256 mint/verify
│   └── Support/
│       ├── Http/
│       │   └── CanonicalOrigin.php    # Origin/Referer normalization
│       └── Widget/
│           ├── WidgetAudit.php        # Structured widget security audit log
│           └── WidgetErrors.php       # Widget error code constants
├── bootstrap/
│   └── app.php                        # Middleware registration + routing
├── config/
│   ├── billing.php                    # Trial limits, billing settings
│   ├── multitenancy.php               # Spatie multitenancy config
│   ├── prism.php                      # LLM provider config
│   ├── services.php                   # Groq/Ollama model config
│   └── widget.php                     # Widget rate limits, session TTL, dual-accept
├── database/
│   ├── factories/                     # Model factories (all models)
│   ├── migrations/                    # Chronological schema migrations
│   └── seeders/
├── docs/
│   └── superpowers/
│       ├── plans/                     # TDD implementation plans
│       └── specs/                     # Feature design specs
├── public/
│   └── widget/
│       ├── chatbot.js                 # WordPress embed script (entry point)
│       ├── widget.js                  # Widget UI logic
│       ├── widget.css
│       └── test.html                  # Local widget test page
├── resources/
│   ├── css/
│   └── js/
│       ├── Components/
│       │   ├── IndexingStatusBanner.vue
│       │   └── ui/                    # Shadcn-style primitive components
│       │       ├── alert/
│       │       ├── avatar/
│       │       ├── badge/
│       │       ├── button/
│       │       ├── card/
│       │       ├── input/
│       │       ├── label/
│       │       ├── select/
│       │       ├── separator/
│       │       ├── switch/
│       │       ├── table/
│       │       └── textarea/
│       ├── composables/
│       │   ├── useRoute.js            # Named route helper
│       │   └── useTheme.js
│       ├── Layouts/
│       │   ├── AdminLayout.vue        # Admin dashboard shell
│       │   └── ClientLayout.vue       # Client dashboard shell
│       ├── lib/                       # Utility functions
│       ├── Pages/
│       │   ├── Admin/
│       │   │   ├── Auth/Login.vue
│       │   │   ├── Clients/           # Index.vue, Show.vue
│       │   │   ├── Dashboard.vue
│       │   │   ├── Inquiries/Index.vue
│       │   │   ├── Logs/Index.vue
│       │   │   ├── Plans/             # Create.vue, Edit.vue, Index.vue
│       │   │   └── Transactions/      # Index.vue, Show.vue
│       │   ├── Auth/                  # ForgotPassword, Login, Register, ResetPassword
│       │   ├── Client/
│       │   │   ├── Analytics/Index.vue
│       │   │   ├── Billing/           # DkQrSession, Index, Plans, Subscribe, Transactions
│       │   │   ├── Conversations/     # Index.vue, Show.vue
│       │   │   ├── Dashboard.vue
│       │   │   ├── KnowledgeBase/     # Create, Edit, Index, Show
│       │   │   ├── Leads/             # Index.vue, Show.vue
│       │   │   └── Widget/Index.vue   # Widget settings page
│       │   └── Welcome.vue            # Marketing landing page
│       └── utils/
├── routes/
│   ├── api.php                        # Widget API + Client API routes
│   ├── console.php                    # Artisan schedule
│   └── web.php                        # All web routes (public/client/admin)
└── tests/
    ├── Feature/                       # HTTP/integration tests
    └── Unit/                          # Unit tests for services
```

## Directory Purposes

**`app/Http/Controllers/Admin/`:**
- Purpose: Platform admin views and actions (not tenant-scoped)
- Contains: Client management, plan management, transaction approval, enterprise inquiries, activity logs
- Key note: Uses `AdminAuthenticate` middleware; separate `AdminUser` model/guard

**`app/Http/Controllers/Api/V1/Widget/`:**
- Purpose: Public widget JSON API — the only public unauthenticated entry point
- Contains: `ChatController` (conversation lifecycle, LLM messages), `LeadController` (form lead capture)
- Key note: Auth is API key + JWT Bearer, not Sanctum

**`app/Http/Controllers/Client/`:**
- Purpose: Inertia server-side controllers for the client dashboard
- Contains: One controller per dashboard section
- Key note: Tenant isolation via `Auth::user()->tenant`

**`app/Models/Concerns/`:**
- Purpose: Reusable model traits for cross-cutting concerns
- Use `BelongsToTenant` on every new tenant-scoped model
- Use `BustsTenantUsageCache` on models that count toward usage limits (Conversation, Lead, KnowledgeItem, UsageRecord)

**`app/Services/Knowledge/`:**
- Purpose: Full RAG pipeline from ingestion to retrieval
- `DocumentProcessor` → `KnowledgeItemWorkflow` → `KnowledgeCache` → `RetrievalService`
- Always go through `KnowledgeItemWorkflow` for status changes; never update status directly

**`app/Services/Usage/`:**
- Purpose: Single owner of all usage metering, limit checking, and cache management
- `UsageTracker` is the only class that should read/write usage cache keys

**`app/Rules/PHPStan/`:**
- Purpose: Custom Larastan rules that enforce architectural constraints at CI time
- `NoRawTenantIdWhere` — zero violations maintained; never add baseline suppressions

**`public/widget/`:**
- Purpose: Self-contained widget JS/CSS bundle served as static files
- `chatbot.js` is the WordPress embed entry point
- These files are NOT built by Vite — they are standalone

**`resources/js/Components/ui/`:**
- Purpose: Shadcn-style primitive UI components (Button, Card, Input, etc.)
- These are building blocks only — use them inside Page components
- Do NOT add business logic to `ui/` components

**`docs/superpowers/`:**
- Purpose: Design artifacts (specs and implementation plans) — committed to git
- `specs/YYYY-MM-DD-<topic>-design.md` — feature design decisions
- `plans/YYYY-MM-DD-<topic>.md` — TDD implementation plans

## Key File Locations

**Entry Points:**
- `routes/web.php`: All web routes (client, admin, auth, public)
- `routes/api.php`: Widget API + client API routes
- `bootstrap/app.php`: Middleware aliases and registration
- `public/widget/chatbot.js`: Widget embed script

**Configuration:**
- `config/widget.php`: Widget rate limit config, session TTL, dual-accept flag
- `config/billing.php`: Trial limits per type (conversations, leads, tokens, knowledge_items)
- `config/multitenancy.php`: Spatie multitenancy setup
- `config/prism.php`: LLM provider configuration
- `app/Providers/AppServiceProvider.php`: Rate limiter definitions, singleton bindings

**Core Logic:**
- `app/Models/Concerns/BelongsToTenant.php`: Tenant isolation trait (apply to all new tenant models)
- `app/Services/Usage/UsageTracker.php`: All usage accounting
- `app/Services/LLM/ChatService.php`: LLM dispatch and prompt engineering
- `app/Services/Knowledge/RetrievalService.php`: RAG retrieval
- `app/Services/Leads/LeadScoring.php`: Lead scoring engine
- `app/Services/Widget/SessionTokenService.php`: Widget JWT session tokens

**Testing:**
- `tests/Feature/`: HTTP-level feature tests (one file per controller or flow)
- `tests/Unit/`: Service-level unit tests

## Naming Conventions

**Files (PHP):**
- Controllers: `{Domain}Controller.php` (e.g., `KnowledgeBaseController.php`, `ChatController.php`)
- Services: Descriptive noun (`ChatService.php`, `LeadScoring.php`, `KnowledgeItemWorkflow.php`)
- Models: Singular PascalCase (`KnowledgeItem.php`, `UsageRecord.php`)
- Jobs: Verb phrase (`ProcessKnowledgeItem.php`, `GenerateEmbeddings.php`)
- Middleware: Descriptive verb phrase (`ValidateWidgetDomain.php`, `CheckUsageLimits.php`)
- Migrations: `YYYY_MM_DD_HHMMSS_{description}.php` e.g. `create_knowledge_items_table`

**Files (Vue):**
- Pages: `resources/js/Pages/{Admin|Client|Auth}/{Feature}/{Action}.vue` (e.g., `Client/KnowledgeBase/Create.vue`)
- Layouts: `{Role}Layout.vue` (e.g., `AdminLayout.vue`, `ClientLayout.vue`)
- Components: PascalCase descriptor (e.g., `IndexingStatusBanner.vue`)
- UI primitives: `resources/js/Components/ui/{name}/{ComponentName}.vue`

**Directories:**
- PHP namespaces mirror directory structure under `app/`
- Vue pages mirror the route hierarchy under `resources/js/Pages/`

## Where to Add New Code

**New Widget API endpoint:**
- Controller: `app/Http/Controllers/Api/V1/Widget/`
- Route: add to the `Route::prefix('v1/widget')` group in `routes/api.php`
- Middleware: apply `check.limits:{type}` if metered; apply `widget.session_token` if post-init

**New Client Dashboard page:**
- Controller: `app/Http/Controllers/Client/{Feature}Controller.php`
- Inertia page: `resources/js/Pages/Client/{Feature}/{Action}.vue`
- Route: add to the `Route::middleware('auth')` group in `routes/web.php`

**New Admin page:**
- Controller: `app/Http/Controllers/Admin/{Feature}Controller.php`
- Inertia page: `resources/js/Pages/Admin/{Feature}/{Action}.vue`
- Route: add to the `Route::middleware(AdminAuthenticate::class)` group in `routes/web.php`

**New tenant-scoped model:**
1. Create model in `app/Models/`
2. Add `use BelongsToTenant;` trait
3. Add `use BustsTenantUsageCache;` trait if the model counts toward a usage limit
4. Add `@use HasFactory<{Model}Factory>` PHPDoc for Larastan
5. Add factory in `database/factories/`
6. Add migration with `tenant_id` column + index

**New service:**
- Place in `app/Services/{Domain}/{ServiceName}.php`
- Register in `AppServiceProvider` only if it needs a singleton or scoped binding

**New queue job:**
- Place in `app/Jobs/{VerbNoun}.php`
- Use `NotTenantAware` from `Spatie\Multitenancy\Jobs\NotTenantAware` if the job loads its own tenant context (like `ProcessKnowledgeItem`)

**New UI component:**
- Reusable primitive: `resources/js/Components/ui/{name}/{ComponentName}.vue`
- Feature-specific shared component: `resources/js/Components/{ComponentName}.vue`

**New form validation:**
- Web routes: create `app/Http/Requests/Client/{FeatureRequest}.php`
- Widget API: use inline `$request->validate([...])` in the controller

## Special Directories

**`.claude/`:**
- Purpose: Claude Code workspace and worktrees
- Generated: Partially (worktrees)
- Committed: No (worktrees are ephemeral)

**`.planning/codebase/`:**
- Purpose: Codebase map documents consumed by GSD planning tools
- Generated: Yes (by `/gsd:map-codebase`)
- Committed: Yes

**`docs/superpowers/`:**
- Purpose: Feature specs and TDD implementation plans
- Generated: No (written during feature development)
- Committed: Yes

**`public/widget/`:**
- Purpose: Widget static assets (not Vite-built)
- Generated: No (hand-authored)
- Committed: Yes

**`vendor/`:**
- Purpose: Composer dependencies
- Generated: Yes (`composer install`)
- Committed: No

**`node_modules/`:**
- Purpose: npm dependencies
- Generated: Yes (`npm install`)
- Committed: No

---

*Structure analysis: 2026-05-20*
