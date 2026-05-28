# Chatbot SaaS - AI Coding Rules

## Accounts & Seeding

`php artisan db:seed` (and `migrate:fresh --seed`) seeds **only** the reference Plans — never users or tenants. Production must not contain fake accounts.

To get accounts after a fresh seed:
- **Platform admin**: `php artisan admin:create` — interactive (prompts for name, email, password). Creates a `super_admin` with no tenant.
- **Tenant owner + staff**: register through the app (`/register`), then add managers/agents from the dashboard.

Roles: `super_admin` (platform admin, no tenant), `owner`, `manager`, `agent` (tenant-scoped).

## Development URLs

- **App**: http://127.0.0.1:8001
- **Client Dashboard**: http://127.0.0.1:8001/dashboard
- **Admin Dashboard**: http://127.0.0.1:8001/admin/dashboard
- **Widget Test Page**: http://127.0.0.1:8001/widget/test.html
- **Widget Settings**: http://127.0.0.1:8001/widget-settings

---

## Project Overview

AI-Powered WordPress Chatbot SaaS built with Laravel 13+ (VILT Stack).
See `prd.md` for full product requirements.
See `ROADMAP.md` for current build status.

**Tech Stack:**
- Backend: Laravel 13+, PHP 8.3+
- Frontend: Vue 3 (Composition API), Inertia.js, Tailwind CSS v4
- JS toolchain: **pnpm — NOT npm** (`package.json` `packageManager`). Use `pnpm install` / `pnpm run dev` / `pnpm run build`. Node pinned to 22 via `.nvmrc` (Vite 8 requires Node ≥20; Herd's nvm defaults to 16, so a terminal must honor `.nvmrc`).
- Database: MySQL 8.0+, SQLite-vec (vectors), Redis
- Multi-tenancy: Spatie Laravel Multitenancy
- LLM: Prism (Ollama/gemma3:4b dev, Groq/llama-3.1 prod)
- Payments: Laravel Cashier (Stripe)

---

## Architecture

### Multi-Tenancy Pattern
- Single database with `tenant_id` column on tenant-scoped tables
- Use Spatie's tenant-aware middleware for all client routes
- Admin routes are NOT tenant-scoped

### Route Structure
```
/                     → Marketing/landing (public)
/login, /register     → Auth (public)
/dashboard/*          → Client dashboard (tenant-scoped)
/admin/*              → Admin dashboard (platform admin only)
/api/v1/widget/*      → Widget API (public, API key auth)
/api/v1/*             → Client API (Sanctum auth)
/api/admin/*          → Admin API
```

### Directory Structure
```
app/
├── Http/Controllers/
│   ├── Admin/         # Admin dashboard
│   ├── Api/V1/        # API endpoints
│   │   ├── Widget/    # Public widget
│   │   └── Client/    # Tenant API
│   └── Client/        # Client dashboard (Inertia)
├── Models/
├── Services/
│   ├── LLM/           # Prism integration
│   ├── Knowledge/     # RAG, embeddings
│   └── Leads/         # Scoring, capture
└── Jobs/
```

---

## Coding Standards

### PHP (PSR-12 + Laravel)
- Use strict types: `declare(strict_types=1);`
- Type hints for all parameters and return types
- Use Laravel's built-in helpers over raw PHP when available
- Prefer Eloquent over raw queries
- Use Form Requests for validation
- Use Resources for API responses

### Vue 3 (Composition API)
- Always use `<script setup>` syntax
- Use TypeScript types via JSDoc or `defineProps` generic
- Keep components small and focused
- Use `@` alias for imports: `import Component from '@/Components/Component.vue'`

### Naming Conventions
- Controllers: `UserController`, `LeadController`
- Models: `User`, `Lead`, `Conversation`
- Migrations: `create_users_table`, `add_score_to_leads_table`
- Vue pages: `resources/js/Pages/Client/Dashboard.vue`
- Vue components: `resources/js/Components/LeadCard.vue`

---

## Database Conventions

### Tables
- Plural snake_case: `users`, `knowledge_items`, `usage_records`
- Always include: `id`, `created_at`, `updated_at`
- Soft deletes where appropriate: `deleted_at`
- Tenant-scoped tables MUST have `tenant_id` column

### Foreign Keys
- Format: `{singular_table}_id` (e.g., `tenant_id`, `user_id`)
- Always add index on foreign keys
- Use cascading deletes cautiously

---

## Security Requirements

### Critical (from PRD)
- HTTPS only in production
- Tenant data isolation (no cross-tenant access)
- Input sanitization (XSS, SQL injection prevention)
- Rate limiting on all endpoints
- API key authentication for widget
- JWT/Sanctum for dashboard auth

### Best Practices
- Never log sensitive data (passwords, API keys, tokens)
- Use `Hash::make()` for passwords
- Validate all user input
- Escape output in Blade templates
- Use CSRF protection

---

## Debugging Rules

### Console Logging
When debugging issues:

1. **Be specific about what logs are needed** - Never say "check the console" or "expand the Object"
2. **Add targeted console.log statements** with clear labels showing exactly what values to look for
3. **Tell the user the exact log line to find** (e.g., "[ComponentName] State: ...")
4. **List the specific properties needed** from any objects

Example of good debugging:
```javascript
console.log('[DEBUG] Input state:', {
  isDisabled: isInputDisabled,
  isLoading: isLoading,
  hasImage: hasCurrentImage,
});
```
Then tell user: "Look for the line that says '[DEBUG] Input state:' and tell me the values of isDisabled, isLoading, and hasImage"

**Never ask user to:**
- "Check the console for errors"
- "Expand the Object"
- "Look at the console output"

**Always:**
- Add specific logging code
- Tell them the exact log label to find
- List the exact properties you need to see

### Cost Convention for External Calls
Use these prefixes in debug logs:
- `(IS $)` → Calls Firebase/Google/Stripe/Groq servers (billed)
- `(NO $)` → Runs locally in browser/server (free)

Example:
```php
Log::debug('[LLM] (IS $) Sending prompt to Groq', ['tokens' => $tokenCount]);
Log::debug('[Cache] (NO $) Retrieved from Redis', ['key' => $cacheKey]);
```

---

## Legacy Code Cleanup

When implementing new features or making changes:

1. **Always delete legacy code** - Don't leave old code paths "just in case"
2. **Remove backward compatibility** - If migrating to a new pattern, remove the old pattern completely
3. **Delete unused imports** - Remove any type imports or dependencies no longer used
4. **Clean up comments** - Remove "backward compat", "legacy", or "deprecated" comments
5. **No fallback logic** - Avoid `oldPattern ?? newPattern` - choose one and commit

**Rationale:** Legacy code creates bugs, confusion, and maintenance burden. Clean breaks are better than dual-system support.

---

## Development Workflow

Follow this pattern and always state which step we're on:

1. **Make it work** - Fix the bug/logic, get functionality working
2. **Make it right** - Clean up the code/architecture, proper patterns
3. **Make it fast** - Optimize/Cache only after it works correctly

Example:
```
Step: Make it work
Adding basic lead capture functionality...

Step: Make it right
Refactoring to use LeadService class...

Step: Make it fast
Adding Redis caching for lead scores...
```

---

## Feature development process

For any **non-trivial feature** (new module, multi-file change, behavior change that affects merchants or customers, anything with migrations) follow this process. **Skip it for one-line fixes, typos, copy tweaks, or pure refactors with no behavior change** — those go straight to a focused commit.

The shape: **brainstorm → spec → plan → branch → subagent-execute (TDD) → smoke → pint → simplify → pint → simplify → PR → merge → update memory.**

### 1. Brainstorm before writing the spec

Use `superpowers:brainstorming`. Ask the user one question at a time, multiple-choice when possible. Lock decisions on at least:
- Who triggers the change (merchant self-serve, admin-on-behalf, hybrid)
- Scope of v1 (which module / role / gateway — resist building for v2)
- Failure behavior (block at source, allow with banner, hard error)
- Validation strategy (live-test vs save-first, locked vs editable)
- UI placement (sidebar entry, wizard step, modal, settings page)
- Whether to bundle adjacent bug fixes into the same plan

Don't write the spec from your own assumptions — extract them via the brainstorm.

### 2. Spec → `docs/superpowers/specs/YYYY-MM-DD-<topic>-design.md`

Brief but concrete: decisions table, architecture diagram, data model, validation rules, file map, **explicit out-of-scope list**. Commit it. Get user approval before moving on.

### 3. Plan → `docs/superpowers/plans/YYYY-MM-DD-<topic>.md`

Use `superpowers:writing-plans`. Each task is **TDD-shaped**: write failing test → run RED → implement → run GREEN → commit. Include exact code, exact paths, exact commands. No placeholders, no "TBD".

**Self-review the plan before executing.** Reading it with fresh eyes catches more bugs than writing it does — broken test-binds, race conditions, missing migrations, validation gaps. Fix them in the plan before any code is written.

### 4. Add a Task 0 for any unverified assumption

If the plan depends on an external API's response shape, an env-config detail, a third-party behavior, or specific DB state — write a verification-only Task 0 that probes reality and updates the plan **before** Task 1 runs. Field names, error codes, and response envelopes are the most common silent-fail vectors.

### 5. Branch + subagent-driven execution

Use `superpowers:subagent-driven-development`. Fresh implementer subagent per task with full task text + scene-setting context (no "go read the plan"). After each task, dispatch a spec-compliance reviewer; once that's ✅, dispatch a code-quality reviewer. Don't move to the next task until both pass.

Match model to complexity: cheap model for mechanical tasks, sonnet/opus for judgment-heavy ones (multi-file refactors, middleware ordering, anything that requires reading the wider codebase to make a call).

### 6. Three layers of testing — none substitutes for the others

**Layer 1 — TDD inside each task.** Failing test first, then implementation. Always.

**Layer 2 — Run the full suite between tasks**, not just the feature-scoped filter:
```bash
php artisan test
```
This catches when a change in the new feature breaks a pre-existing test elsewhere — typically because the change altered behavior the old test assumed (env fallbacks, default values, missing models). A feature-scoped run hides those.

**Layer 3 — End-to-end browser smoke before the PR.** Open Playwright (or use the dev server manually), hit real local routes, click real buttons, watch real flashes. Pest tests verify code; the browser verifies the *feature*. This catches bugs Pest can't see: wrong route paths between spec and reality, JS form-targeting issues, CSRF cookie scope, modal toggles, flash-message rendering, the difference between routes that 200 vs routes that 200-then-redirect.

### 7. Pint → `/simplify` → Pint → `/simplify` → PR

Both Pint and `/simplify` run twice, interleaved. Pint runs first because it's deterministic and cheap — sending style-broken code into a `/simplify` review wastes the reviewer's tokens on noise that auto-fix would catch.

Each pass is:
1. **Pint** — run `./vendor/bin/pint --test` to see what's flagged. If anything is flagged, run `./vendor/bin/pint` (without `--test`) to fix, then `php artisan test` to confirm nothing broke, then commit as `style(pint): apply auto-fixes`. Scope the commit to PR-touched files only — don't sweep up pre-existing style debt on unrelated files in the same commit.
2. **`/simplify`** — dispatches three parallel reviewers (reuse / quality / efficiency). Apply real fixes; skip stylistic noise with a one-line reason.

Then run both again. The cleanups from the first `/simplify` can introduce new issues (silent catches, stale imports, leftover narrative comments) that the second pass catches, and the new code from those cleanups may itself need Pint.

### 8. PR description follows the "Deploy steps" template

Title under 70 chars. Body has Summary, Deploy steps, ⚠️ behavior changes, Test plan as a checklist, architecture notes, links to spec + plan.

### 9. After merge: update memory

Save a memory entry capturing what shipped + what's still pending (e.g., production deploy, comms to send merchants, follow-up cleanup). Update or remove any prior memory that's now stale. Don't over-document — only what future sessions need that isn't already in the code or git log.

### When to skip steps

- **Brainstorm**: skip if the user provides a tight spec themselves or it's a clearly-scoped bug fix
- **Plan + subagent execution**: skip for single-file changes that don't need multi-task coordination
- **Task 0**: skip if no external API or unverified assumption is involved
- **Browser smoke**: skip for pure backend changes with full Pest coverage and no UI surface
- **`/simplify`**: skip for trivial commits (typo fixes, version bumps, doc edits)
- **Pint**: never skip — it's cheap and deterministic. Even on trivial commits, run `./vendor/bin/pint --test` on the touched files before pushing.
- **Memory update**: skip if no project-state changed (just a code refactor)

### Why each layer earns its keep

- **Brainstorm** — locks design before code, prevents redesigning mid-implementation
- **Self-reviewed plan** — catches plan bugs (broken test patterns, race conditions) at planning cost, not implementation cost
- **Task 0 verification** — catches "the API returns X not Y" before any code commits to the wrong shape
- **Full suite between tasks** — catches regressions in existing tests caused by behavioral changes
- **Browser smoke** — catches bugs that have no test surface (route paths, DOM ordering, form targeting)
- **Pint before `/simplify`** — auto-fixable style noise gets resolved deterministically before the review reads it, so the reviewer can focus on substance instead of flagging spacing and import order
- **Two `/simplify` passes** — catches both original-code issues and cleanup-introduced issues
- **Pint after `/simplify`** — the simplifier may introduce new code (extracted methods, renamed vars, deleted imports) that itself needs style normalization

---

## LLM Integration (Prism)

### Development (Ollama)
```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Ollama, 'gemma3:4b')
    ->withPrompt($message)
    ->asText();
```

### Production (Groq)
```php
$response = Prism::text()
    ->using(Provider::Groq, 'llama-3.1-8b-instant')
    ->withPrompt($message)
    ->asText();
```

### Token Tracking
Always track token usage for billing:
```php
UsageRecord::create([
    'tenant_id' => $tenant->id,
    'type' => 'tokens',
    'quantity' => $response->usage->totalTokens,
]);
```

---

## Testing

### Required Tests
- Unit tests for Services
- Feature tests for API endpoints
- Browser tests for critical user flows

### Test Naming
```php
public function test_user_can_create_lead(): void
public function test_widget_requires_valid_api_key(): void
```

---

## Git Commit Convention

Format:
```
type(scope): description

[optional body]

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
```

Types: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`
