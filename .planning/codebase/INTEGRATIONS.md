# External Integrations

**Analysis Date:** 2026-05-20

## APIs & External Services

**LLM (Production):**
- Groq — inference for `llama-3.1-8b-instant` model in production
  - SDK/Client: `prism-php/prism` via `Prism::text()->using(Provider::Groq, ...)`
  - Auth env var: `GROQ_API_KEY`
  - URL env var: `GROQ_URL` (default: `https://api.groq.com/openai/v1`)
  - Model env var: `GROQ_MODEL` (default: `llama-3.1-8b-instant`) in `config/services.php`
  - Call site: `app/Services/LLM/ChatService.php`

**LLM (Development):**
- Ollama — local inference for `gemma3:4b` model in non-production
  - SDK/Client: `prism-php/prism` via `Prism::text()->using(Provider::Ollama, ...)`
  - URL env var: `OLLAMA_URL` (default: `http://localhost:11434`)
  - Model env var: `OLLAMA_MODEL` (default: `gemma3:4b`) in `config/services.php`
  - Call site: `app/Services/LLM/ChatService.php`

**Embeddings:**
- Ollama (default dev) or configurable provider — generates 768-dimension embeddings via `nomic-embed-text`
  - SDK/Client: `prism-php/prism` via `Prism::embeddings()->using(...)`
  - Auth env var: `EMBEDDING_PROVIDER` (default: `ollama`), `EMBEDDING_MODEL` (default: `nomic-embed-text`)
  - Call site: `app/Services/Knowledge/EmbeddingService.php`
  - Dimensions constant: `EmbeddingService::DIMENSIONS = 768`

**Payment Gateway (QR):**
- DK Bank — QR-code-based payment flow (behind killswitch `DK_BANK_ENABLED=false`)
  - SDK/Client: custom `app/Services/Payment/DkBank/DkBankClient.php` using Laravel `Http` facade
  - Auth: OAuth2 password grant (`DK_BANK_USERNAME`, `DK_BANK_PASSWORD`, `DK_BANK_CLIENT_ID`, `DK_BANK_CLIENT_SECRET`)
  - API key header: `X-gravitee-api-key` (`DK_BANK_API_KEY`)
  - JWT signing: `firebase/php-jwt` with RSA private key at `storage/app/dk_pg.pem`
  - Access tokens cached in Redis at `dk_bank:access_token` for 1500 seconds (25 min)
  - Endpoint: `DK_BANK_BASE_URL` env var
  - Config: `config/services.php` under `dk_bank.*`
  - Service files: `app/Services/Payment/DkBank/DkBankClient.php`, `app/Services/Payment/DkBank/DkBankQrService.php`
  - Controller: `app/Http/Controllers/Client/DkBankQrController.php`

**Configured-but-unused providers (via `config/prism.php`):**
- OpenAI — `OPENAI_API_KEY`, `OPENAI_URL`
- Anthropic — `ANTHROPIC_API_KEY`, `ANTHROPIC_URL`
- Mistral — `MISTRAL_API_KEY`
- xAI — `XAI_API_KEY`
- Google Gemini — `GEMINI_API_KEY`
- DeepSeek — `DEEPSEEK_API_KEY`
- ElevenLabs — `ELEVENLABS_API_KEY`
- VoyageAI — `VOYAGEAI_API_KEY`
- OpenRouter — `OPENROUTER_API_KEY`

## Data Storage

**Databases:**
- MySQL 8.0+ (production primary)
  - Connection: `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
  - Client: Eloquent ORM (Laravel)
  - charset: `utf8mb4`, collation: `utf8mb4_unicode_ci`, strict mode enabled
- SQLite (dev/test fallback; in-memory for PHPUnit)
  - Connection: `DB_DATABASE` (default: `database/database.sqlite`)
  - pgvector extension: enabled via migration `2025_11_27_000000_enable_pgvector_extension.php`

**Vector Search:**
- pgvector — cosine-distance search on `knowledge_chunks.embedding` column (type: `vector(768)`)
  - HNSW index: `knowledge_chunks_embedding_hnsw_idx` (`vector_cosine_ops`)
  - Query syntax: `orderByRaw('embedding <=> ?::vector', [$queryVector])`
  - Call site: `app/Services/Knowledge/RetrievalService.php`
  - Fallback: keyword LIKE search when no embedding is available

**File Storage:**
- Local filesystem (Laravel's default `local` disk)
  - PDF receipts generated on-the-fly by `app/Services/Billing/ReceiptService.php` (not stored)
  - DK Bank RSA private key stored at `storage/app/dk_pg.pem`

**Caching:**
- Redis (production) — `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME`, `REDIS_PASSWORD`
  - Client: `phpredis` (`REDIS_CLIENT`)
  - Two databases: `REDIS_DB` (default: 0) for general, `REDIS_CACHE_DB` (default: 1) for cache store
  - Retry: decorrelated jitter backoff, 3 retries
- Database (default fallback) — `cache` table; `CACHE_STORE` env var selects store
- Named cache keys pattern: `tenant:{id}:with_plan`, `dk_bank:access_token`

**Queue:**
- Database driver (default) — `jobs` table; `QUEUE_CONNECTION` env var
- Redis driver available for production scaling
- All queued jobs are tenant-aware by default (Spatie multitenancy)
- Jobs: `app/Jobs/CrawlWebsiteJob.php`, `app/Jobs/GenerateEmbeddings.php`, `app/Jobs/ProcessKnowledgeItem.php`

## Authentication & Identity

**Session Auth (Dashboard):**
- Laravel session-based auth (no Sanctum tokens for dashboard)
- Two guards: `web` (client users → `App\Models\User`) and `admin` (admin users → `App\Models\AdminUser`)
- Password reset: built-in Laravel flow via email

**Widget API Auth:**
- API key authentication — tenants have a hashed `api_key` on the `tenants` table (`api_key_hash` for lookup)
- Widget session tokens — short-lived JWT Bearer tokens issued at `/api/v1/widget/init`
  - Signed with `APP_KEY` via `firebase/php-jwt`
  - TTL: `WIDGET_SESSION_TTL` (default: 1800 seconds)
  - Dual-accept mode: `WIDGET_SESSION_DUAL_ACCEPT` (default: `true`) — accepts both old and new tokens during rotation
  - Cross-tenant binding: token carries `tenant_id`, verified on every request
  - Service: `app/Services/Widget/SessionTokenService.php`
  - Middleware: `app/Http/Middleware/RequireWidgetSessionToken.php`

**Client API Auth:**
- Sanctum (`auth:sanctum`) middleware applied to `/api/v1/*` routes in `routes/api.php`
  - No client API routes are currently implemented (marked TODO)
  - CORS restricted to `sanctum/csrf-cookie` path only; widget CORS is handled by `ValidateWidgetDomain` middleware

## Monitoring & Observability

**Error Tracking:**
- No third-party error tracking detected (no Sentry, Bugsnag, Flare, etc.)

**Logs:**
- Monolog via Laravel's logging stack
  - Default channel: `LOG_CHANNEL` env var (default: `stack` → `single` file at `storage/logs/laravel.log`)
  - Daily log rotation available (`daily` channel)
  - Debug log convention: `(IS $)` prefix for billed external calls, `(NO $)` prefix for local operations
  - Admin activity logged to `admin_activity_logs` DB table via `App\Models\AdminActivityLog`

**Static Analysis:**
- Larastan (PHPStan level 6) — zero baseline, custom rule `App\Rules\PHPStan\NoRawTenantIdWhere` forbids raw `tenant_id` WHERE clauses

## CI/CD & Deployment

**Hosting:**
- Not detected from codebase (no Forge, Vapor, Railway, or platform configs found)

**CI Pipeline:**
- Not detected (no `.github/workflows/`, `.gitlab-ci.yml`, or similar)

## Email

**Mail transport:**
- Default mailer: `log` (`MAIL_MAILER` env var; production should set SMTP/Resend/etc.)
- Configured transports in `config/mail.php`: SMTP, Resend, Postmark, SES, Mailgun
- Auth env vars (if used): `RESEND_API_KEY`, `POSTMARK_API_KEY`, `AWS_ACCESS_KEY_ID` + `AWS_SECRET_ACCESS_KEY`
- Usage: password reset emails (`app/Http/Controllers/Auth/ForgotPasswordController.php`)

## Site Crawler (Internal Service)

- Custom HTTP crawler — fetches tenant website content for RAG knowledge base
  - Implementation: `app/Services/Crawler/SiteCrawler.php` (Laravel `Http` facade)
  - Sitemap discovery: `app/Services/Crawler/SitemapDiscoverer.php`
  - robots.txt respect: `app/Services/Crawler/RobotsTxtPolicy.php` (honors `crawl-delay`)
  - URL normalization: `app/Services/Crawler/UrlNormalizer.php`
  - Blocklist: `App\Models\CrawlUrlBlocklist`
  - Sessions tracked: `App\Models\CrawlSession` with status enum (`Running`, `Completed`, `Partial`, `Failed`)
  - Job: `app/Jobs/CrawlWebsiteJob.php` — dispatched on recrawl request
  - No external crawl API — all HTTP calls go directly to the tenant's website

## Webhooks & Callbacks

**Incoming:**
- DK Bank QR status polling: `/billing/dk-qr/{transaction}/status` (GET, throttled 60/min) — client polls; no incoming webhook from DK Bank detected
- DK Bank RRN verification: `/billing/dk-qr/{transaction}/verify-rrn` (POST, throttled via `dk-rrn-verify` rate limiter)

**Outgoing:**
- All outgoing calls use Laravel's `Http` facade (no webhook dispatch)
- DK Bank API calls: `POST {DK_BANK_BASE_URL}/v1/auth/token` and signed payment endpoints via `DkBankClient::postSigned()`
- Groq/Ollama/embedding providers: called via Prism PHP SDK

## Environment Configuration

**Required env vars (production):**
- `APP_KEY` — application encryption key (also used for JWT widget session token signing)
- `DB_CONNECTION=mysql`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `REDIS_HOST`, `REDIS_PORT`
- `GROQ_API_KEY` — LLM inference (production)
- `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis` (recommended for production)
- `APP_URL` — used for CORS allowed origins

**Optional / feature-gated:**
- `DK_BANK_ENABLED=true` + all `DK_BANK_*` vars + `storage/app/dk_pg.pem`
- `WIDGET_SESSION_DUAL_ACCEPT`, `WIDGET_SESSION_TTL`
- `WIDGET_IP_INIT_PER_MIN`, `WIDGET_IP_MESSAGE_PER_MIN`, `WIDGET_IP_DAILY_CAP`
- `TRIAL_CONVERSATIONS_LIMIT`, `TRIAL_TOKENS_LIMIT`, `TRIAL_LEADS_LIMIT`, `TRIAL_KNOWLEDGE_ITEMS_LIMIT`
- `EMBEDDING_PROVIDER`, `EMBEDDING_MODEL`
- `MAIL_MAILER` + transport-specific vars

**Secrets location:**
- `.env` file (git-ignored) at project root
- DK Bank RSA private key: `storage/app/dk_pg.pem` (filesystem, not in `.env`)

---

*Integration audit: 2026-05-20*
