# Coding Conventions

**Analysis Date:** 2026-05-20

## PHP: Strict Types (Non-Negotiable)

Every PHP file in `app/` and `tests/` opens with:

```php
<?php

declare(strict_types=1);
```

This is enforced across all 103 application files. Any new file that omits `declare(strict_types=1)` violates project standards.

## PHP: Type Hints

All parameters and return types must be explicitly typed. No `mixed` without justification. Nullable types use `?Type` or explicit union `Type|null`. PHPDoc `@return` and `@param` annotations are used for Larastan generic hints that PHP syntax cannot express, not as a substitute for type hints.

```php
// Correct
public function captureFromConversation(Conversation $conversation, array $contactInfo = []): ?Lead

// Incorrect — no return type
public function captureFromConversation(Conversation $conversation)
```

## PHP: Naming Conventions

**Classes:**
- Controllers: `PascalCase` suffixed with `Controller` — `UserController`, `LeadController`, `ChatController`
- Models: `PascalCase` singular — `User`, `Lead`, `Conversation`, `KnowledgeItem`
- Services: `PascalCase` suffixed with `Service` for general services, bare class name for workflow/scoring — `ChatService`, `LeadScoring`, `KnowledgeItemWorkflow`
- Form Requests: `PascalCase` suffixed with `Request` — `RegisterRequest`, `UpdatePlanRequest`
- Jobs: `PascalCase` imperative verb phrase — `GenerateEmbeddings`, `CrawlWebsiteJob`
- Enums: `PascalCase` class, `PascalCase` cases, `'snake_case'` backing values — `KnowledgeItemStatus::Pending = 'pending'`
- Exceptions: `PascalCase` — `TransactionAlreadyProcessed`, `InvalidSessionTokenException`
- Traits: `PascalCase` — `BelongsToTenant`, `BustsTenantUsageCache`
- Middleware: `PascalCase` — `ValidateWidgetDomain`, `RequireWidgetSessionToken`

**Methods:**
- `camelCase` verbs — `captureFromConversation`, `forTenant`, `bootBelongsToTenant`

**Variables:**
- `camelCase` — `$tenantId`, `$apiKey`, `$minted`

## PHP: PSR-12 + Laravel Pint

Code style is enforced by Laravel Pint using the `"laravel"` preset (`pint.json` at repo root, one line). Run before every push:

```bash
./vendor/bin/pint --test    # check only (run first)
./vendor/bin/pint           # auto-fix
```

**Never skip Pint.** The workflow is: Pint → `/simplify` → Pint → `/simplify`. Both passes run because `/simplify` may introduce code that needs Pint normalization.

After running `./vendor/bin/pint` (fix mode), always re-run the test suite:

```bash
php artisan test
```

Only commit Pint fixes as a separate `style(pint): apply auto-fixes` commit scoped to PR-touched files. Do not sweep up pre-existing style debt in unrelated files.

## PHP: Eloquent Over Raw SQL

Prefer Eloquent query builder methods. Never write raw `DB::statement()` or `DB::select()` for business logic queries. The Larastan rule `App\Rules\PHPStan\NoRawTenantIdWhere` (registered in `phpstan.neon`) statically blocks:

```php
// BLOCKED — PHPStan custom rule will error
Model::where('tenant_id', $id)
Model::whereIn('tenant_id', [...])

// CORRECT — always use the trait scope
Model::query()->forTenant($tenant)
```

Every tenant-scoped model uses the `BelongsToTenant` trait (`app/Models/Concerns/BelongsToTenant.php`), which provides `scopeForTenant(Builder $query, Tenant|int $tenant)` and a `creating` boot hook that auto-stamps `tenant_id` from the authenticated user.

## PHP: Form Requests for Validation

All multi-field or reusable validation must use Form Request classes in `app/Http/Requests/`. For simple single-field validation inline `$request->validate([...])` is acceptable:

```php
// Simple — inline is fine
$request->validate(['api_key' => 'required|string']);

// Multi-field — use a Form Request
class RegisterRequest extends FormRequest { ... }
```

Existing Form Requests:
- `app/Http/Requests/Auth/RegisterRequest.php`
- `app/Http/Requests/Auth/LoginRequest.php`
- `app/Http/Requests/Admin/UpdatePlanRequest.php`
- `app/Http/Requests/Admin/StorePlanRequest.php`
- `app/Http/Requests/Admin/Auth/LoginRequest.php`
- `app/Http/Requests/Client/UpdateWebsiteIndexingRequest.php`

## PHP: API Responses

No bare API Resources directory exists yet — widget API controllers return `response()->json([...])` directly. For structured responses, use the `errorResponse()` private method pattern established in `app/Http/Controllers/Api/V1/Widget/ChatController.php`:

```php
private function errorResponse(string $message, string $code, int $status = 400): JsonResponse
{
    return response()->json(['error' => $message, 'code' => $code], $status);
}
```

Inertia responses use `Inertia::render('PagePath/Name', [...])`.

## PHP: Constructor Injection

Services are injected via constructor with `readonly` properties:

```php
public function __construct(
    private readonly ChatService $chatService,
    private readonly UsageTracker $usageTracker,
) {}
```

## PHP: PHPDoc for Larastan Generics

Use `@use`, `@extends`, `@template`, and `@return` annotations where PHP generics don't exist:

```php
/** @use HasFactory<TenantFactory> */
use BelongsToTenant, HasFactory;

/** @return BelongsTo<Tenant, $this> */
public function tenant(): BelongsTo { ... }

/** @extends Factory<Tenant> */
class TenantFactory extends Factory { ... }
```

Declare `@property` PHPDoc on model class bodies for cast columns that Larastan cannot infer:

```php
/**
 * @property CrawlSessionStatus $status
 * @property Carbon|null $completed_at
 */
class CrawlSession extends Model { ... }
```

## PHP: Static Analysis (Larastan / PHPStan)

Config: `phpstan.neon` — level 6, Larastan extension, zero-error baseline (`phpstan-baseline.neon` has 2 lines — empty `ignoreErrors:` array). The custom `NoRawTenantIdWhere` rule blocks all new raw `tenant_id` where-clauses.

**Baseline is at zero — never add entries to `phpstan-baseline.neon`.** Fix the type error instead.

Run:
```bash
./vendor/bin/phpstan analyse
```

## PHP: Database Conventions

Tables: plural `snake_case` (`users`, `knowledge_items`, `usage_records`).

Every table must include `id`, `created_at`, `updated_at`. Soft deletes use `deleted_at` where applicable.

Tenant-scoped tables MUST have `tenant_id` NOT NULL with an index. Foreign key format: `{singular_table}_id`.

Migration names: `create_users_table`, `add_score_to_leads_table`.

Casts are declared as a method (not a property) returning `array<string, string>`:

```php
protected function casts(): array
{
    return ['excluded_at' => 'datetime'];
}
```

## PHP: Enums

All PHP 8.1+ backed enums, always string-backed:

```php
enum KnowledgeItemStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
```

Location: `app/Enums/`.

## PHP: Exception Handling

Named domain exceptions in `app/Exceptions/` grouped by subdomain:
- `app/Exceptions/Billing/` — `TransactionAlreadyProcessed`, `TransactionStatusNotAllowed`, etc.
- `app/Exceptions/Widget/` — `InvalidSessionTokenException`

Callers use `try/catch (\Throwable $e)` for broad catches (not `\Exception`), then log and either rethrow or return a structured error response.

```php
try {
    $result = $this->service->doWork();
} catch (\Throwable $e) {
    Log::error('[Service] (NO $) Operation failed', ['error' => $e->getMessage()]);
    return $this->errorResponse('...', 'ERROR_CODE', 500);
}
```

## PHP: Logging Conventions

All log entries use the `Log` facade with `debug`, `info`, `warning`, or `error` level. Format:

```
'[ComponentName] (COST) Short description'
```

**Cost prefix is mandatory for all debug/info calls:**
- `(IS $)` — call reaches Firebase / Google / Stripe / Groq / DK Bank servers (billed)
- `(NO $)` — runs locally in browser/server (free)

```php
// External/billed
Log::debug('[LLM] (IS $) Generating response', ['tokens' => $count]);
Log::warning('[DK QR] (IS $) verifyByRrn unexpected response code', [...]);

// Local/free
Log::debug('[Widget] (NO $) Conversation started', ['conversation_id' => $id]);
Log::debug('[Auth] (NO $) Login success', ['email' => $email]);
```

Second argument is always an array of structured context — never interpolated strings.

**Never log sensitive data:** passwords, raw API keys, tokens, PII beyond email/phone for leads.

## PHP: Security Patterns

- Use `Hash::make()` for all password storage
- Passwords in `$hidden` on models
- Validate all user input (Form Request or inline `validate()`)
- No direct `$_GET/$_POST` — always via `$request`
- CSRF protection is on by default for web routes
- Widget API auth: API key + JWT session token (`app/Services/Widget/SessionTokenService.php`)
- Admin routes use a separate `AdminUser` guard, not the `User` guard

## PHP: Multi-Tenancy Rules

All tenant-scoped queries MUST use the `forTenant($tenant)` scope, never raw `where('tenant_id', ...)`. The Larastan rule enforces this statically. In controllers, retrieve tenant via API key or authenticated user:

```php
// Canonical find-by-id + tenant isolation pattern
Conversation::query()->whereKey($id)->forTenant($tenant)->first();
```

Admin routes are NOT tenant-scoped — do not apply `forTenant()` in admin controllers.

## PHP: Traits in `app/Models/Concerns/`

- `BelongsToTenant` — tenant relationship, `forTenant` scope, auto-stamp boot hook
- `BustsTenantUsageCache` — fires on `created` event; do NOT manually `Cache::forget` in tests when this trait is present

## PHP: `final` Keyword

Pure value-object services with no expected extension are marked `final`:

```php
final class SessionTokenService { ... }
final class RobotsPolicy { ... }
```

Business services that may be extended or faked are not marked `final`.

## Vue 3: Composition API

All components use `<script setup>` syntax. No Options API.

```vue
<script setup>
import { ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import ClientLayout from '@/Layouts/ClientLayout.vue'

const props = defineProps({
  leads: Object,
  filters: Object,
})
</script>
```

The `@` alias maps to `/resources/js` (configured in `vite.config.js`).

## Vue 3: Import Organization

1. Vue core (`vue`, `ref`, `computed`, etc.)
2. Inertia (`@inertiajs/vue3`)
3. Composables (`@/composables/...`)
4. Layouts (`@/Layouts/...`)
5. UI primitives (`@/Components/ui/...`)
6. Feature components (`@/Components/...`)
7. Icons (`lucide-vue-next`)

## Vue 3: Naming Conventions

- **Pages:** `PascalCase.vue` in `resources/js/Pages/{Area}/` — `resources/js/Pages/Client/Dashboard.vue`
- **Components:** `PascalCase.vue` in `resources/js/Components/` — `resources/js/Components/LeadCard.vue`
- **Layouts:** `PascalCase` suffixed `Layout` — `ClientLayout.vue`, `AdminLayout.vue`
- **Composables:** `camelCase` prefixed `use` — `resources/js/composables/useRoute.js`, `useTheme.js`

## Vue 3: Routing

Use the `useRoute()` composable (wraps Ziggy), not the global `route()` function directly in `<script setup>`:

```js
import { useRoute } from '@/composables/useRoute'
const route = useRoute()
router.get(route('client.leads.index'), { ... })
```

The global `route()` is available in templates via `app.config.globalProperties.route`.

## Vue 3: Reactive State

Use `reactive(new Set())` for collection-type reactive state (not `ref(new Set())`). Use `ref()` for primitives and simple objects.

## Commit Convention

```
type(scope): description (under 70 chars title)

[optional body]

Co-Authored-By: Claude <noreply@anthropic.com>
```

Types: `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `style`

Style-only Pint commits: `style(pint): apply auto-fixes`

## Legacy Code Cleanup Rule

When implementing a new pattern or migrating old code:

1. Delete the old code path entirely — no "just in case" stubs
2. Remove backward compatibility shims completely
3. Delete all unused imports after refactoring
4. Remove comments that say "backward compat", "legacy", or "deprecated"
5. No fallback logic (`oldPattern ?? newPattern`) — choose one and commit

**Rationale:** Dual-system support creates bugs and confusion. Clean breaks are required.

## Development Workflow (Mandatory Sequencing)

Always declare which step is active:

1. **Make it work** — get the functionality working correctly
2. **Make it right** — clean up architecture, proper patterns
3. **Make it fast** — optimize/cache only after it works

For non-trivial features, the full shape is:
**brainstorm → spec → plan → branch → TDD execute → smoke → Pint → `/simplify` → Pint → `/simplify` → PR → merge → memory update**

Skip steps are documented in `CLAUDE.md`. Pint is the one step that is **never** skipped.

---

*Convention analysis: 2026-05-20*
