# Billing-Cluster High-Severity Fixes — 2026-05-11

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close three plan/billing HIGHs from the 2026-05-09 audit:

- **H-NEW-9** — `UsageTracker::remaining` treats limit `0` as unlimited. A misconfigured plan with `conversations_limit=0` (or any other type) silently grants infinite usage. Only `-1` should be the unlimited sentinel.
- **H-NEW-10** — `Admin\ClientController::updatePlan` overwrites `plan_expires_at` with `now()->addMonth()` whenever the admin saves with a blank `expires_at`. A tenant with 9 months left on a yearly plan is silently collapsed to a 30-day window.
- **H-NEW-11** — `Admin\TransactionController::approve` doesn't verify the plan is still active. An admin can deactivate Plan A, then approve a pending transaction for Plan A, subscribing the tenant to a removed plan.

**Architecture:**
- For H-NEW-9: change `remaining()` so only `-1` (the explicit sentinel) yields `null`; treat `0` and any positive integer as a real limit. Update the docblock to match.
- For H-NEW-10: when `expires_at` is blank, derive the expiry by extending from the existing future plan (`plan_expires_at` if future, else `now()`) by the plan's `billing_period` (yearly → 12 months, else 1). Don't collapse remaining time. Mirror the math already used in `Tenant::extendPlan` to keep the two paths consistent.
- For H-NEW-11: inside the `DB::transaction` block in `approve`, assert `$locked->plan && $locked->plan->is_active`. Throw a `PLAN_INACTIVE` `RuntimeException` and surface a flash-error analogous to the existing `ALREADY_PROCESSED` path.

**Tech Stack:** Laravel 13, PHPUnit. Tests live under `tests/Feature/Admin/*` and `tests/Unit/Services/Usage/*`. Existing `BillingTest.php` covers some billing flows but doesn't exercise these edge cases.

**Branch base:** `main`. If PR #5 or PR #6 land before this PR is reviewed, rebase to pick up the updated `Tenant::extendPlan` signature (drops the `$months` param). The patches below avoid hard-coupling to PR #5's signature change — they read `$plan->billing_period` directly so both pre- and post-PR-#5 states work.

---

## Pre-flight: branch + baseline

- [ ] **Pre-flight 1: Branch off main**

```bash
git checkout main && git pull --ff-only && git checkout -b fix/billing-highs-2026-05-11
```

- [ ] **Pre-flight 2: Baseline test run**

```bash
php artisan test
```

Expected: green (172 baseline on main; 181/193 if PR #5/#6 have merged — adjust as appropriate).

---

## Task 1: `UsageTracker::remaining` only treats `-1` as unlimited

**Bug:** `$limit <= 0` collapses both "limit is 0 (block all)" and "limit is -1 (unlimited)" into the same branch — `return null`. The middleware then treats the tenant as having unlimited usage. The doc comment is also wrong; the sentinel for unlimited is `-1` only.

**Files:**
- Modify: `app/Services/Usage/UsageTracker.php:111-123`
- Test: `tests/Unit/Services/Usage/UsageTrackerRemainingTest.php` (create)

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Services/Usage/UsageTrackerRemainingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Usage;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Usage\UsageTracker;
use Tests\TestCase;

class UsageTrackerRemainingTest extends TestCase
{
    private function makeTenantWithPlan(array $limits): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Quota Co',
            'slug' => 'quota-co',
            'status' => 'active',
            'trial_ends_at' => null,
        ]);

        $plan = Plan::create([
            'name' => 'Test Plan',
            'slug' => 'test-plan-' . uniqid(),
            'price' => 100,
            'billing_period' => 'monthly',
            'is_active' => true,
            'conversations_limit' => $limits['conversations_limit'] ?? 100,
            'leads_limit' => $limits['leads_limit'] ?? 50,
            'tokens_limit' => $limits['tokens_limit'] ?? 10000,
            'knowledge_items_limit' => $limits['knowledge_items_limit'] ?? 5,
        ]);

        $tenant->update([
            'plan_id' => $plan->id,
            'plan_expires_at' => now()->addMonth(),
        ]);

        return $tenant->fresh();
    }

    public function test_negative_one_means_unlimited(): void
    {
        $tenant = $this->makeTenantWithPlan(['conversations_limit' => -1]);

        $this->assertNull(
            app(UsageTracker::class)->remaining($tenant, UsageTracker::TYPE_CONVERSATIONS),
            '-1 must signal unlimited (returns null)'
        );
    }

    public function test_zero_means_block_all_not_unlimited(): void
    {
        $tenant = $this->makeTenantWithPlan(['conversations_limit' => 0]);

        $this->assertSame(
            0,
            app(UsageTracker::class)->remaining($tenant, UsageTracker::TYPE_CONVERSATIONS),
            'A limit of 0 must yield 0 remaining (blocked), not null (unlimited)'
        );
    }

    public function test_positive_limit_returns_remainder(): void
    {
        $tenant = $this->makeTenantWithPlan(['conversations_limit' => 100]);

        $this->assertSame(
            100,
            app(UsageTracker::class)->remaining($tenant, UsageTracker::TYPE_CONVERSATIONS),
            'With no usage and a limit of 100, remaining must be 100'
        );
    }

    public function test_missing_type_returns_null(): void
    {
        $tenant = $this->makeTenantWithPlan([]);

        $this->assertNull(
            app(UsageTracker::class)->remaining($tenant, 'unknown_type'),
            'Unknown types are treated as no-limit (null)'
        );
    }
}
```

- [ ] **Step 2: Run, confirm `test_zero_means_block_all_not_unlimited` FAILS**

```bash
php artisan test --filter=UsageTrackerRemainingTest
```

Expected: that test fails because `$limit <= 0` returns `null` for limit `0`.

- [ ] **Step 3: Patch `remaining()`**

Edit `app/Services/Usage/UsageTracker.php:111-123` — replace the method with:

```php
    /**
     * Remaining quota for the current period.
     * Returns null only when the type is unlimited (limit absent or -1).
     * A limit of 0 means "block all" and returns 0.
     */
    public function remaining(Tenant $tenant, string $type): ?int
    {
        $limit = $this->limitsFor($tenant)[$type] ?? null;
        if ($limit === null || $limit === -1) {
            return null;
        }
        $used = $this->monthlyUsage($tenant)[$type] ?? 0;
        return max(0, $limit - $used);
    }
```

The only behavior change: `$limit === 0` now reaches the `max(0, $limit - $used)` path and returns `0` (the user is blocked) instead of `null` (unlimited).

- [ ] **Step 4: Run tests, confirm all PASS**

```bash
php artisan test --filter=UsageTrackerRemainingTest
```

Expected: all 4 tests green.

- [ ] **Step 5: Update the existing pre-existing test that relied on the old semantics**

`tests/Unit/Services/Usage/UsageTrackerTest.php:141-147` currently asserts that a trial limit of `tokens => 0` returns null (treating 0 as unlimited). After the patch it returns `0`. Update both the method name and the first assertion:

```php
    public function test_remaining_returns_null_only_for_minus_one_or_missing_limit(): void
    {
        config(['billing.trial_limits' => ['tokens' => 0, 'leads' => -1]]);
        $this->assertSame(0, $this->tracker->remaining($this->tenant, 'tokens'));
        $this->assertNull($this->tracker->remaining($this->tenant, 'leads'));
        $this->assertNull($this->tracker->remaining($this->tenant, 'knowledge_items'));
    }
```

The `leads` (−1 → null) and `knowledge_items` (missing → null) assertions stay the same; only the `tokens` (0) line changes from `assertNull` to `assertSame(0, ...)`. This must ship in the same commit as the `UsageTracker` patch so the full suite stays green.

- [ ] **Step 6: Full suite**

```bash
php artisan test
```

Expected: green. Also grep config (`config/billing.php`) and seeders for any `'limit' => 0` literal that meant "unlimited" — those need to flip to `-1`. (Probably none, but verify.)

- [ ] **Step 7: Commit**

```bash
git add app/Services/Usage/UsageTracker.php tests/Unit/Services/Usage/UsageTrackerRemainingTest.php tests/Unit/Services/Usage/UsageTrackerTest.php
git commit -m "$(cat <<'EOF'
fix(usage): only -1 means unlimited in UsageTracker::remaining

The $limit <= 0 guard collapsed two distinct sentinels: -1 (unlimited)
and 0 (block all). A plan misconfigured with conversations_limit=0
silently granted unlimited usage. Now only -1 yields null; 0 yields 0
remaining (blocked), matching the documented Plan model semantics.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `Admin\ClientController::updatePlan` preserves remaining plan time

**Bug:** When the admin saves a tenant's plan with a blank `expires_at`, the controller writes `plan_expires_at = now()->addMonth()`. A tenant in month 3 of a yearly plan loses 9 months. The fallback also ignores `billing_period`.

**Files:**
- Modify: `app/Http/Controllers/Admin/ClientController.php:155-171`
- Test: `tests/Feature/Admin/UpdatePlanPreservesExpiryTest.php` (create)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Admin/UpdatePlanPreservesExpiryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\Tenant;
use Tests\TestCase;

class UpdatePlanPreservesExpiryTest extends TestCase
{
    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AdminUser::create([
            'name' => 'Admin',
            'email' => 'admin@test.example',
            'password' => bcrypt('password'),
        ]);
    }

    private function makeTenant(?\Carbon\Carbon $expires = null): Tenant
    {
        return Tenant::create([
            'name' => 'Tenant',
            'slug' => 'tenant-' . uniqid(),
            'status' => 'active',
            'plan_expires_at' => $expires,
        ]);
    }

    private function makePlan(string $billingPeriod = 'monthly'): Plan
    {
        return Plan::create([
            'name' => 'Plan ' . $billingPeriod,
            'slug' => 'plan-' . $billingPeriod . '-' . uniqid(),
            'price' => 100,
            'billing_period' => $billingPeriod,
            'is_active' => true,
            'conversations_limit' => 100,
            'leads_limit' => 50,
            'tokens_limit' => 10000,
            'knowledge_items_limit' => 5,
        ]);
    }

    public function test_blank_expires_at_preserves_remaining_time_on_yearly_plan(): void
    {
        $existing = now()->addMonths(9);
        $tenant = $this->makeTenant($existing);
        $plan = $this->makePlan('yearly');

        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.clients.update-plan', $tenant), [
                'plan_id' => $plan->id,
                // expires_at intentionally omitted
            ])
            ->assertRedirect();

        $tenant->refresh();
        // Existing future expiry (9 months out) + 12 months = 21 months
        $expected = $existing->copy()->addMonths(12);
        $this->assertEqualsWithDelta(
            $expected->getTimestamp(),
            $tenant->plan_expires_at->getTimestamp(),
            5,
            'Yearly plan extension must add 12 months to the existing future expiry'
        );
    }

    public function test_blank_expires_at_on_expired_plan_starts_from_now(): void
    {
        $expired = now()->subMonths(2);
        $tenant = $this->makeTenant($expired);
        $plan = $this->makePlan('monthly');

        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.clients.update-plan', $tenant), [
                'plan_id' => $plan->id,
            ])
            ->assertRedirect();

        $tenant->refresh();
        $expected = now()->addMonths(1);
        $this->assertEqualsWithDelta(
            $expected->getTimestamp(),
            $tenant->plan_expires_at->getTimestamp(),
            5,
            'Expired-plan base resets to now(), not the past expiry'
        );
    }

    public function test_explicit_expires_at_is_honored(): void
    {
        $tenant = $this->makeTenant(now()->addMonths(2));
        $plan = $this->makePlan('monthly');
        $explicit = now()->addMonths(6)->startOfDay();

        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.clients.update-plan', $tenant), [
                'plan_id' => $plan->id,
                'expires_at' => $explicit->toDateString(),
            ])
            ->assertRedirect();

        $tenant->refresh();
        $this->assertSame(
            $explicit->toDateString(),
            $tenant->plan_expires_at->toDateString(),
            'Explicit expires_at must override extension logic'
        );
    }
}
```

- [ ] **Step 2: Run, confirm the preserve-remaining-time test FAILS**

```bash
php artisan test --filter=UpdatePlanPreservesExpiryTest
```

Expected: `test_blank_expires_at_preserves_remaining_time_on_yearly_plan` fails (expiry collapsed to `now()+1mo`).

- [ ] **Step 3: Patch `updatePlan`**

Edit `app/Http/Controllers/Admin/ClientController.php:155-171`:

```php
    public function updatePlan(Request $request, Tenant $client): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'expires_at' => 'nullable|date|after:today',
        ]);

        /** @var Plan $plan */
        $plan = Plan::findOrFail($validated['plan_id']);

        if (! empty($validated['expires_at'])) {
            $expires = $validated['expires_at'];
        } else {
            $base = $client->plan_expires_at && $client->plan_expires_at->isFuture()
                ? $client->plan_expires_at
                : now();
            $months = $plan->billing_period === 'yearly' ? 12 : 1;
            $expires = $base->copy()->addMonths($months);
        }

        $client->update([
            'plan_id' => $plan->id,
            'plan_expires_at' => $expires,
        ]);

        return back()->with('success', "Client plan updated to {$plan->name}.");
    }
```

- [ ] **Step 4: Run tests, confirm all PASS**

```bash
php artisan test --filter=UpdatePlanPreservesExpiryTest
```

- [ ] **Step 5: Full suite**

```bash
php artisan test
```

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/ClientController.php tests/Feature/Admin/UpdatePlanPreservesExpiryTest.php
git commit -m "$(cat <<'EOF'
fix(admin): preserve remaining plan time when expires_at is blank

ClientController::updatePlan overwrote plan_expires_at with
now()->addMonth() whenever the admin saved the form without a
custom date, silently collapsing a tenant's remaining yearly-plan
time to 30 days. Now mirrors Tenant::extendPlan's math: derive the
expiry from the existing future expiry (or now() if expired) plus
the plan's billing_period.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `Admin\TransactionController::approve` rejects inactive plans

**Bug:** A pending transaction for Plan A can be approved after Plan A has been deactivated, subscribing the tenant to a removed plan that shouldn't be available anymore.

**Files:**
- Modify: `app/Http/Controllers/Admin/TransactionController.php:83-121`
- Test: `tests/Feature/Admin/ApproveInactivePlanTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Admin/ApproveInactivePlanTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use Tests\TestCase;

class ApproveInactivePlanTest extends TestCase
{
    private AdminUser $admin;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AdminUser::create([
            'name' => 'Admin',
            'email' => 'admin@test.example',
            'password' => bcrypt('password'),
        ]);
        $this->tenant = Tenant::create([
            'name' => 'Tenant',
            'slug' => 'tenant',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    private function makePendingTransaction(Plan $plan): Transaction
    {
        return Transaction::create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'transaction_number' => 'TXN-' . uniqid(),
            'reference_number' => 'ABC123',
            'amount' => $plan->price,
            'payment_method' => 'bob',
            'payment_date' => now()->toDateString(),
            'status' => 'pending',
        ]);
    }

    public function test_approving_an_inactive_plan_is_rejected(): void
    {
        $plan = Plan::create([
            'name' => 'Removed Plan',
            'slug' => 'removed',
            'price' => 100,
            'billing_period' => 'monthly',
            'is_active' => false,
            'conversations_limit' => 100,
            'leads_limit' => 50,
            'tokens_limit' => 10000,
            'knowledge_items_limit' => 5,
        ]);
        $txn = $this->makePendingTransaction($plan);

        $response = $this->actingAs($this->admin, 'admin')
            ->post(route('admin.transactions.approve', $txn));

        $response->assertRedirect();
        $response->assertSessionHas('error');

        $txn->refresh();
        $this->assertSame('pending', $txn->status, 'Transaction must remain pending');

        $this->tenant->refresh();
        $this->assertNull(
            $this->tenant->plan_id,
            'Tenant must not be subscribed to a deactivated plan'
        );
    }

    public function test_approving_an_active_plan_still_works(): void
    {
        $plan = Plan::create([
            'name' => 'Active Plan',
            'slug' => 'active',
            'price' => 100,
            'billing_period' => 'monthly',
            'is_active' => true,
            'conversations_limit' => 100,
            'leads_limit' => 50,
            'tokens_limit' => 10000,
            'knowledge_items_limit' => 5,
        ]);
        $txn = $this->makePendingTransaction($plan);

        $response = $this->actingAs($this->admin, 'admin')
            ->post(route('admin.transactions.approve', $txn));

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $txn->refresh();
        $this->assertSame('approved', $txn->status);

        $this->tenant->refresh();
        $this->assertSame($plan->id, $this->tenant->plan_id);
    }
}
```

- [ ] **Step 2: Run, confirm the inactive-plan test FAILS**

```bash
php artisan test --filter=ApproveInactivePlanTest
```

Expected: `test_approving_an_inactive_plan_is_rejected` fails because `approve` currently accepts inactive plans and the tenant gets subscribed.

- [ ] **Step 3: Patch `approve`**

Edit `app/Http/Controllers/Admin/TransactionController.php:91-118`. Inside the `DB::transaction` closure, add the plan-active check right after the `ALREADY_PROCESSED` guard:

```php
        try {
            DB::transaction(function () use ($transaction, $validated, $admin) {
                $locked = Transaction::with(['tenant', 'plan'])
                    ->whereKey($transaction->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked || $locked->status !== 'pending') {
                    throw new \RuntimeException('ALREADY_PROCESSED');
                }

                if (! $locked->plan || ! $locked->plan->is_active) {
                    throw new \RuntimeException('PLAN_INACTIVE');
                }

                $locked->update([
                    'status' => 'approved',
                    'admin_notes' => $validated['admin_notes'] ?? null,
                    'approved_by' => $admin?->id,
                    'approved_at' => now(),
                ]);

                if ($locked->tenant && $locked->plan) {
                    $locked->tenant->extendPlan($locked->plan);
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'ALREADY_PROCESSED') {
                return back()->with('error', 'Transaction has already been processed.');
            }
            if ($e->getMessage() === 'PLAN_INACTIVE') {
                return back()->with('error', 'Cannot approve transaction: the plan is no longer active.');
            }
            throw $e;
        }
```

- [ ] **Step 4: Run tests, confirm both PASS**

```bash
php artisan test --filter=ApproveInactivePlanTest
```

- [ ] **Step 5: Full suite**

```bash
php artisan test
```

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/TransactionController.php tests/Feature/Admin/ApproveInactivePlanTest.php
git commit -m "$(cat <<'EOF'
fix(admin): reject transaction approval when plan is no longer active

approve() didn't check $locked->plan->is_active. After an admin
deactivated a plan, any pending transaction for it could still be
approved, subscribing the tenant to a removed plan. Now throws
PLAN_INACTIVE inside the transaction and surfaces a flash error
analogous to the existing ALREADY_PROCESSED path.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Browser smoke + simplify + PR

- [ ] **Step 1: Browser smoke**

Login as `admin@example.com` / `password`. Walk the affected flows:

- **H-NEW-10**: navigate to a client detail page with an existing future `plan_expires_at`. Open the Change Plan modal, select a yearly plan, leave `expires_at` blank, submit. Verify via tinker that `plan_expires_at` is roughly `existing + 12 months`, NOT `now() + 1 month`.
- **H-NEW-11**: create or find a plan in admin, deactivate it. Find a pending transaction for that plan (or create one as a test tenant). Try to approve — must see a flash error and the transaction stays `pending`.
- **H-NEW-9**: create a plan with `conversations_limit = 0` via admin UI. Switch to a tenant on that plan and try to start a widget conversation — must be blocked by `CheckUsageLimits` middleware.

- [ ] **Step 2: Run `/simplify`** (the multi-agent review) and apply any high-confidence findings. Skip pre-existing or out-of-scope.

- [ ] **Step 3: Run a second-pass `/simplify`** to catch issues introduced by the first pass's cleanups.

- [ ] **Step 4: Open PR**

PR title: `fix: close 3 billing-cluster high-severity bugs (limit semantics, expiry preservation, plan validation)`

PR body should call out:
- **Behavior change**: a `plan.*_limit = 0` now blocks all usage of that type (was: silently unlimited). Re-audit existing plans before deploy if any tenant is on a plan with a `0` limit.
- **Behavior change**: admin saving a client's plan with a blank `expires_at` now extends from the existing future expiry, not from `now()`. Yearly tenants no longer lose remaining months on routine admin edits.
- **Behavior change**: pending transactions for deactivated plans cannot be approved. Admin must reject them or reactivate the plan first.

---

## Out of scope

- H-NEW-4 (EmbeddingService Ollama hardcoded), H-NEW-5 (markAsReady early), H-NEW-6 (prompt injection), H-NEW-7 (prompt budget), H-NEW-8 (failed-billed token tracking) — LLM/RAG cluster, separate plan.
- H-NEW-12..H-NEW-15 — UI cluster (conversations keyboard nav, slug watcher, trial silent error, enterprise modal stale errors), separate plan.
- 11 documented Mediums M1–M11 from the May 7 audit and 15 M-NEW-* from the May 9 audit — separate plans.
- Refactoring `Tenant::extendPlan` to be the single source of truth for expiry math — would couple this PR to PR #5's signature change; the duplication is preserved for independence and is one-spot only.
