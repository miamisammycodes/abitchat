# Foundation First Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade all packages to latest (including major bumps), then improve code quality, caching, error handling, widget resilience, and query performance.

**Architecture:** Package updates first (Composer → npm → fix breaks), then system improvements in order of risk: query optimization, caching, code quality, error handling, widget resilience. Each task produces a working, committable state.

**Tech Stack:** Laravel 13, PHP 8.4, Vue 3, Vite 8, Tailwind v4, PHPUnit 13, Prism (LLM), MySQL

**Spec:** `docs/superpowers/specs/2026-03-25-foundation-first-design.md`

---

## Task 1: Composer Package Updates

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Potentially modify: `config/*.php`, `bootstrap/app.php`, `phpunit.xml`

- [ ] **Step 1: Check Laravel 13 PHP requirement**

```bash
composer show laravel/framework --available | grep -i "requires.*php"
```

If PHP 8.3+ required, verify current version: `php -v` (should be 8.4.19).

- [ ] **Step 2: Update composer.json version constraints**

Update `composer.json` to allow latest versions:

```json
"require": {
    "php": "^8.2",
    "laravel/framework": "^13.0",
    "laravel/tinker": "^3.0",
    "prism-php/prism": "*",
    "barryvdh/laravel-dompdf": "*",
    "inertiajs/inertia-laravel": "*",
    "laravel/cashier": "*",
    "smalot/pdfparser": "*",
    "spatie/laravel-multitenancy": "*",
    "tightenco/ziggy": "*"
},
"require-dev": {
    "barryvdh/laravel-debugbar": "^4.0",
    "fakerphp/faker": "*",
    "larastan/larastan": "*",
    "laravel/pail": "*",
    "laravel/pint": "*",
    "laravel/sail": "*",
    "mockery/mockery": "*",
    "nunomaduro/collision": "*",
    "phpunit/phpunit": "^13.0"
}
```

- [ ] **Step 3: Run composer update**

```bash
composer update --with-all-dependencies
```

If dependency conflicts arise, resolve them one at a time. If Laravel 13 has breaking conflicts with other packages, check their changelogs.

- [ ] **Step 4: Follow Laravel upgrade guide**

Check for breaking changes. Common Laravel major version changes:
- Config file updates (compare with `laravel/laravel` skeleton)
- Middleware changes in `bootstrap/app.php`
- Service provider changes
- Deprecated method removals

Run:
```bash
php artisan --version
```
Expected: Laravel Framework 13.x.x

- [ ] **Step 5: Verify PHPUnit 13 compatibility**

Check `phpunit.xml` for deprecated config. PHPUnit 13 may require:
- Removing `backupGlobals` or other deprecated attributes
- Updating test method signatures

Run:
```bash
php artisan test
```

Fix any immediate failures from PHPUnit API changes.

- [ ] **Step 6: Verify Prism API compatibility**

Check if `ChatService.php` (line 65-70, 113-118) still works with latest Prism:

```bash
php artisan tinker --execute="use Prism\Prism\Facades\Prism; echo 'OK';"
```

Check Prism changelog for breaking changes in `asText()`, `asStream()`, `withClientOptions()`, `withMessages()`, `withSystemPrompt()`.

- [ ] **Step 7: Run full application smoke test**

```bash
php artisan route:list --compact
php artisan config:cache
php artisan config:clear
```

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock config/ bootstrap/ phpunit.xml
git commit -m "chore: upgrade Composer packages (Laravel 13, PHPUnit 13, Prism latest)"
```

---

## Task 2: NPM Package Updates

**Files:**
- Modify: `package.json`
- Modify: `package-lock.json`
- Potentially modify: `vite.config.js`
- Potentially modify: Vue files with lucide icon imports

- [ ] **Step 1: Update package.json version constraints**

```json
"devDependencies": {
    "@tailwindcss/vite": "*",
    "autoprefixer": "*",
    "axios": "*",
    "concurrently": "*",
    "laravel-vite-plugin": "^3.0",
    "postcss": "*",
    "tailwindcss": "*",
    "vite": "^8.0"
},
"dependencies": {
    "@inertiajs/vue3": "*",
    "@vitejs/plugin-vue": "*",
    "class-variance-authority": "*",
    "clsx": "*",
    "lodash": "*",
    "lucide-vue-next": "^1.0",
    "radix-vue": "*",
    "tailwind-merge": "*",
    "vue": "*"
}
```

- [ ] **Step 2: Run npm install**

```bash
rm -rf node_modules package-lock.json
npm install
```

- [ ] **Step 3: Check Vite 8 + laravel-vite-plugin 3 config**

Read `vite.config.js` (currently 33 lines). Check if the plugin API changed. Key areas:
- `laravel()` plugin options (input, refresh)
- `@tailwindcss/vite` import
- `vue()` plugin options
- Resolve aliases

If `laravel-vite-plugin` 3.x changed its API, update `vite.config.js` accordingly.

- [ ] **Step 4: Check lucide-vue-next 1.0 icon imports**

The 0.x → 1.0 jump may change icon names or import paths. Search for all icon imports:

```bash
grep -rn "lucide-vue-next" resources/js/
```

Fix any broken imports (icon names may have changed from PascalCase to different naming).

- [ ] **Step 5: Build and verify**

```bash
npm run build
```

Expected: Build completes without errors. If it fails, fix Vite/plugin config issues.

- [ ] **Step 6: Dev server smoke test**

```bash
npm run dev &
# Check for compilation errors in terminal output
kill %1
```

- [ ] **Step 7: Commit**

```bash
git add package.json package-lock.json vite.config.js resources/js/
git commit -m "chore: upgrade NPM packages (Vite 8, laravel-vite-plugin 3, lucide 1.0)"
```

---

## Task 3: Query Optimization — Fix N+1 in KnowledgeBaseController

**Files:**
- Modify: `app/Http/Controllers/Client/KnowledgeBaseController.php:24-36,111-129`

- [ ] **Step 1: Fix N+1 in index() method**

In `app/Http/Controllers/Client/KnowledgeBaseController.php`, the `index()` method around lines 24-36 loads items then calls `$item->chunks()->count()` in a `map()` callback.

Replace the query to use `withCount('chunks')`:

Find the query that fetches items (around line 24-26) and add `->withCount('chunks')`.

In the `map()` callback (around line 33), replace `$item->chunks()->count()` with `$item->chunks_count`.

- [ ] **Step 2: Fix N+1 in show() method**

In the same file around line 124, replace `$item->chunks()->count()` with eager loading.

Add `->loadCount('chunks')` on the item before passing to the view, then use `$item->chunks_count`.

- [ ] **Step 3: Verify fix**

```bash
php artisan tinker --execute="
    \$tenant = \App\Models\Tenant::first();
    \$items = \$tenant->knowledgeItems()->withCount('chunks')->get();
    echo \$items->first()?->chunks_count ?? 'no items';
"
```

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Client/KnowledgeBaseController.php
git commit -m "fix: resolve N+1 query in KnowledgeBaseController using withCount"
```

---

## Task 4: Query Optimization — Add Missing Index and Scope Queries

**Files:**
- Create: `database/migrations/XXXX_add_type_recorded_date_index_to_usage_records_table.php`
- Modify: `app/Http/Controllers/Admin/DashboardController.php:25-46`

- [ ] **Step 1: Create migration for usage_records index**

```bash
php artisan make:migration add_type_recorded_date_index_to_usage_records_table
```

Migration content:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_records', function (Blueprint $table) {
            $table->index(['type', 'recorded_date']);
        });
    }

    public function down(): void
    {
        Schema::table('usage_records', function (Blueprint $table) {
            $table->dropIndex(['type', 'recorded_date']);
        });
    }
};
```

- [ ] **Step 2: Run migration**

```bash
php artisan migrate
```

- [ ] **Step 3: Scope unbounded admin dashboard queries**

In `app/Http/Controllers/Admin/DashboardController.php`, the "total" queries around lines 34-43 scan entire tables.

Keep the "total" counts but add year-scoping to the sum queries to prevent full table scans as data grows. The "this month" queries (already scoped) are fine.

For the sum queries around lines 34-37 (`UsageRecord` tokens sum) and 40-43 (`Transaction` revenue sum), these are already split into "total" and "this month" — leave as-is since they're admin-only and infrequent. The new index will help the most common filtered queries.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/ app/Http/Controllers/Admin/DashboardController.php
git commit -m "perf: add usage_records index for admin dashboard queries"
```

---

## Task 5: Caching — Tenant & Plan Lookups

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Widget/ChatController.php:37,67,115,183,260`
- Modify: `app/Http/Controllers/Client/BillingController.php:25,48`
- Modify: `app/Models/Tenant.php:43-53` (add cache invalidation to existing `booted()`)

- [ ] **Step 1: Add cached tenant lookup helper to ChatController**

In `app/Http/Controllers/Api/V1/Widget/ChatController.php`, the tenant lookup `Tenant::where('api_key', ...)->first()` is repeated on **5 lines**: 37 (`init`), 67 (`startConversation`), 115 (`sendMessage`), 183 (`streamMessage`), and 260 (`endConversation`).

Add a private method at the bottom of the class:

```php
private function findTenantByApiKey(string $apiKey): ?Tenant
{
    return Cache::remember("tenant:api_key:{$apiKey}", 300, function () use ($apiKey) {
        return Tenant::where('api_key', $apiKey)->first();
    });
}
```

Add `use Illuminate\Support\Facades\Cache;` at the top of the file.

Replace all **five** `Tenant::where('api_key', ...)` calls (lines 37, 67, 115, 183, 260) with `$this->findTenantByApiKey(...)`.

- [ ] **Step 2: Add tenant-with-plan caching for dashboard requests**

In `app/Http/Controllers/Client/BillingController.php`, the `index()` method (line 25) calls `$tenant->load('currentPlan')` and `plans()` (line 48) queries `Plan::active()`.

Add caching to the tenant plan lookup in `index()`:

```php
$tenant = Cache::remember("tenant:{$tenant->id}:with_plan", 300, function () use ($tenant) {
    $tenant->load('currentPlan');
    return $tenant;
});
```

Add `use Illuminate\Support\Facades\Cache;` import.

- [ ] **Step 3: Add cache invalidation to existing Tenant model booted()**

In `app/Models/Tenant.php`, the `booted()` method already exists at lines 43-53 with a `creating` listener. **Add** a `saved` listener inside the existing method (do NOT create a new `booted()` method):

```php
protected static function booted(): void
{
    static::creating(function (Tenant $tenant) {
        if (empty($tenant->api_key)) {
            $tenant->api_key = Str::random(64);
        }
        if (empty($tenant->slug)) {
            $tenant->slug = Str::slug($tenant->name);
        }
    });

    static::saved(function (Tenant $tenant) {
        Cache::forget("tenant:api_key:{$tenant->api_key}");
        Cache::forget("tenant:{$tenant->id}:with_plan");
    });
}
```

Add `use Illuminate\Support\Facades\Cache;` import.

- [ ] **Step 3: Verify cache works**

```bash
php artisan tinker --execute="
    use Illuminate\Support\Facades\Cache;
    \$tenant = \App\Models\Tenant::first();
    Cache::put('tenant:api_key:test', \$tenant, 300);
    echo Cache::get('tenant:api_key:test')->name;
    Cache::forget('tenant:api_key:test');
    echo 'OK';
"
```

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Api/V1/Widget/ChatController.php app/Models/Tenant.php
git commit -m "perf: cache tenant lookups by API key (5 min TTL)"
```

---

## Task 6: Caching — Usage Limits & Knowledge Retrieval

**Files:**
- Modify: `app/Http/Middleware/CheckUsageLimits.php:19-73`
- Modify: `app/Services/Knowledge/RetrievalService.php:22-62`
- Modify: `routes/api.php:19-26`

- [ ] **Step 1: Adapt CheckUsageLimits for widget API routes**

The middleware at `app/Http/Middleware/CheckUsageLimits.php` currently uses `$request->user()` (line 21) which returns null for unauthenticated widget requests. It also takes a `$type` parameter (line 19: `handle(Request $request, Closure $next, string $type)`).

Modify the `handle()` method to support API-key-based tenant lookup while **keeping the `$type` parameter**:

```php
public function handle(Request $request, Closure $next, string $type): Response
{
    $user = $request->user();
    $tenant = $user?->tenant;

    // Fallback: look up tenant by API key for widget routes
    if (!$tenant && $request->has('api_key')) {
        $tenant = Cache::remember(
            "tenant:api_key:{$request->input('api_key')}",
            300,
            fn () => Tenant::where('api_key', $request->input('api_key'))->first()
        );
    }

    if (!$tenant) {
        return response()->json(['error' => 'Unauthorized', 'code' => 'NO_TENANT'], 401);
    }

    // Check if tenant has an active plan or is on trial
    if (!$tenant->hasPlan() && !$tenant->isOnTrial()) {
        if ($request->wantsJson()) {
            return response()->json([
                'error' => 'No active subscription',
                'code' => 'NO_SUBSCRIPTION',
            ], 403);
        }
        return redirect()->route('client.billing.plans')
            ->with('error', 'Your trial has expired. Please subscribe to a plan to continue.');
    }

    // Skip limit checks for trial users
    if ($tenant->isOnTrial()) {
        return $next($request);
    }

    // Check the specific limit using cached usage counters
    $usage = Cache::remember("tenant:{$tenant->id}:usage", 60, function () use ($tenant) {
        return [
            'conversations' => $tenant->conversations()->whereMonth('created_at', now()->month)->count(),
            'leads' => $tenant->leads()->whereMonth('created_at', now()->month)->count(),
            'tokens' => $tenant->usageRecords()->where('type', 'tokens')
                ->whereMonth('recorded_date', now()->month)->sum('quantity'),
            'knowledge_items' => $tenant->knowledgeItems()->count(),
        ];
    });

    $plan = $tenant->currentPlan;
    if ($plan) {
        $limits = [
            'conversations' => $plan->conversations_limit,
            'leads' => $plan->leads_limit,
            'tokens' => $plan->tokens_limit,
            'knowledge_items' => $plan->knowledge_items_limit,
        ];

        $limit = $limits[$type] ?? null;
        if ($limit !== null && $limit > 0 && ($usage[$type] ?? 0) >= $limit) {
            $limitMessages = [
                'conversations' => 'You have reached your monthly conversation limit.',
                'knowledge_items' => 'You have reached your knowledge items limit.',
                'leads' => 'You have reached your monthly leads limit.',
                'tokens' => 'You have reached your monthly token limit.',
            ];
            $message = $limitMessages[$type] ?? 'You have reached your usage limit.';

            if ($request->wantsJson()) {
                return response()->json([
                    'error' => 'Limit reached',
                    'message' => $message . ' Please upgrade your plan.',
                    'code' => 'LIMIT_REACHED',
                    'limit_type' => $type,
                ], 403);
            }
            return redirect()->route('client.billing.plans')
                ->with('error', $message . ' Please upgrade your plan.');
        }
    }

    return $next($request);
}
```

Add `use Illuminate\Support\Facades\Cache;` and `use App\Models\Tenant;` imports.

- [ ] **Step 2: Wire middleware to widget API routes with per-route types**

In `routes/api.php`, add the middleware to specific widget routes (around lines 19-26). The `$type` parameter must match the limit being checked for each endpoint:

```php
Route::prefix('v1/widget')->group(function () {
    Route::post('/init', [ChatController::class, 'init']);
    Route::post('/conversation', [ChatController::class, 'startConversation'])->middleware('check.limits:conversations');
    Route::post('/message', [ChatController::class, 'sendMessage'])->middleware('check.limits:tokens');
    Route::get('/message/stream', [ChatController::class, 'streamMessage'])->middleware('check.limits:tokens');
    Route::post('/conversation/end', [ChatController::class, 'endConversation']);
    Route::post('/lead', [LeadController::class, 'capture'])->middleware('check.limits:leads');
});
```

Note: `init` and `endConversation` don't need limit checks — they don't consume limited resources.

- [ ] **Step 4: Add cache invalidation for usage counters**

In `app/Http/Controllers/Api/V1/Widget/ChatController.php`, after creating conversations, messages, or leads, add:

```php
Cache::forget("tenant:{$tenant->id}:usage");
```

Key locations:
- After `Conversation::create()` in `startConversation()` (around line 80)
- After storing usage in `sendMessage()` / after ChatService completes
- After lead capture in `captureLeadFromMessage()` (around line 310)

- [ ] **Step 5: Cache knowledge retrieval results**

In `app/Services/Knowledge/RetrievalService.php`, wrap the `retrieve()` method (lines 22-62):

```php
public function retrieve(Tenant $tenant, string $query, int $limit = 5): array
{
    $cacheKey = "knowledge:{$tenant->id}:" . md5($query);

    return Cache::remember($cacheKey, 600, function () use ($tenant, $query, $limit) {
        // existing retrieval logic...
    });
}
```

Add `use Illuminate\Support\Facades\Cache;` import.

- [ ] **Step 6: Add knowledge cache invalidation**

In `app/Http/Controllers/Client/KnowledgeBaseController.php`, after store/update/destroy operations, flush knowledge cache for the tenant:

```php
// After store(), update(), destroy(), reprocess()
$this->clearKnowledgeCache($tenant);
```

Add helper method. Use a version counter so the cache key changes when knowledge is updated (works with all cache drivers, unlike cache tags):

```php
private function clearKnowledgeCache(Tenant $tenant): void
{
    // Increment the tenant's knowledge version — this invalidates all cached
    // retrieval results because the cache key includes the version number.
    Cache::increment("knowledge_version:{$tenant->id}");
}
```

Then update the cache key in `RetrievalService::retrieve()` to include the version:

```php
$version = Cache::get("knowledge_version:{$tenant->id}", 0);
$cacheKey = "knowledge:{$tenant->id}:v{$version}:" . md5($query);
```

This way, when knowledge changes, all old cache entries naturally expire (10 min TTL) while new queries immediately use fresh data.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/CheckUsageLimits.php app/Services/Knowledge/RetrievalService.php routes/api.php app/Http/Controllers/Api/V1/Widget/ChatController.php app/Http/Controllers/Client/KnowledgeBaseController.php
git commit -m "perf: add caching for usage limits and knowledge retrieval"
```

---

## Task 7: Code Quality — PHPStan Level 6

**Files:**
- Modify: `phpstan.neon`
- Modify: `app/Http/Controllers/Client/EnterpriseInquiryController.php:28`
- Modify: Various files surfaced by PHPStan

- [ ] **Step 1: Fix the existing nullsafe error**

In `app/Http/Controllers/Client/EnterpriseInquiryController.php` line 28, change:

```php
'tenant_id' => $tenant?->id,
```

to:

```php
'tenant_id' => $tenant->id,
```

- [ ] **Step 2: Set reportUnmatchedIgnoredErrors to true**

In `phpstan.neon` line 45, change:

```yaml
reportUnmatchedIgnoredErrors: false
```

to:

```yaml
reportUnmatchedIgnoredErrors: true
```

- [ ] **Step 3: Bump level to 5 and run analysis**

In `phpstan.neon` line 7, change `level: 4` to `level: 5`.

```bash
vendor/bin/phpstan analyse --no-progress 2>&1
```

Fix all errors. Common level 5 additions:
- Method return type mismatches
- Missing argument types
- Incorrect property types

- [ ] **Step 4: Remove unmatched ignore patterns**

After fixing level 5 errors, run PHPStan again. Any ignore patterns that no longer match will now be reported. Remove them from `phpstan.neon`.

- [ ] **Step 5: Bump level to 6 and run analysis**

Change `level: 5` to `level: 6` in `phpstan.neon`.

```bash
vendor/bin/phpstan analyse --no-progress 2>&1
```

Fix all new errors. Level 6 additions:
- Missing type hints on all properties
- Stricter return type checking
- Mixed type usage

- [ ] **Step 6: Remove any new unmatched ignore patterns**

Run PHPStan again after fixes. Remove dead patterns.

- [ ] **Step 7: Verify clean analysis**

```bash
vendor/bin/phpstan analyse --no-progress 2>&1
```

Expected: `[OK] No errors`

- [ ] **Step 8: Commit**

```bash
git add phpstan.neon app/
git commit -m "refactor: bump PHPStan to level 6, fix all errors, clean up ignoreErrors"
```

---

## Task 8: Code Quality — Authorization Policies

**Files:**
- Create: `app/Policies/ConversationPolicy.php`
- Create: `app/Policies/LeadPolicy.php`
- Create: `app/Policies/KnowledgeItemPolicy.php`
- Create: `app/Policies/TransactionPolicy.php`
- Modify: `app/Http/Controllers/Client/KnowledgeBaseController.php`
- Modify: `app/Http/Controllers/Client/LeadController.php`
- Modify: `app/Http/Controllers/Client/BillingController.php`

- [ ] **Step 1: Create ConversationPolicy**

```bash
php artisan make:policy ConversationPolicy --model=Conversation
```

Edit `app/Policies/ConversationPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->tenant_id === $user->tenant_id;
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return $conversation->tenant_id === $user->tenant_id;
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return $conversation->tenant_id === $user->tenant_id;
    }
}
```

- [ ] **Step 2: Create LeadPolicy**

```bash
php artisan make:policy LeadPolicy --model=Lead
```

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function view(User $user, Lead $lead): bool
    {
        return $lead->tenant_id === $user->tenant_id;
    }

    public function update(User $user, Lead $lead): bool
    {
        return $lead->tenant_id === $user->tenant_id;
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $lead->tenant_id === $user->tenant_id;
    }
}
```

- [ ] **Step 3: Create KnowledgeItemPolicy**

```bash
php artisan make:policy KnowledgeItemPolicy --model=KnowledgeItem
```

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\KnowledgeItem;
use App\Models\User;

class KnowledgeItemPolicy
{
    public function view(User $user, KnowledgeItem $item): bool
    {
        return $item->tenant_id === $user->tenant_id;
    }

    public function create(User $user): bool
    {
        return true; // Any authenticated user can create within their tenant
    }

    public function update(User $user, KnowledgeItem $item): bool
    {
        return $item->tenant_id === $user->tenant_id;
    }

    public function delete(User $user, KnowledgeItem $item): bool
    {
        return $item->tenant_id === $user->tenant_id;
    }
}
```

- [ ] **Step 4: Create TransactionPolicy**

```bash
php artisan make:policy TransactionPolicy --model=Transaction
```

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function view(User $user, Transaction $transaction): bool
    {
        return $transaction->tenant_id === $user->tenant_id;
    }
}
```

- [ ] **Step 5: Add authorize calls to client controllers**

In controllers that load individual models, add `$this->authorize()` calls:

**KnowledgeBaseController** — In `show()`, `edit()`, `update()`, `destroy()`, `reprocess()` methods, after `authorizeItem()` call or replacing it:

```php
$this->authorize('view', $item);
```

**LeadController** — In `show()` method:

```php
$this->authorize('view', $lead);
```

**BillingController** — In `downloadReceipt()` method (lines 133-138), replace the manual tenant check with the policy. Remove the now-redundant `$tenant = $this->getTenant($request)` line (line 133) and the manual `if ($transaction->tenant_id !== $tenant->id)` check (lines 136-138), replacing both with:

```php
$this->authorize('view', $transaction);
```

Note: `$this->getTenant($request)` is still needed in other methods of this controller — only remove it from `downloadReceipt()` where the policy replaces its purpose.

- [ ] **Step 6: Verify policies auto-discover**

```bash
php artisan policy:list 2>/dev/null || echo "Check manually"
```

If policies aren't auto-discovered, register them in `app/Providers/AuthServiceProvider.php` (or `AppServiceProvider` in Laravel 13).

- [ ] **Step 7: Commit**

```bash
git add app/Policies/ app/Http/Controllers/Client/
git commit -m "feat: add authorization policies for tenant-scoped resources"
```

---

## Task 9: Error Handling — ChatService Retry Logic

**Files:**
- Modify: `app/Services/LLM/ChatService.php:64-88`

- [ ] **Step 1: Add retry to generateResponse()**

In `app/Services/LLM/ChatService.php`, replace the try-catch block in `generateResponse()` (lines 64-88) with retry logic:

```php
try {
    $response = retry(
        times: 3,
        callback: function (int $attempt) use ($systemPrompt, $messages, $conversation) {
            if ($attempt > 1) {
                Log::warning('[LLM] (IS $) Retry attempt', [
                    'conversation_id' => $conversation->id,
                    'attempt' => $attempt,
                ]);
            }

            return Prism::text()
                ->using($this->provider, $this->model)
                ->withSystemPrompt($systemPrompt)
                ->withMessages($messages)
                ->withClientOptions(['timeout' => 60])
                ->asText();
        },
        sleepMilliseconds: fn (int $attempt) => match ($attempt) {
            1 => 1000,
            2 => 2000,
            default => 4000,
        },
        when: function (\Throwable $e) {
            // Only retry on transient failures
            $message = $e->getMessage();
            return str_contains($message, '429')
                || str_contains($message, '500')
                || str_contains($message, '503')
                || str_contains($message, 'Connection')
                || str_contains($message, 'timeout')
                || str_contains($message, 'CURL');
        },
    );

    $this->trackUsage($tenant, $conversation, $response->usage);

    Log::debug('[LLM] (IS $) Response generated', [
        'conversation_id' => $conversation->id,
        'tokens' => $response->usage?->totalTokens ?? 0,
    ]);

    return $response->text;
} catch (\Exception $e) {
    Log::error('[LLM] Response generation failed after retries', [
        'conversation_id' => $conversation->id,
        'error' => $e->getMessage(),
    ]);

    return $this->getFallbackResponse();
}
```

Note: `streamResponse()` keeps its existing single-attempt pattern (no retry once chunks are sent).

- [ ] **Step 2: Verify retry helper is available**

```bash
php artisan tinker --execute="echo function_exists('retry') ? 'OK' : 'MISSING';"
```

Expected: `OK` (Laravel's global helper)

- [ ] **Step 3: Commit**

```bash
git add app/Services/LLM/ChatService.php
git commit -m "feat: add retry with exponential backoff to ChatService generateResponse"
```

---

## Task 10: Error Handling — Standardize Widget API Responses

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Widget/ChatController.php`

- [ ] **Step 1: Add standardized error response helper**

Add a private method to `ChatController`:

```php
private function errorResponse(string $message, string $code, int $status = 400): \Illuminate\Http\JsonResponse
{
    return response()->json([
        'error' => $message,
        'code' => $code,
    ], $status);
}
```

- [ ] **Step 2: Update init() error responses**

Around line 39-41, replace:

```php
return response()->json(['error' => 'Invalid API key'], 401);
```

with:

```php
return $this->errorResponse('Invalid API key', 'TENANT_NOT_FOUND', 401);
```

- [ ] **Step 3: Add try-catch to startConversation()**

Wrap the body of `startConversation()` (around lines 60-95) in a try-catch:

```php
try {
    // existing code...
} catch (\Exception $e) {
    Log::error('[Widget] Failed to start conversation', ['error' => $e->getMessage()]);
    return $this->errorResponse('Failed to start conversation', 'CONVERSATION_ERROR', 500);
}
```

- [ ] **Step 4: Add try-catch to sendMessage()**

Wrap the body of `sendMessage()` (around lines 100-163) in a try-catch:

```php
try {
    // existing code...
} catch (\Exception $e) {
    Log::error('[Widget] Failed to send message', ['error' => $e->getMessage()]);
    return $this->errorResponse('Failed to process message', 'MESSAGE_ERROR', 500);
}
```

- [ ] **Step 5: Standardize all error returns**

Search through the controller for any remaining `response()->json(['error' => ...])` calls and replace with `$this->errorResponse()` using appropriate codes:

- `TENANT_NOT_FOUND` (401)
- `CONVERSATION_NOT_FOUND` (404)
- `CONVERSATION_ERROR` (500)
- `MESSAGE_ERROR` (500)
- `VALIDATION_ERROR` (422)

**Note:** The `streamMessage()` method (line 168) returns a `StreamedResponse` for SSE, not JSON. Its error responses at lines 186-192 should remain as SSE-formatted errors (they already return proper error data within the stream), not `$this->errorResponse()`. Only standardize the pre-stream validation errors (tenant/conversation not found) which are returned as JSON before streaming begins.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/Widget/ChatController.php
git commit -m "feat: standardize widget API error responses with error codes"
```

---

## Task 11: Widget Resilience — apiCall Retry, Timeout & Offline Detection

**Files:**
- Modify: `public/widget/chatbot.js:495-524,620-636`

- [ ] **Step 1: Rewrite apiCall() with retry and timeout**

Replace the `apiCall()` method (lines 620-636) in `public/widget/chatbot.js`:

```javascript
async apiCall(endpoint, options = {}) {
    const url = this.config.baseUrl + endpoint;
    const timeout = options.timeout || 75000; // 75s default, override for streaming
    const maxRetries = options.retries ?? 2;
    let lastError;

    for (let attempt = 0; attempt <= maxRetries; attempt++) {
        if (attempt > 0) {
            this.showReconnecting(true);
            const delay = attempt === 1 ? 1000 : 3000;
            await new Promise(r => setTimeout(r, delay));
        }

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), timeout);

        try {
            const response = await fetch(url, {
                ...options,
                signal: controller.signal,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    ...options.headers
                }
            });

            clearTimeout(timeoutId);
            this.showReconnecting(false);

            if (!response.ok) {
                // Don't retry 4xx errors
                if (response.status >= 400 && response.status < 500) {
                    const body = await response.json().catch(() => ({}));
                    throw new Error(body.error || `HTTP ${response.status}`);
                }
                throw new Error(`HTTP ${response.status}`);
            }

            return response.json();
        } catch (err) {
            clearTimeout(timeoutId);
            lastError = err;

            // Don't retry aborted requests or 4xx errors
            if (err.name === 'AbortError') {
                lastError = new Error('Request timed out');
                break;
            }
            if (err.message && err.message.startsWith('HTTP 4')) {
                break;
            }
        }
    }

    this.showReconnecting(false);
    throw lastError;
}
```

- [ ] **Step 2: Add reconnecting indicator**

Add `showReconnecting()` method:

```javascript
showReconnecting(show) {
    let indicator = this.container?.querySelector('.chatbot-reconnecting');
    if (show && !indicator) {
        indicator = document.createElement('div');
        indicator.className = 'chatbot-reconnecting';
        indicator.textContent = 'Reconnecting...';
        const messagesContainer = this.container?.querySelector('.chatbot-messages');
        if (messagesContainer) {
            messagesContainer.parentNode.insertBefore(indicator, messagesContainer.nextSibling);
        }
    } else if (!show && indicator) {
        indicator.remove();
    }
}
```

Add CSS for the indicator in the styles section:

```css
.chatbot-reconnecting {
    text-align: center;
    padding: 4px 8px;
    font-size: 12px;
    color: #f59e0b;
    background: #fef3c7;
    border-radius: 4px;
    margin: 4px 12px;
}
```

- [ ] **Step 3: Add offline detection**

Add to the `initializeWidget()` method (or after widget DOM is created):

```javascript
window.addEventListener('offline', () => this.setOfflineState(true));
window.addEventListener('online', () => this.setOfflineState(false));
if (!navigator.onLine) this.setOfflineState(true);
```

Add `setOfflineState()` method:

```javascript
setOfflineState(offline) {
    const input = this.container?.querySelector('.chatbot-input input');
    const sendBtn = this.container?.querySelector('.chatbot-input button');
    let banner = this.container?.querySelector('.chatbot-offline-banner');

    if (offline) {
        if (input) input.disabled = true;
        if (sendBtn) sendBtn.disabled = true;
        if (!banner) {
            banner = document.createElement('div');
            banner.className = 'chatbot-offline-banner';
            banner.textContent = "You're offline";
            const messagesContainer = this.container?.querySelector('.chatbot-messages');
            if (messagesContainer) {
                messagesContainer.parentNode.insertBefore(banner, messagesContainer.nextSibling);
            }
        }
    } else {
        if (input) input.disabled = false;
        if (sendBtn) sendBtn.disabled = false;
        if (banner) banner.remove();
    }
}
```

Add CSS:

```css
.chatbot-offline-banner {
    text-align: center;
    padding: 6px 8px;
    font-size: 12px;
    color: #ef4444;
    background: #fef2f2;
    border-radius: 4px;
    margin: 4px 12px;
}
```

- [ ] **Step 4: Add double-send prevention and message preservation**

Rewrite the `sendMessage()` method (lines 495-524). The current code clears the input at line 499 **before** the API call, which means it's lost on failure. Save the message first, then clear only on success:

```javascript
async sendMessage() {
    const message = this.elements.input.value.trim();
    if (!message || this.state.isLoading || this.state.sending) return;

    this.state.sending = true;
    const savedMessage = message; // Save before clearing
    this.elements.input.value = '';
    this.addMessage(message, 'user');
    this.setLoading(true);
    this.showTypingIndicator();

    try {
        const response = await this.apiCall('/api/v1/widget/message', {
            method: 'POST',
            body: JSON.stringify({
                api_key: this.config.apiKey,
                conversation_id: this.state.conversationId,
                message: message
            })
        });

        this.hideTypingIndicator();
        this.addMessage(response.response, 'bot');
    } catch (error) {
        console.error('[Chatbot] Failed to send message:', error);
        this.hideTypingIndicator();
        // Restore message to input on failure
        this.elements.input.value = savedMessage;
        this.addMessage('Sorry, I couldn\'t send your message. Please try again.', 'bot');
    } finally {
        this.state.sending = false;
        this.setLoading(false);
        this.elements.input.focus();
    }
},
```

- [ ] **Step 5: Update streaming calls to use longer timeout**

Where the widget calls the stream endpoint, pass a longer timeout:

```javascript
const response = await this.apiCall('/api/v1/widget/message/stream', {
    timeout: 90000, // 90s for streaming
    retries: 0,     // Don't retry streams
    // ... other options
});
```

- [ ] **Step 6: Test widget manually**

Open `http://127.0.0.1:8001/widget/test.html` and verify:
1. Normal message send works
2. Turning off network shows offline banner
3. Turning network back on removes banner
4. Double-clicking send doesn't send twice

- [ ] **Step 7: Commit**

```bash
git add public/widget/chatbot.js
git commit -m "feat: add widget resilience (retry, timeout, offline detection, double-send prevention)"
```

---

## Task 12: Final Verification

**Files:** None (verification only)

- [ ] **Step 1: Run PHPStan**

```bash
vendor/bin/phpstan analyse --no-progress
```

Expected: `[OK] No errors`

- [ ] **Step 2: Run full build**

```bash
npm run build
```

Expected: Build succeeds

- [ ] **Step 3: Run Laravel smoke tests**

```bash
php artisan route:list --compact
php artisan config:cache && php artisan config:clear
```

- [ ] **Step 4: Run existing tests**

```bash
php artisan test
```

- [ ] **Step 5: Manual smoke test**

1. Start dev server: `composer dev`
2. Login as client (`test@example.com` / `password`)
3. Visit dashboard, knowledge base, leads, billing
4. Open widget test page, send a message
5. Login as admin (`admin@example.com` / `password`)
6. Visit admin dashboard, check metrics load

- [ ] **Step 6: Commit any final fixes**

```bash
git add -A
git commit -m "chore: final verification fixes for foundation-first enhancement"
```

- [ ] **Step 7: Push all changes**

```bash
git push
```
