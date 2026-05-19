# Technology Stack

**Analysis Date:** 2026-05-20

## Languages

**Primary:**
- PHP 8.3+ (required), 8.4.21 installed — backend application logic, all server-side code
- JavaScript/ES Module — Vue 3 frontend components, Vite build pipeline

**Secondary:**
- SQL — MySQL 8.0+ (production), SQLite with pgvector extension (testing/dev fallback)

## Runtime

**Environment:**
- PHP-FPM / Laravel's built-in server (`php artisan serve` on port 8001 in dev)
- Node.js v26.0.0 — frontend build tooling only (not server-side rendering)

**Package Manager:**
- Composer — PHP dependencies; lockfile: `composer.lock` (present, committed)
- npm — JS dependencies; lockfile: `package-lock.json` (present, committed)

## Frameworks

**Core:**
- Laravel 13.2.0 (`laravel/framework`) — application backbone, routing, ORM, queues, auth
- Vue 3.5.31 — reactive frontend components using Composition API + `<script setup>`
- Inertia.js 2.3.18 (`@inertiajs/vue3`) — server-driven SPA bridge (no REST API for dashboard)
- Tailwind CSS 4.2.2 — utility-first styling via `@tailwindcss/vite` Vite plugin (no separate tailwind.config.js)

**Multi-tenancy:**
- Spatie Laravel Multitenancy 4.1.0 — single-database multi-tenancy with `tenant_id` column strategy; `queues_are_tenant_aware_by_default: true`

**LLM/AI:**
- Prism PHP v0.100.1 (`prism-php/prism`) — unified LLM facade supporting Ollama (dev) and Groq (prod)

**Payments:**
- Laravel Cashier v16.5.0 (`laravel/cashier`) — installed as dependency; billing is implemented via a custom Transaction model rather than Cashier's Billable trait (no `use Billable` on Tenant model)
- Stripe PHP SDK v17.6.0 (`stripe/stripe-php`) — pulled in by Cashier

**Testing:**
- PHPUnit 13.x (`phpunit/phpunit`) — test runner configured in `phpunit.xml`
- Larastan (`larastan/larastan`) — PHPStan level 6 static analysis; zero-baseline enforced via `phpstan-baseline.neon`

**Build/Dev:**
- Vite 8.0.2 — asset bundling; entry points `resources/css/app.css` + `resources/js/app.js`
- laravel-vite-plugin 3.x — hot-reload integration with Laravel
- Laravel Pint — PSR-12 code formatter; `pint.json` uses `"preset": "laravel"`
- Laravel Pail — log tailing in dev
- Laravel Sail — Docker dev environment (available, not required)
- Laravel Debugbar 4.x (dev only)

## Key Dependencies

**Critical:**
- `firebase/php-jwt` v7.0.5 — JWT signing/verification for widget session tokens (`app/Services/Widget/SessionTokenService.php`)
- `barryvdh/laravel-dompdf` v3.1.2 — PDF generation for billing receipts (`app/Services/Billing/ReceiptService.php`)
- `smalot/pdfparser` v2.12.4 — PDF text extraction for knowledge base document ingestion (`app/Services/Knowledge/DocumentProcessor.php`)
- `tightenco/ziggy` v2.6.2 — route name → URL bridge from PHP to JS (`ziggy-js` alias in `vite.config.js`)

**Infrastructure:**
- `radix-vue` 1.9.17 — headless UI primitives (dialogs, dropdowns, etc.)
- `lucide-vue-next` 1.0.0 — icon library
- `class-variance-authority`, `clsx`, `tailwind-merge` — class composition utilities
- `lodash` — general JS utilities
- `axios` — HTTP client for frontend requests

## Configuration

**Environment:**
- `.env` file present (never read; see `.env.example` for required keys)
- Key env vars: `APP_ENV`, `DB_CONNECTION`, `DB_HOST`, `REDIS_HOST`, `GROQ_API_KEY`, `OLLAMA_URL`, `EMBEDDING_PROVIDER`, `EMBEDDING_MODEL`, `DK_BANK_*`, `WIDGET_SESSION_DUAL_ACCEPT`, `WIDGET_SESSION_TTL`, `TRIAL_*_LIMIT`
- LLM provider selection: `APP_ENV=production` → Groq; any other → Ollama (hardcoded in `app/Services/LLM/ChatService.php`)
- Embedding provider: `EMBEDDING_PROVIDER` env (default: `ollama`) and `EMBEDDING_MODEL` (default: `nomic-embed-text`)

**Build:**
- `vite.config.js` — Vite configuration at project root
- `phpstan.neon` — PHPStan level 6 config with custom `NoRawTenantIdWhere` rule
- `phpunit.xml` — test configuration (SQLite in-memory, sync queue, array cache)
- `pint.json` — Pint formatter config (Laravel preset)

**Config files:**
- `config/app.php`, `config/auth.php`, `config/billing.php`, `config/cache.php`
- `config/cors.php`, `config/database.php`, `config/filesystems.php`, `config/logging.php`
- `config/mail.php`, `config/multitenancy.php`, `config/prism.php`, `config/queue.php`
- `config/services.php`, `config/session.php`, `config/widget.php`

## Platform Requirements

**Development:**
- PHP 8.3+ (8.4.21 used locally)
- Node.js 26 (for Vite build)
- MySQL 8.0+ or SQLite (for dev; pgvector extension needed for vector search)
- Redis (for caching and queues in non-test environments)
- Ollama with `gemma3:4b` model (LLM) and `nomic-embed-text` model (embeddings)

**Production:**
- MySQL 8.0+ with pgvector extension enabled (`CREATE EXTENSION IF NOT EXISTS vector`)
- HNSW index on `knowledge_chunks.embedding` column (created by migration `2025_11_28_060506_create_knowledge_chunks_table.php`)
- Redis (cache driver and queue driver configurable; defaults to `database`)
- `DK_BANK_ENABLED` killswitch (default: `false`); requires PEM private key at `storage/app/dk_pg.pem`
- TrustProxies middleware must be configured before enabling strict widget IP-binding and rate limits (noted in memory: required before WIDGET_SESSION_DUAL_ACCEPT cutover)

---

*Stack analysis: 2026-05-20*
