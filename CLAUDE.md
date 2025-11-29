# Chatbot SaaS - AI Coding Rules

## Test Credentials

**Client User:**
- Email: `test@example.com`
- Password: `password`

**Admin User:**
- Email: `admin@example.com`
- Password: `password`

---

## Project Overview

AI-Powered WordPress Chatbot SaaS built with Laravel 12+ (VILT Stack).
See `prd.md` for full product requirements.

**Tech Stack:**
- Backend: Laravel 12+, PHP 8.2+
- Frontend: Vue 3 (Composition API), Inertia.js, Tailwind CSS v4
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
