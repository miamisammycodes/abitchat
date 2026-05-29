# Free Plan Activation & Tenant Lifecycle — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Decouple the Free trial from registration — a registered tenant starts in an inert **Setup** state, explicitly clicks "Start Free Plan" to begin a 14-day **Active** window (which unlocks the api_key + widget), then drops to **view-only Expired** until they subscribe; with in-app + email notifications.

**Architecture:** A `Tenant::lifecycleState()` accessor (enum `Setup|Active|Expired|LegacyTrial`) is the single source of truth for gating and UI. Middleware (`CheckUsageLimits` modified + new `EnsureNotExpired`) enforces it; `UsageTracker::limitsFor` resolves display limits independently. Phase 2 adds banners, three emails, and a daily scheduled command.

**Tech Stack:** Laravel 13 / PHP 8.3, Pest/PHPUnit feature+unit tests (RefreshDatabase, no auto-seed), Vue 3 + Inertia (pnpm), Resend notifications, Pint.

**Spec:** `docs/superpowers/specs/2026-05-28-free-plan-activation-lifecycle-design.md`

---

## Key facts the implementer must know

- **Tests do NOT seed plans** (`RefreshDatabase`, no seeder). Any test needing the Free plan must `Plan::create([...])` it. No `PlanFactory` exists.
- **`Tests\TestCase::createTenantWithUser()` sets `trial_ends_at = now()+14d`** → `actingAsTenantUser()` yields a **LegacyTrial** tenant, NOT Setup. Task 1 adds `actingAsSetupTenant()` for Setup-state tests. Use the right helper or you'll get phantom failures.
- **Free plan is resolved by `slug='free'` AND `price=0`.** Include all `plans` columns in test `Plan::create` (mirror existing tests): name, slug, description, price, billing_period, conversations_limit, messages_per_conversation, knowledge_items_limit, tokens_limit, leads_limit, is_active, is_contact_sales, features, sort_order.
- **Notifications pattern:** class `extends Notification implements NotTenantAware, ShouldQueue`, `use Queueable`, `via()=['mail']`, `toMail()` returns `MailMessage`. Dispatched via `Notification::send($resolver->recipientsFor(EmailType::X, $tenant), new XNotification($tenant))`.
- **`UsageTracker::TYPES` order** = `tokens, conversations, leads, knowledge_items`. `getUsageStats()` returns keys in that order; assert with `assertEquals` (order-insensitive), pull the limit with an explicit closure `->map(fn ($s) => $s['limit'])` (not `->map->limit`).
- **Middleware aliases** live in `bootstrap/app.php` `->withMiddleware(...->alias([...]))`.
- **Scheduled commands** are registered in `routes/console.php` via `Schedule::command('sig')->daily();`.

---

# PHASE 1 — Lifecycle + activation gating (independently shippable)

## Task 1: `TenantLifecycle` enum + `Tenant` accessor + test helper

**Files:**
- Create: `app/Enums/TenantLifecycle.php`
- Modify: `app/Models/Tenant.php`
- Modify: `tests/TestCase.php`
- Test: `tests/Unit/Models/TenantLifecycleStateTest.php`

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/Models/TenantLifecycleStateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\TenantLifecycle;
use App\Models\Plan;
use App\Models\Tenant;
use Tests\TestCase;

class TenantLifecycleStateTest extends TestCase
{
    public function test_setup_when_no_plan_and_no_trial(): void
    {
        $t = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        $this->assertSame(TenantLifecycle::Setup, $t->lifecycleState());
    }

    private function aPlan(): Plan
    {
        // plan_id is a constrained FK (nullOnDelete) — a real Plan row is required.
        return Plan::create([
            'name' => 'Free', 'slug' => 'free', 'description' => null, 'price' => 0,
            'billing_period' => 'monthly', 'conversations_limit' => 100,
            'messages_per_conversation' => 50, 'knowledge_items_limit' => 10,
            'tokens_limit' => 50000, 'leads_limit' => 50, 'is_active' => true,
            'is_contact_sales' => false, 'features' => [], 'sort_order' => 0,
        ]);
    }

    public function test_active_when_plan_not_expired(): void
    {
        $plan = $this->aPlan();
        $t = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active', 'plan_id' => $plan->id, 'plan_expires_at' => now()->addDay()]);
        $this->assertSame(TenantLifecycle::Active, $t->lifecycleState());
    }

    public function test_expired_when_plan_past(): void
    {
        $plan = $this->aPlan();
        $t = Tenant::create(['name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $plan->id, 'plan_expires_at' => now()->subDay()]);
        $this->assertSame(TenantLifecycle::Expired, $t->lifecycleState());
    }

    public function test_legacy_trial_when_trial_active(): void
    {
        $t = Tenant::create(['name' => 'D', 'slug' => 'd', 'status' => 'active', 'trial_ends_at' => now()->addDay()]);
        $this->assertSame(TenantLifecycle::LegacyTrial, $t->lifecycleState());
    }

    public function test_expired_when_legacy_trial_past(): void
    {
        $t = Tenant::create(['name' => 'E', 'slug' => 'e', 'status' => 'active', 'trial_ends_at' => now()->subDay()]);
        $this->assertSame(TenantLifecycle::Expired, $t->lifecycleState());
    }

    public function test_enum_permission_helpers(): void
    {
        $this->assertTrue(TenantLifecycle::Active->allowsWidget());
        $this->assertTrue(TenantLifecycle::LegacyTrial->allowsWidget());
        $this->assertFalse(TenantLifecycle::Setup->allowsWidget());
        $this->assertFalse(TenantLifecycle::Expired->allowsWidget());

        $this->assertTrue(TenantLifecycle::Setup->allowsKnowledgeWrites());
        $this->assertTrue(TenantLifecycle::Active->allowsKnowledgeWrites());
        $this->assertFalse(TenantLifecycle::Expired->allowsKnowledgeWrites());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=TenantLifecycleStateTest`
Expected: FAIL — `App\Enums\TenantLifecycle` and `Tenant::lifecycleState()` don't exist.

- [ ] **Step 3: Create the enum**

Create `app/Enums/TenantLifecycle.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantLifecycle: string
{
    case Setup = 'setup';
    case Active = 'active';
    case Expired = 'expired';
    case LegacyTrial = 'legacy_trial';

    /** Widget API + api_key reveal require a live plan. */
    public function allowsWidget(): bool
    {
        return $this === self::Active || $this === self::LegacyTrial;
    }

    /** Knowledge-base writes are allowed everywhere except Expired (view-only). */
    public function allowsKnowledgeWrites(): bool
    {
        return $this !== self::Expired;
    }
}
```

- [ ] **Step 4: Add the constant + accessor to `Tenant`**

In `app/Models/Tenant.php`, add `use App\Enums\TenantLifecycle;` to imports. Add the constant after `MAX_CUSTOM_INSTRUCTIONS_CHARS`:

```php
    /**
     * Length (in days) of the free window unlocked by "Start Free Plan".
     */
    public const FREE_TRIAL_DAYS = 14;
```

Add this method (e.g. after `hasPlan()`):

```php
    public function lifecycleState(): TenantLifecycle
    {
        if ($this->plan_id !== null) {
            return $this->isPlanExpired() ? TenantLifecycle::Expired : TenantLifecycle::Active;
        }

        if ($this->trial_ends_at !== null) {
            return $this->isOnTrial() ? TenantLifecycle::LegacyTrial : TenantLifecycle::Expired;
        }

        return TenantLifecycle::Setup;
    }
```

- [ ] **Step 5: Add the Setup-tenant test helper**

In `tests/TestCase.php`, add these methods (mirror `createTenantWithUser` / `actingAsTenantUser` but WITHOUT `trial_ends_at`):

```php
    /**
     * Create a tenant in the Setup lifecycle state (no plan, no trial clock).
     */
    protected function createSetupTenantWithUser(): User
    {
        $this->tenant = Tenant::create([
            'name' => 'Setup Company',
            'slug' => 'setup-company',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'name' => 'Setup User',
            'email' => 'setup@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $this->tenant->id,
        ]);

        UserRole::create([
            'user_id' => $this->user->id,
            'role' => Role::Owner,
            'tenant_id' => $this->tenant->id,
        ]);

        return $this->user;
    }

    protected function actingAsSetupTenant(): static
    {
        $this->createSetupTenantWithUser();
        $this->actingAs($this->user);

        return $this;
    }
```

- [ ] **Step 6: Run to verify it passes**

Run: `php artisan test --filter=TenantLifecycleStateTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Enums/TenantLifecycle.php app/Models/Tenant.php tests/TestCase.php tests/Unit/Models/TenantLifecycleStateTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): add TenantLifecycle enum + lifecycleState accessor

Single source of truth for Setup/Active/Expired/LegacyTrial gating, plus
FREE_TRIAL_DAYS and an actingAsSetupTenant() test helper.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Registration → Setup

**Files:**
- Modify: `app/Http/Controllers/Auth/RegisterController.php`
- Test: `tests/Feature/Auth/RegisterControllerTest.php`

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Auth/RegisterControllerTest.php`:

```php
    public function test_registration_lands_in_setup_state(): void
    {
        $this->post(route('register.store'), $this->validPayload())
            ->assertRedirect(route('dashboard'));

        $tenant = Tenant::query()->where('name', 'Test Corp')->firstOrFail();

        $this->assertNull($tenant->plan_id);
        $this->assertNull($tenant->plan_expires_at);
        $this->assertNull($tenant->trial_ends_at);
        $this->assertNull($tenant->trial_activated_at);
        $this->assertSame(\App\Enums\TenantLifecycle::Setup, $tenant->lifecycleState());
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter='RegisterControllerTest::test_registration_lands_in_setup_state'`
Expected: FAIL — current code sets `trial_ends_at`.

- [ ] **Step 3: Make registration create a bare Setup tenant**

In `app/Http/Controllers/Auth/RegisterController.php`, replace the `Tenant::create([...])` call and the following debug log inside the transaction:

```php
            $tenant = Tenant::create([
                'name' => $request->company_name,
                'website_url' => $request->website_url,
                'auto_recrawl' => true,
            ]);

            Log::debug('[Register] (NO $) Tenant created', [
                'tenant_id' => $tenant->id,
                'api_key' => substr($tenant->api_key, 0, 8).'...',
                'lifecycle' => $tenant->lifecycleState()->value,
            ]);
```

(Removes the `'trial_ends_at' => now()->addDays(14)` line and the now-invalid `$tenant->trial_ends_at->toDateString()` log access. No `Plan` import needed here.)

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --filter=RegisterControllerTest`
Expected: all PASS (the pre-existing rollback + role tests are unaffected — they don't assert trial state).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Auth/RegisterController.php tests/Feature/Auth/RegisterControllerTest.php
git commit -m "$(cat <<'EOF'
feat(register): land new tenants in the inert Setup state

Registration no longer starts a trial clock or assigns a plan. Tenants begin
in Setup (no plan_id, no trial_ends_at) and explicitly start the Free plan later.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: "Start Free Plan" action + route (repurpose activateTrial)

**Files:**
- Modify: `app/Http/Controllers/Client/BillingController.php`
- Modify: `routes/web.php`
- Delete: `tests/Feature/Client/ActivateTrialErrorsTest.php`
- Modify: `tests/Feature/BillingTest.php` (replace 3 trial tests)
- Modify: `tests/Feature/Client/AbilityCoverageTest.php` (rename route in the 3 ability tests)
- Test: `tests/Feature/Client/StartFreePlanTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Client/StartFreePlanTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Enums\TenantLifecycle;
use App\Models\Plan;
use Tests\TestCase;

class StartFreePlanTest extends TestCase
{
    private function freePlan(): Plan
    {
        return Plan::create([
            'name' => 'Free', 'slug' => 'free', 'description' => null, 'price' => 0,
            'billing_period' => 'monthly', 'conversations_limit' => 100,
            'messages_per_conversation' => 50, 'knowledge_items_limit' => 10,
            'tokens_limit' => 50000, 'leads_limit' => 50, 'is_active' => true,
            'is_contact_sales' => false, 'features' => [], 'sort_order' => 0,
        ]);
    }

    public function test_start_free_plan_activates_14_day_window(): void
    {
        $this->actingAsSetupTenant();
        $free = $this->freePlan();

        $this->post(route('client.billing.start-free-plan'))
            ->assertRedirect(route('client.billing.index'));

        $this->tenant->refresh();
        $this->assertSame($free->id, $this->tenant->plan_id);
        $this->assertNotNull($this->tenant->trial_activated_at);
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, $this->tenant->plan_expires_at->timestamp, 60);
        $this->assertSame(TenantLifecycle::Active, $this->tenant->lifecycleState());
    }

    public function test_cannot_start_free_plan_twice(): void
    {
        $this->actingAsSetupTenant();
        $this->freePlan();
        $this->tenant->update(['trial_activated_at' => now()]);

        $this->post(route('client.billing.start-free-plan'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_missing_free_plan_flashes_error(): void
    {
        $this->actingAsSetupTenant(); // no Free plan seeded

        $this->post(route('client.billing.start-free-plan'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=StartFreePlanTest`
Expected: FAIL — route `client.billing.start-free-plan` doesn't exist.

- [ ] **Step 3: Replace `activateTrial` with `startFreePlan`**

In `app/Http/Controllers/Client/BillingController.php`, delete the `activateTrial` method and replace it with (keep `use App\Models\Tenant;`, `use App\Models\Plan;`, `use Illuminate\Support\Facades\DB;`):

```php
    /**
     * Start the Free plan — the explicit trial activation. Unlocks the
     * api_key + widget and begins the 14-day window.
     */
    public function startFreePlan(Request $request): RedirectResponse
    {
        $this->authorize(Ability::ManageBilling->value);

        $freePlan = Plan::query()
            ->where('slug', 'free')
            ->where('price', 0)
            ->where('is_active', true)
            ->first();

        if ($freePlan === null) {
            return back()->with('error', 'The free plan is currently unavailable. Please contact support.');
        }

        $tenant = $this->getTenant($request);

        return DB::transaction(function () use ($tenant, $freePlan) {
            $locked = Tenant::whereKey($tenant->id)->lockForUpdate()->first();

            if ($locked->trial_activated_at !== null) {
                return back()->with('error', 'Your free plan has already been used. Please choose a paid plan.');
            }

            if ($locked->plan_id && ! $locked->isPlanExpired()) {
                return back()->with('error', 'You already have an active plan.');
            }

            $locked->update([
                'plan_id' => $freePlan->id,
                'plan_expires_at' => now()->addDays(Tenant::FREE_TRIAL_DAYS),
                'trial_activated_at' => now(),
            ]);

            return redirect()
                ->route('client.billing.index')
                ->with('success', 'Your 14-day free plan is active — your widget is now live!');
        });
    }
```

- [ ] **Step 4: Replace the route**

In `routes/web.php`, inside the `billing` group, replace the `activate-trial` route with:

```php
        Route::post('/start-free-plan', [BillingController::class, 'startFreePlan'])->name('start-free-plan');
```

- [ ] **Step 5: Fix the obsolete tests**

- `git rm tests/Feature/Client/ActivateTrialErrorsTest.php` (its error cases are now covered by `StartFreePlanTest`).
- In `tests/Feature/BillingTest.php`, delete the three methods `test_trial_cannot_be_activated_twice_even_after_expiry`, `test_first_trial_activation_succeeds`, `test_paid_plan_cannot_be_activated_via_trial_route` (they POST to the removed `/billing/activate-trial/{id}` route; the new flow is tested in `StartFreePlanTest`).
- In `tests/Feature/Client/AbilityCoverageTest.php`, the three methods `test_manager_cannot_activate_trial`, `test_agent_cannot_activate_trial`, `test_owner_can_activate_trial` reference `route('client.billing.activate-trial', $plan)`. Update each to the new no-arg route and intent:
  - `test_manager_cannot_activate_trial` body: `$this->post(route('client.billing.start-free-plan'))->assertForbidden();`
  - `test_agent_cannot_activate_trial` body: `$this->post(route('client.billing.start-free-plan'))->assertForbidden();`
  - `test_owner_can_activate_trial` body: remove the `Plan::create([...])`, then `$response = $this->post(route('client.billing.start-free-plan')); $this->assertNotEquals(403, $response->status());`

- [ ] **Step 6: Run to verify green**

Run: `php artisan test --filter=StartFreePlanTest` → PASS.
Run: `php artisan test --filter=BillingTest` and `--filter=AbilityCoverageTest` → PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Client/BillingController.php routes/web.php tests/Feature/Client/StartFreePlanTest.php tests/Feature/BillingTest.php tests/Feature/Client/AbilityCoverageTest.php tests/Feature/Client/ActivateTrialErrorsTest.php
git commit -m "$(cat <<'EOF'
feat(billing): add explicit Start Free Plan action (replaces activateTrial)

POST /billing/start-free-plan looks up the Free plan, enforces the one-shot
trial_activated_at guard, and begins the 14-day window. Renames the route and
updates the affected tests.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: `UsageTracker::limitsFor` — Setup previews Free; expired plans still show plan limits

**Files:**
- Modify: `app/Services/Usage/UsageTracker.php`
- Test: `tests/Unit/Services/UsageTrackerLimitsForTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/UsageTrackerLimitsForTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Usage\UsageTracker;
use Tests\TestCase;

class UsageTrackerLimitsForTest extends TestCase
{
    private function freePlan(): Plan
    {
        return Plan::create([
            'name' => 'Free', 'slug' => 'free', 'description' => null, 'price' => 0,
            'billing_period' => 'monthly', 'conversations_limit' => 100,
            'messages_per_conversation' => 50, 'knowledge_items_limit' => 10,
            'tokens_limit' => 50000, 'leads_limit' => 50, 'is_active' => true,
            'is_contact_sales' => false, 'features' => [], 'sort_order' => 0,
        ]);
    }

    public function test_setup_tenant_previews_free_plan_limits(): void
    {
        $free = $this->freePlan();
        $tenant = Tenant::create(['name' => 'S', 'slug' => 's', 'status' => 'active']);

        $limits = app(UsageTracker::class)->limitsFor($tenant);

        $this->assertEquals(100, $limits['conversations']);
        $this->assertEquals(10, $limits['knowledge_items']);
        $this->assertEquals(50, $limits['leads']);
        $this->assertEquals(50000, $limits['tokens']);
    }

    public function test_expired_plan_tenant_still_shows_plan_limits(): void
    {
        $free = $this->freePlan();
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x', 'status' => 'active',
            'plan_id' => $free->id, 'plan_expires_at' => now()->subDay(),
        ]);

        $limits = app(UsageTracker::class)->limitsFor($tenant);
        $this->assertEquals(100, $limits['conversations']); // plan limits, NOT trial_limits 50
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=UsageTrackerLimitsForTest`
Expected: FAIL — Setup currently returns `trial_limits` (conversations 50, not 100); expired returns `trial_limits` too.

- [ ] **Step 3: Update `limitsFor`**

In `app/Services/Usage/UsageTracker.php`, add `use App\Models\Plan;` to imports and replace `limitsFor`:

```php
    /** @return array<string, int> */
    public function limitsFor(Tenant $tenant): array
    {
        // Plan limits drive display whenever a plan is attached — even if expired
        // (the lifecycle gate, not the limit numbers, enforces the block).
        if ($tenant->plan_id !== null && $tenant->currentPlan) {
            return $this->planLimits($tenant->currentPlan);
        }

        // Legacy implicit-trial tenants keep the config trial limits.
        if ($tenant->trial_ends_at !== null) {
            return config('billing.trial_limits', []);
        }

        // Setup tenants preview the Free plan's limits.
        $free = Plan::query()->where('slug', 'free')->where('price', 0)->first();
        if ($free) {
            return $this->planLimits($free);
        }

        return config('billing.trial_limits', []);
    }

    /** @return array<string, int> */
    private function planLimits(\App\Models\Plan $plan): array
    {
        return [
            self::TYPE_CONVERSATIONS => (int) $plan->conversations_limit,
            self::TYPE_LEADS => (int) $plan->leads_limit,
            self::TYPE_TOKENS => (int) $plan->tokens_limit,
            self::TYPE_KNOWLEDGE_ITEMS => (int) $plan->knowledge_items_limit,
        ];
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --filter=UsageTrackerLimitsForTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Usage/UsageTracker.php tests/Unit/Services/UsageTrackerLimitsForTest.php
git commit -m "$(cat <<'EOF'
feat(usage): Setup previews Free limits; expired plans show plan limits

limitsFor now resolves plan limits whenever plan_id is set (decoupled from
expiry) and previews the Free plan for Setup tenants. Gating is enforced by
lifecycleState, not by these numbers.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: `CheckUsageLimits` — allow Setup KB; block Expired KB; widget needs live plan

**Files:**
- Modify: `app/Http/Middleware/CheckUsageLimits.php`
- Test: `tests/Feature/Http/Middleware/CheckUsageLimitsLifecycleTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Http/Middleware/CheckUsageLimitsLifecycleTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use App\Models\Plan;
use Tests\TestCase;

class CheckUsageLimitsLifecycleTest extends TestCase
{
    private function freePlan(): Plan
    {
        return Plan::create([
            'name' => 'Free', 'slug' => 'free', 'description' => null, 'price' => 0,
            'billing_period' => 'monthly', 'conversations_limit' => 100,
            'messages_per_conversation' => 50, 'knowledge_items_limit' => 10,
            'tokens_limit' => 50000, 'leads_limit' => 50, 'is_active' => true,
            'is_contact_sales' => false, 'features' => [], 'sort_order' => 0,
        ]);
    }

    public function test_setup_tenant_can_reach_knowledge_store(): void
    {
        $this->freePlan();
        $this->actingAsSetupTenant();

        // Empty body → passes check.limits (Setup allowed for KB) and reaches
        // the controller's validation (NOT a billing redirect).
        $response = $this->post(route('client.knowledge.store'), []);
        // Setup-allowed: the request reaches the controller (its own validation),
        // it is NOT short-circuited to billing the way an Expired tenant would be.
        // (Do NOT assert session-missing 'errors' here — controller validation
        // legitimately writes errors once the gate lets the request through.)
        $this->assertNotSame(route('client.billing.plans'), $response->headers->get('Location'));
    }

    public function test_expired_tenant_blocked_from_knowledge_store(): void
    {
        $free = $this->freePlan();
        $this->actingAsSetupTenant();
        $this->tenant->update(['plan_id' => $free->id, 'plan_expires_at' => now()->subDay()]);

        $this->post(route('client.knowledge.store'), [])
            ->assertRedirect(route('client.billing.plans'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=CheckUsageLimitsLifecycleTest`
Expected: `test_setup_tenant_can_reach_knowledge_store` FAILS — Setup currently hits the `!hasPlan && !isOnTrial` subscription block and redirects to billing.plans.

- [ ] **Step 3: Update the middleware**

In `app/Http/Middleware/CheckUsageLimits.php`, add `use App\Enums\TenantLifecycle;`. Replace the subscription-check block (the `if (! $tenant->hasPlan() && ! $tenant->isOnTrial())` block) with lifecycle-aware logic:

```php
        $state = $tenant->lifecycleState();

        if ($type === UsageTracker::TYPE_KNOWLEDGE_ITEMS) {
            // KB management: blocked only when Expired (view-only). Setup,
            // Active, and LegacyTrial may manage KB up to their resolved limits.
            if (! $state->allowsKnowledgeWrites()) {
                return $this->reject(
                    $isJson,
                    'Your free plan has ended. Please subscribe to make changes.',
                    'NO_SUBSCRIPTION',
                    403,
                );
            }
        } elseif (! $state->allowsWidget()) {
            // Widget usage (conversations/tokens/leads) requires a live plan.
            return $this->reject(
                $isJson,
                'Your trial has expired. Please subscribe to a plan to continue.',
                'NO_SUBSCRIPTION',
                403,
            );
        }
```

(The `isActive()` status check above it and the `canRecordUsage` LIMIT_REACHED check below it are unchanged.)

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --filter=CheckUsageLimitsLifecycleTest` → PASS.
Run: `php artisan test --filter=CheckUsageLimits` → existing middleware tests still PASS (LegacyTrial tenants still allowed for all types since `allowsWidget()` is true).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/CheckUsageLimits.php tests/Feature/Http/Middleware/CheckUsageLimitsLifecycleTest.php
git commit -m "$(cat <<'EOF'
feat(limits): lifecycle-aware gate — Setup builds KB, Expired is view-only

Knowledge-item checks now allow Setup tenants (capped at Free) and block only
Expired; widget usage types still require a live plan via allowsWidget().

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: `EnsureNotExpired` middleware on KB mutation routes

**Files:**
- Create: `app/Http/Middleware/EnsureNotExpired.php`
- Modify: `bootstrap/app.php` (alias)
- Modify: `routes/web.php` (apply to KB update/destroy/reprocess/retry)
- Test: `tests/Feature/Http/Middleware/EnsureNotExpiredTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Http/Middleware/EnsureNotExpiredTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use App\Models\KnowledgeItem;
use App\Models\Plan;
use Tests\TestCase;

class EnsureNotExpiredTest extends TestCase
{
    private function freePlan(): Plan
    {
        return Plan::create([
            'name' => 'Free', 'slug' => 'free', 'description' => null, 'price' => 0,
            'billing_period' => 'monthly', 'conversations_limit' => 100,
            'messages_per_conversation' => 50, 'knowledge_items_limit' => 10,
            'tokens_limit' => 50000, 'leads_limit' => 50, 'is_active' => true,
            'is_contact_sales' => false, 'features' => [], 'sort_order' => 0,
        ]);
    }

    public function test_expired_tenant_cannot_delete_knowledge(): void
    {
        $free = $this->freePlan();
        $this->actingAsSetupTenant();
        $this->tenant->update(['plan_id' => $free->id, 'plan_expires_at' => now()->subDay()]);

        $item = KnowledgeItem::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'text',
            'title' => 'Doc',
            'content' => 'hello',
            'status' => 'ready',
        ]);

        $this->delete(route('client.knowledge.destroy', $item))
            ->assertRedirect(route('client.billing.plans'));

        $this->assertDatabaseHas('knowledge_items', ['id' => $item->id]);
    }

    public function test_setup_tenant_can_delete_knowledge(): void
    {
        $this->freePlan();
        $this->actingAsSetupTenant();

        $item = KnowledgeItem::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'text',
            'title' => 'Doc',
            'content' => 'hello',
            'status' => 'ready',
        ]);

        $response = $this->delete(route('client.knowledge.destroy', $item));
        // Setup is allowed to delete: NOT redirected to billing, and the row is gone.
        $this->assertNotSame(route('client.billing.plans'), $response->headers->get('Location'));
        $this->assertDatabaseMissing('knowledge_items', ['id' => $item->id]);
    }
}
```

> Note: confirm `KnowledgeItem`'s required columns/casts against `app/Models/KnowledgeItem.php` before running; adjust the `create([...])` payload to match (e.g. status enum value). The exact columns are not assumed here.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=EnsureNotExpiredTest`
Expected: `test_expired_tenant_cannot_delete_knowledge` FAILS — destroy is currently ungated, so it deletes and redirects to the KB index, not billing.

- [ ] **Step 3: Create the middleware**

Create `app/Http/Middleware/EnsureNotExpired.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\TenantLifecycle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNotExpired
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->user()?->tenant;

        if ($tenant && $tenant->lifecycleState() === TenantLifecycle::Expired) {
            return redirect()
                ->route('client.billing.plans')
                ->with('error', 'Your free plan has ended. Subscribe to a paid plan to make changes.');
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the alias**

In `bootstrap/app.php`, add to the `->alias([...])` array:

```php
            'block.expired' => \App\Http\Middleware\EnsureNotExpired::class,
```

- [ ] **Step 5: Apply to KB mutation routes**

In `routes/web.php`, add `->middleware('block.expired')` to the knowledge update, destroy, reprocess, and retry routes (NOT store — store is already gated by `check.limits:knowledge_items`, which now blocks Expired):

```php
        Route::put('/{item}', [KnowledgeBaseController::class, 'update'])->middleware('block.expired')->name('update');
        Route::delete('/{item}', [KnowledgeBaseController::class, 'destroy'])->middleware('block.expired')->name('destroy');
        Route::post('/{item}/reprocess', [KnowledgeBaseController::class, 'reprocess'])->middleware('block.expired')->name('reprocess');
        Route::post('/{item}/retry', [KnowledgeBaseController::class, 'retry'])->middleware('block.expired')->name('retry');
```

- [ ] **Step 6: Run to verify it passes**

Run: `php artisan test --filter=EnsureNotExpiredTest` → PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/EnsureNotExpired.php bootstrap/app.php routes/web.php tests/Feature/Http/Middleware/EnsureNotExpiredTest.php
git commit -m "$(cat <<'EOF'
feat(limits): block KB mutations for Expired tenants (view-only)

New block.expired middleware on knowledge update/destroy/reprocess/retry
redirects Expired tenants to billing. Setup/Active/LegacyTrial pass through.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Gate api_key reveal to Active (WidgetController, regenerate, Dashboard)

**Files:**
- Modify: `app/Http/Controllers/Client/WidgetController.php`
- Modify: `app/Http/Controllers/Client/DashboardController.php`
- Test: `tests/Feature/Client/WidgetKeyGatingTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Client/WidgetKeyGatingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Models\Plan;
use Tests\TestCase;

class WidgetKeyGatingTest extends TestCase
{
    private function freePlan(): Plan
    {
        return Plan::create([
            'name' => 'Free', 'slug' => 'free', 'description' => null, 'price' => 0,
            'billing_period' => 'monthly', 'conversations_limit' => 100,
            'messages_per_conversation' => 50, 'knowledge_items_limit' => 10,
            'tokens_limit' => 50000, 'leads_limit' => 50, 'is_active' => true,
            'is_contact_sales' => false, 'features' => [], 'sort_order' => 0,
        ]);
    }

    public function test_setup_tenant_does_not_receive_api_key(): void
    {
        $this->actingAsSetupTenant();

        $this->get(route('client.widget.index'))
            ->assertInertia(fn ($page) => $page
                ->where('tenant.api_key', null)
                ->where('widgetUnlocked', false));
    }

    public function test_active_tenant_receives_api_key(): void
    {
        $free = $this->freePlan();
        $this->actingAsSetupTenant();
        $this->tenant->update(['plan_id' => $free->id, 'plan_expires_at' => now()->addDays(14), 'trial_activated_at' => now()]);

        $this->get(route('client.widget.index'))
            ->assertInertia(fn ($page) => $page
                ->where('widgetUnlocked', true)
                ->where('tenant.api_key', fn ($v) => $v !== null)); // whereNot() is not available in this Inertia version
    }

    public function test_setup_tenant_cannot_regenerate_key(): void
    {
        $this->actingAsSetupTenant();
        $before = $this->tenant->api_key;

        $this->post(route('client.widget.regenerate-key'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($before, $this->tenant->fresh()->api_key);
    }
}
```

(Requires `Inertia\Testing\AssertableInertia` — already used elsewhere in the suite via `assertInertia`.)

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=WidgetKeyGatingTest`
Expected: FAIL — `widgetUnlocked` prop missing; Setup currently exposes `api_key` and allows regenerate.

- [ ] **Step 3: Gate `WidgetController`**

In `app/Http/Controllers/Client/WidgetController.php`, update `index`:

```php
    public function index(Request $request): Response
    {
        $tenant = $this->getTenant($request);

        $widgetUnlocked = $tenant->lifecycleState()->allowsWidget();
        $canManageSettings = Gate::allows(Ability::ManageTenantSettings->value);

        return Inertia::render('Client/Widget/Index', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'api_key' => ($widgetUnlocked && $canManageSettings) ? $tenant->api_key : null,
                'settings' => $tenant->settings ?? [],
            ],
            'widgetUnlocked' => $widgetUnlocked,
            'lifecycleState' => $tenant->lifecycleState()->value,
            'embedUrl' => $request->getSchemeAndHttpHost().'/widget/chatbot.js',
            'apiUrl' => $request->getSchemeAndHttpHost(),
            'website_url' => $tenant->website_url,
            'auto_recrawl' => (bool) $tenant->auto_recrawl,
            'last_crawl_session' => CrawlSession::query()->forTenant($tenant)->latest('id')->first()?->only([
                'id', 'status', 'mode', 'pages_indexed', 'pages_discovered', 'started_at', 'completed_at',
            ]),
        ]);
    }
```

In the same file, guard `regenerateApiKey` after the authorize call:

```php
    public function regenerateApiKey(Request $request): RedirectResponse
    {
        $this->authorize(Ability::ManageTenantSettings->value);

        $tenant = $this->getTenant($request);

        if (! $tenant->lifecycleState()->allowsWidget()) {
            return back()->with('error', 'Start a plan to manage your widget key.');
        }

        $tenant->update([
            'api_key' => bin2hex(random_bytes(32)),
        ]);

        return back()->with('success', 'API key regenerated successfully.');
    }
```

- [ ] **Step 4: Gate the Dashboard api_key hint**

In `app/Http/Controllers/Client/DashboardController.php`, change the `api_key` line:

```php
                'api_key' => $tenant->lifecycleState()->allowsWidget()
                    ? substr($tenant->api_key, 0, 8).'...'
                    : null,
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test --filter=WidgetKeyGatingTest` → PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Client/WidgetController.php app/Http/Controllers/Client/DashboardController.php tests/Feature/Client/WidgetKeyGatingTest.php
git commit -m "$(cat <<'EOF'
feat(widget): reveal api_key only when the plan is live

WidgetController + DashboardController hide the api_key (and the hint) outside
Active/LegacyTrial, and regenerate is blocked. Adds widgetUnlocked + lifecycleState
props for the UI.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Frontend — locked widget state + 3-state Free card

**Files:**
- Modify: `resources/js/Pages/Client/Widget/Index.vue`
- Modify: `resources/js/Pages/Client/Billing/Plans.vue`

- [ ] **Step 1: Widget locked state**

In `resources/js/Pages/Client/Widget/Index.vue`, accept the new props (`widgetUnlocked: Boolean`, `lifecycleState: String`) in `defineProps`. Wrap the api_key/embed-snippet section so it only renders when `widgetUnlocked`. When `!widgetUnlocked`, render a locked panel:
- If `lifecycleState === 'setup'`: heading "Your widget is ready to go live" + a `<Link :href="route('client.billing.plans')">` button "Start Free Plan" (or POST `client.billing.start-free-plan` directly via a `useForm().post`).
- If `lifecycleState === 'expired'`: heading "Your free plan ended" + a "Subscribe" `<Link :href="route('client.billing.plans')">`.

Use the existing `Card`/`Button`/`Link` components already imported in the file. Do not reference `tenant.api_key` outside the `v-if="widgetUnlocked"` block (it is `null` otherwise).

- [ ] **Step 2: Plans.vue — 3-state Free card + remove activate-trial wiring**

In `resources/js/Pages/Client/Billing/Plans.vue`:
1. Remove `const trialForm = useForm({})` and the `activateFreeTrial` function.
2. Add a `useForm` for starting the free plan:
   ```js
   const startForm = useForm({})
   function startFreePlan() {
     startForm.post(route('client.billing.start-free-plan'), { preserveScroll: true })
   }
   ```
3. Replace the `price == 0` button branch with three states (inside `v-if="plan.id !== currentPlanId"`, for `plan.price == 0 && $page.props.auth.user.can.manage_billing`):
   - If the tenant has never started (no current plan and trial not used): a `<Button @click="startFreePlan" :disabled="startForm.processing">Start Free Plan</Button>`.
   - Else (trial used / expired): a disabled `<Button disabled variant="outline">Trial used — choose a paid plan</Button>`.

   To know "trial used", pass a prop from `BillingController::plans`: add `'trialUsed' => $tenant->trial_activated_at !== null` to the Inertia render in `app/Http/Controllers/Client/BillingController.php::plans`, and read `props.trialUsed` in Plans.vue. (Add `trialUsed: Boolean` to `defineProps`.)
   - The current-Free-plan "Current Plan" disabled button already renders via the existing `plan.id === currentPlanId` fallback.

- [ ] **Step 3: Build + verify no dead references**

Run:
```bash
pnpm run build && grep -rn "activate-trial\|activateFreeTrial\|trialForm\|Start Free Trial" resources/js/ || echo "CLEAN"
```
Expected: build succeeds; grep prints `CLEAN`.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Client/Widget/Index.vue resources/js/Pages/Client/Billing/Plans.vue app/Http/Controllers/Client/BillingController.php
git commit -m "$(cat <<'EOF'
feat(ui): locked widget state + 3-state Free card

Widget Settings shows Start Free Plan / Subscribe when locked; the pricing
Free card distinguishes Setup (Start), Active (Current), and trial-used states.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: Phase 1 — full suite, Pint, browser smoke

- [ ] **Step 1: Full suite (Layer 2)**

Run: `php artisan test`
Expected: green. If a pre-existing test assumed a fresh tenant had `trial_ends_at`/widget key, fix it to use `actingAsSetupTenant()` or seed the Free plan + start it. `grep -rln "trial_ends_at\|activate-trial" tests/` to find stragglers.

- [ ] **Step 2: Pint**

Run `./vendor/bin/pint --test`; if flagged, `./vendor/bin/pint`, then `php artisan test`, then commit `style(pint): apply auto-fixes` (PR-touched files only).

- [ ] **Step 3: Browser smoke (Layer 3)** — boot `composer dev`, then:
1. Register → dashboard shows **Setup**: usage strip /100,/50,/10,/50k; Widget Settings locked + "Start Free Plan", **no api_key**.
2. Add 2–3 KB items in Setup → allowed; `trial_activated_at` still null.
3. Click "Start Free Plan" → Active: api_key + snippet revealed; widget answers a message.
4. tinker: set `plan_expires_at` to past, clear caches → widget 403; Widget Settings hides key + "Subscribe"; KB edit/delete redirect to billing; leads/conversations still viewable + exportable.
5. Subscribe to a paid plan from Expired → Active again.

Record results in the Phase 1 PR. If the UI can't be exercised, say so.

- [ ] **Step 4: `/simplify` ×2 interleaved with Pint, then open the Phase 1 PR**

Title: `feat: Free plan activation + tenant lifecycle (Setup/Active/Expired)`
Body: Summary, Deploy steps (none — Phase 1 has no migration/env), ⚠️ behavior changes (registration no longer starts a trial; explicit Start Free Plan required; api_key hidden until live; Expired = KB view-only; legacy lapsed tenants gain the KB-write gate), Test plan checklist (smoke above), links to spec + this plan.

---

# PHASE 2 — Notifications (depends on Phase 1)

## Task 10: Migration — email idempotency timestamps

**Files:**
- Create: `database/migrations/2026_05_28_000001_add_trial_notification_timestamps_to_tenants.php`
- Modify: `app/Models/Tenant.php` (fillable + casts)
- Test: `tests/Unit/Models/TenantTrialTimestampsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Models/TenantTrialTimestampsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Tenant;
use Tests\TestCase;

class TenantTrialTimestampsTest extends TestCase
{
    public function test_trial_notification_timestamps_are_castable(): void
    {
        $t = Tenant::create([
            'name' => 'T', 'slug' => 't', 'status' => 'active',
            'trial_expiring_notified_at' => now(),
            'trial_expired_notified_at' => now(),
        ]);

        $this->assertNotNull($t->fresh()->trial_expiring_notified_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $t->fresh()->trial_expiring_notified_at);
        $this->assertNotNull($t->fresh()->trial_expired_notified_at);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=TenantTrialTimestampsTest`
Expected: FAIL — columns/casts don't exist.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_05_28_000001_add_trial_notification_timestamps_to_tenants.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // NOTE: ->after() is a MySQL-ism (no-op on Postgres). Harmless on both.
            $table->timestamp('trial_expiring_notified_at')->nullable()->after('trial_activated_at');
            $table->timestamp('trial_expired_notified_at')->nullable()->after('trial_expiring_notified_at');
        });

        // BLOCKER GUARD: backfill so the FIRST scheduled run does not email
        // tenants whose Free plan already lapsed before this feature shipped
        // (they would receive a stale "your plan has ended" email). Only the
        // expired stamp needs backfilling — already-lapsed tenants can't match
        // the "expiring in ~3 days" (future) reminder window anyway.
        DB::table('tenants')
            ->whereIn('plan_id', function ($q) {
                $q->select('id')->from('plans')->where('slug', 'free')->where('price', 0);
            })
            ->whereNotNull('plan_expires_at')
            ->where('plan_expires_at', '<=', now())
            ->update(['trial_expired_notified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['trial_expiring_notified_at', 'trial_expired_notified_at']);
        });
    }
};
```

- [ ] **Step 4: Add fillable + casts**

In `app/Models/Tenant.php`, add to `$fillable`: `'trial_expiring_notified_at'`, `'trial_expired_notified_at'`. Add to `$casts`: `'trial_expiring_notified_at' => 'datetime'`, `'trial_expired_notified_at' => 'datetime'`.

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test --filter=TenantTrialTimestampsTest` → PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_05_28_000001_add_trial_notification_timestamps_to_tenants.php app/Models/Tenant.php tests/Unit/Models/TenantTrialTimestampsTest.php
git commit -m "$(cat <<'EOF'
feat(tenant): add trial notification idempotency timestamps

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: EmailType entries, RecipientResolver mapping, 3 notifications

**Files:**
- Modify: `app/Enums/EmailType.php`
- Modify: `app/Services/Email/RecipientResolver.php`
- Create: `app/Notifications/Billing/TrialStartedNotification.php`
- Create: `app/Notifications/Billing/TrialExpiringNotification.php`
- Create: `app/Notifications/Billing/TrialExpiredNotification.php`
- Test: `tests/Feature/Notifications/TrialNotificationsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Notifications/TrialNotificationsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\EmailType;
use App\Models\Tenant;
use App\Notifications\Billing\TrialStartedNotification;
use App\Services\Email\RecipientResolver;
use Tests\TestCase;

class TrialNotificationsTest extends TestCase
{
    public function test_resolver_routes_trial_emails_to_owners(): void
    {
        $this->actingAsSetupTenant(); // creates Owner

        $recipients = app(RecipientResolver::class)->recipientsFor(EmailType::TrialStarted, $this->tenant);

        $this->assertCount(1, $recipients);
        $this->assertSame($this->user->id, $recipients->first()->id);
    }

    public function test_trial_started_mail_renders(): void
    {
        $tenant = Tenant::create(['name' => 'Mailco', 'slug' => 'mailco', 'status' => 'active', 'plan_expires_at' => now()->addDays(14)]);

        $mail = (new TrialStartedNotification($tenant))->toMail(new \Illuminate\Notifications\AnonymousNotifiable);
        $this->assertStringContainsString('Mailco', (string) $mail->greeting);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=TrialNotificationsTest`
Expected: FAIL — `EmailType::TrialStarted` and the notification classes don't exist.

- [ ] **Step 3: Add EmailType entries**

In `app/Enums/EmailType.php`, add:

```php
    case TrialStarted = 'trial_started';
    case TrialExpiring = 'trial_expiring';
    case TrialExpired = 'trial_expired';
```

- [ ] **Step 4: Map them in `RecipientResolver`**

In `app/Services/Email/RecipientResolver.php`, add the three cases to the owners-of-tenant arm of the match:

```php
            EmailType::Receipt,
            EmailType::LeadNotification,
            EmailType::Cancellation,
            EmailType::Dunning,
            EmailType::QuotaWarning,
            EmailType::WeeklyDigest,
            EmailType::TrialStarted,
            EmailType::TrialExpiring,
            EmailType::TrialExpired => $this->ownersOf($this->requireTenant($type, $tenant)),
```

- [ ] **Step 5: Create the three notifications**

Create `app/Notifications/Billing/TrialStartedNotification.php`:

```php
<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class TrialStartedNotification extends Notification implements NotTenantAware, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Tenant $tenant) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expires = $this->tenant->plan_expires_at?->format('M j, Y');

        return (new MailMessage)
            ->subject('Your AbitChat free plan is live')
            ->greeting("Hi {$this->tenant->name},")
            ->line('Your 14-day free plan is now active and your chat widget is live.')
            ->line("It runs until **{$expires}**. Add your widget snippet from Widget Settings to go live on your site.")
            ->action('Open Widget Settings', route('client.widget.index'))
            ->line('Thanks for using AbitChat!');
    }
}
```

Create `app/Notifications/Billing/TrialExpiringNotification.php` (same structure):

```php
<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class TrialExpiringNotification extends Notification implements NotTenantAware, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Tenant $tenant) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expires = $this->tenant->plan_expires_at?->format('M j, Y');

        return (new MailMessage)
            ->subject('Your AbitChat free plan ends soon')
            ->greeting("Hi {$this->tenant->name},")
            ->line("Your free plan ends on **{$expires}**. Subscribe now to keep your widget live without interruption.")
            ->action('Choose a plan', route('client.billing.plans'))
            ->line('Your data stays safe either way.');
    }
}
```

Create `app/Notifications/Billing/TrialExpiredNotification.php` (same structure):

```php
<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class TrialExpiredNotification extends Notification implements NotTenantAware, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Tenant $tenant) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your AbitChat free plan has ended')
            ->greeting("Hi {$this->tenant->name},")
            ->line('Your free plan has ended, so your chat widget is now offline.')
            ->line('Subscribe to a paid plan to bring it back online. Your leads, conversations, and knowledge base are all preserved.')
            ->action('Reactivate with a plan', route('client.billing.plans'));
    }
}
```

- [ ] **Step 6: Run to verify it passes**

Run: `php artisan test --filter=TrialNotificationsTest` → PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Enums/EmailType.php app/Services/Email/RecipientResolver.php app/Notifications/Billing/Trial*.php tests/Feature/Notifications/TrialNotificationsTest.php
git commit -m "$(cat <<'EOF'
feat(email): add trial started / expiring / expired notifications

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 12: Fire `TrialStarted` on Start Free Plan

**Files:**
- Modify: `app/Http/Controllers/Client/BillingController.php`
- Test: `tests/Feature/Client/StartFreePlanTest.php` (add)

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Client/StartFreePlanTest.php` (add `use Illuminate\Support\Facades\Notification;` and `use App\Notifications\Billing\TrialStartedNotification;`):

```php
    public function test_starting_free_plan_sends_trial_started_email(): void
    {
        Notification::fake();
        $this->actingAsSetupTenant();
        $this->freePlan();

        $this->post(route('client.billing.start-free-plan'));

        Notification::assertSentTimes(TrialStartedNotification::class, 1);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter='StartFreePlanTest::test_starting_free_plan_sends_trial_started_email'`
Expected: FAIL — no notification sent yet.

- [ ] **Step 3: Send the email after activation**

In `app/Http/Controllers/Client/BillingController.php`, add imports `use App\Enums\EmailType;`, `use App\Notifications\Billing\TrialStartedNotification;`, `use App\Services\Email\RecipientResolver;`, `use Illuminate\Support\Facades\Notification;`. Refactor `startFreePlan` so the transaction returns a result and the email sends after commit:

```php
    public function startFreePlan(Request $request): RedirectResponse
    {
        $this->authorize(Ability::ManageBilling->value);

        $freePlan = Plan::query()
            ->where('slug', 'free')->where('price', 0)->where('is_active', true)->first();

        if ($freePlan === null) {
            return back()->with('error', 'The free plan is currently unavailable. Please contact support.');
        }

        $tenant = $this->getTenant($request);

        $error = DB::transaction(function () use ($tenant, $freePlan): ?string {
            $locked = Tenant::whereKey($tenant->id)->lockForUpdate()->first();

            if ($locked->trial_activated_at !== null) {
                return 'Your free plan has already been used. Please choose a paid plan.';
            }
            if ($locked->plan_id && ! $locked->isPlanExpired()) {
                return 'You already have an active plan.';
            }

            $locked->update([
                'plan_id' => $freePlan->id,
                'plan_expires_at' => now()->addDays(Tenant::FREE_TRIAL_DAYS),
                'trial_activated_at' => now(),
            ]);

            return null;
        });

        if ($error !== null) {
            return back()->with('error', $error);
        }

        $fresh = $tenant->fresh();
        $recipients = app(RecipientResolver::class)->recipientsFor(EmailType::TrialStarted, $fresh);
        Notification::send($recipients, new TrialStartedNotification($fresh));

        return redirect()
            ->route('client.billing.index')
            ->with('success', 'Your 14-day free plan is active — your widget is now live!');
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --filter=StartFreePlanTest` → all PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Client/BillingController.php tests/Feature/Client/StartFreePlanTest.php
git commit -m "$(cat <<'EOF'
feat(billing): email tenant owners when the free plan starts

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 13: Scheduled command for expiring/expired emails

**Files:**
- Create: `app/Console/Commands/SendTrialLifecycleEmails.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Console/SendTrialLifecycleEmailsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Console/SendTrialLifecycleEmailsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Tenant;
use App\Notifications\Billing\TrialExpiredNotification;
use App\Notifications\Billing\TrialExpiringNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendTrialLifecycleEmailsTest extends TestCase
{
    // Free plan via the shared TestCase::createFreePlan() helper (Phase 1).

    private function tenantWithOwner(string $slug, array $attrs): Tenant
    {
        $tenant = Tenant::create(array_merge(['name' => $slug, 'slug' => $slug, 'status' => 'active'], $attrs));
        $user = \App\Models\User::create(['name' => 'O', 'email' => "$slug@x.test", 'password' => bcrypt('x'), 'tenant_id' => $tenant->id]);
        \App\Models\UserRole::create(['user_id' => $user->id, 'role' => \App\Enums\Role::Owner, 'tenant_id' => $tenant->id]);

        return $tenant;
    }

    public function test_sends_reminder_and_expired_once_each(): void
    {
        Notification::fake();
        $free = $this->createFreePlan();

        $expiring = $this->tenantWithOwner('expiring', ['plan_id' => $free->id, 'plan_expires_at' => now()->addDays(2)]);
        $expired = $this->tenantWithOwner('expired', ['plan_id' => $free->id, 'plan_expires_at' => now()->subDay()]);

        $this->artisan('trials:send-lifecycle-emails')->assertExitCode(0);

        Notification::assertSentTimes(TrialExpiringNotification::class, 1);
        Notification::assertSentTimes(TrialExpiredNotification::class, 1);

        // Idempotent: second run sends nothing more.
        $this->artisan('trials:send-lifecycle-emails')->assertExitCode(0);
        Notification::assertSentTimes(TrialExpiringNotification::class, 1);
        Notification::assertSentTimes(TrialExpiredNotification::class, 1);

        $this->assertNotNull($expiring->fresh()->trial_expiring_notified_at);
        $this->assertNotNull($expired->fresh()->trial_expired_notified_at);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=SendTrialLifecycleEmailsTest`
Expected: FAIL — command doesn't exist.

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/SendTrialLifecycleEmails.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EmailType;
use App\Models\Plan;
use App\Models\Tenant;
use App\Notifications\Billing\TrialExpiredNotification;
use App\Notifications\Billing\TrialExpiringNotification;
use App\Services\Email\RecipientResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendTrialLifecycleEmails extends Command
{
    protected $signature = 'trials:send-lifecycle-emails';

    protected $description = 'Email Free-plan tenants ~3 days before expiry and on expiry';

    private const REMINDER_DAYS = 3;

    public function __construct(private readonly RecipientResolver $resolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $freeId = Plan::query()->free()->value('id'); // Plan::free() scope added in Phase 1
        if ($freeId === null) {
            $this->warn('No Free plan found; nothing to do.');

            return self::SUCCESS;
        }

        $reminders = 0;
        $expireds = 0;

        // Reminder: Free-plan tenants expiring within REMINDER_DAYS, not yet reminded.
        Tenant::query()
            ->where('plan_id', $freeId)
            ->whereNotNull('plan_expires_at')
            ->where('plan_expires_at', '>', now())
            ->where('plan_expires_at', '<=', now()->addDays(self::REMINDER_DAYS))
            ->whereNull('trial_expiring_notified_at')
            ->chunkById(100, function ($tenants) use (&$reminders): void {
                foreach ($tenants as $tenant) {
                    Notification::send(
                        $this->resolver->recipientsFor(EmailType::TrialExpiring, $tenant),
                        new TrialExpiringNotification($tenant),
                    );
                    $tenant->forceFill(['trial_expiring_notified_at' => now()])->save();
                    $reminders++;
                }
            });

        // Expired: Free-plan tenants past expiry, not yet notified.
        Tenant::query()
            ->where('plan_id', $freeId)
            ->whereNotNull('plan_expires_at')
            ->where('plan_expires_at', '<=', now())
            ->whereNull('trial_expired_notified_at')
            ->chunkById(100, function ($tenants) use (&$expireds): void {
                foreach ($tenants as $tenant) {
                    Notification::send(
                        $this->resolver->recipientsFor(EmailType::TrialExpired, $tenant),
                        new TrialExpiredNotification($tenant),
                    );
                    $tenant->forceFill(['trial_expired_notified_at' => now()])->save();
                    $expireds++;
                }
            });

        $this->info("Sent {$reminders} reminder(s) and {$expireds} expiry email(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Schedule it**

In `routes/console.php`, add after the existing schedules:

```php
Schedule::command('trials:send-lifecycle-emails')->daily();
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test --filter=SendTrialLifecycleEmailsTest` → PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/SendTrialLifecycleEmails.php routes/console.php tests/Feature/Console/SendTrialLifecycleEmailsTest.php
git commit -m "$(cat <<'EOF'
feat(email): daily command for trial expiring/expired emails (idempotent)

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 14: In-app trial banner

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `resources/js/Layouts/ClientLayout.vue`
- Test: `tests/Feature/InertiaTrialStatusTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/InertiaTrialStatusTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class InertiaTrialStatusTest extends TestCase
{
    public function test_active_free_tenant_shares_trial_status_with_days_remaining(): void
    {
        $free = $this->createFreePlan();
        $this->actingAsSetupTenant();
        $this->tenant->update(['plan_id' => $free->id, 'plan_expires_at' => now()->addDays(5), 'trial_activated_at' => now()]);

        $this->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('trialStatus.state', 'active')
                ->where('trialStatus.days_remaining', fn ($d) => $d >= 4 && $d <= 5));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=InertiaTrialStatusTest`
Expected: FAIL — `trialStatus` prop not shared.

- [ ] **Step 3: Share `trialStatus`**

In `app/Http/Middleware/HandleInertiaRequests.php`, add to the `share()` return array:

```php
            'trialStatus' => fn () => $this->buildTrialStatus($request),
```

And add the private method (uses the Free plan only — paid tenants don't need the banner):

```php
    /**
     * @return array{state: string, days_remaining: int}|null
     */
    private function buildTrialStatus(Request $request): ?array
    {
        $tenant = $request->user()?->tenant;
        if (! $tenant) {
            return null;
        }

        return once(function () use ($tenant): ?array {
            $free = \App\Models\Plan::query()->free()->value('id'); // Plan::free() scope added in Phase 1
            if ($free === null || $tenant->plan_id !== $free) {
                return null; // banner is only for Free-plan tenants
            }

            $state = $tenant->lifecycleState();
            if ($state === \App\Enums\TenantLifecycle::Active) {
                // diffInDays returns a float; ceil so "expires in 5 days" doesn't
                // truncate to 4 a few microseconds after creation.
                return [
                    'state' => 'active',
                    'days_remaining' => (int) max(0, ceil(now()->diffInDays($tenant->plan_expires_at, false))),
                ];
            }
            if ($state === \App\Enums\TenantLifecycle::Expired) {
                return ['state' => 'expired', 'days_remaining' => 0];
            }

            return null;
        });
    }
```

(`once()` matches the existing `latestCrawlSession` pattern in this middleware — the Plan lookup runs at most once per request.)

- [ ] **Step 4: Render the banner**

In `resources/js/Layouts/ClientLayout.vue`, add near the existing usage-warnings banner (around line 202) a block reading `$page.props.trialStatus`:
- `state === 'active'`: an amber strip "Your free plan expires in {{ days_remaining }} day(s). [Subscribe]" linking to `route('client.billing.plans')`.
- `state === 'expired'`: a red strip "Your free plan ended — your widget is offline. [Reactivate]" linking to `route('client.billing.plans')`.

Use existing markup/classes from the adjacent usage banner for visual consistency.

- [ ] **Step 5: Run + build**

Run: `php artisan test --filter=InertiaTrialStatusTest` → PASS.
Run: `pnpm run build` → succeeds.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/HandleInertiaRequests.php resources/js/Layouts/ClientLayout.vue tests/Feature/InertiaTrialStatusTest.php
git commit -m "$(cat <<'EOF'
feat(ui): in-app free-plan countdown / expired banner

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 15: Phase 2 — full suite, Pint, smoke, simplify, PR

- [ ] **Step 1: Full suite** — `php artisan test` green.
- [ ] **Step 2: Pint** — `./vendor/bin/pint --test`; fix + commit `style(pint): apply auto-fixes` if needed.
- [ ] **Step 3: Browser smoke** — start Free plan → "expires in 14 days" banner + `trial_started` email in Mailpit; run `php artisan trials:send-lifecycle-emails` against a tenant expiring in 3 days and a freshly-expired tenant → reminder + expired emails appear once (re-run sends nothing); expired tenant sees the red banner.
- [ ] **Step 4: `/simplify` ×2 interleaved with Pint, then open the Phase 2 PR.**
   Title: `feat: free-plan lifecycle notifications (banner + emails)`
   Body: Summary, Deploy steps (`php artisan migrate --force`; ensure the scheduler/cron runs `schedule:run`; MAIL configured), behavior changes, test plan, links to spec + plan.

---

## Self-review (author)

- **Spec coverage:** D1 (Task 2+3), D2 (`FREE_TRIAL_DAYS`), D3 (Task 5 Setup-allow), D4 (Task 3+7), D5 (Task 5+6 KB view-only; widget already gated), D6 (one-shot guard Task 3), D7 (Tasks 11–14), D8 (Task 7 reveal-gating; key still generated at registration), D9 (Task 2 leaves crawl dispatch intact), D10/D11 (lifecycleState folds legacy + paid-lapse into Expired — Task 1). ✓
- **api_key leak surfaces:** WidgetController + DashboardController both gated (Task 7); regenerate gated. ✓
- **Free card 3 states + trialUsed prop:** Task 8. ✓
- **Test-helper trap:** `actingAsSetupTenant()` added Task 1; used by all Setup tests. ✓
- **Type/name consistency:** `TenantLifecycle::{Setup,Active,Expired,LegacyTrial}`, `allowsWidget()`, `allowsKnowledgeWrites()`, `lifecycleState()`, `FREE_TRIAL_DAYS`, route `client.billing.start-free-plan`, props `widgetUnlocked`/`lifecycleState`/`trialUsed`/`trialStatus` used identically across tasks. ✓
- **Verify-before-run flags:** `KnowledgeItem` create payload (Task 6) and `diffInDays` return type (Task 14) are explicitly called out to check against reality rather than assumed.
- **Out-of-scope honored:** no backfill, no removal of `trial_limits`/`isOnTrial`, no crawl-cap reconciliation, no payment-flow change.
