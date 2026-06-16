# Tech-Debt Cleanup (PR 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship PR 2 of the 2026-06-16 security-hardening + tech-debt batch — the "Correctness, dead-code & infra" half. Make `LeadScoring` the single source of truth for lead-temperature thresholds, wire up the dormant admin audit-log writer at every mutation chokepoint (best-effort, never-throw), surface the already-built client restore/sort/trashed UI, make `tests/bootstrap.php` portable across checkouts, add stream-path retry parity to `ChatService`, and publish the consolidated deploy runbook.

**Architecture:** This branch (`fix/tech-debt-cleanup`) is cut **off `main` AFTER PR 1 (`fix/security-hardening`) merges** — it has no code dependency on PR 1, but the runbook (Task 6) references the `widget_dual_accept_passthrough` counter and the `.env.example` dual-accept flip that PR 1 introduces, so it documents reality only once PR 1 is on `main`. Every task is TDD-shaped (failing test → RED → implement → GREEN → commit) and ordered so the full suite stays green after each commit. All changes are backend + one Vue page + two Markdown docs; there are no migrations. Threshold constants are introduced in `LeadScoring` and consumed by both `AnalyticsService::getLeadScoreDistribution` and `LeadService::getStats` (the spec text says "AnalyticsService::getStats" but the `high_quality >= 70` literal actually lives in `LeadService::getStats` — verified against live source). The audit writer mirrors `WidgetAudit`'s never-throw posture: each call is wrapped in `try/catch` + `Log::warning` so an audit failure can never break the admin action.

**Tech Stack:** Laravel 13 / PHP 8.3 (`declare(strict_types=1)`, full type hints), Vue 3 `<script setup>` + Inertia.js, PHPUnit class-style tests extending `Tests\TestCase` (RefreshDatabase). Role auth in tests via `Tests\Concerns\SeedsRoleMatrix` (`actingAsSuperAdmin`/`actingAsOwner`) and the `createSuperAdmin()` / `createTenantWithUser()` helpers on `TestCase`. Style: `./vendor/bin/pint`. Static analysis: `vendor/bin/phpstan analyse` (level 6, baseline MUST stay at zero). The custom Larastan rule forbids raw `where('tenant_id', ...)` — use `Model::forTenant($tenant)`.

---

## File map

**Create**
- `tests/Unit/Services/Analytics/LeadScoreDistributionTest.php` *(test)*
- `tests/Feature/Admin/AdminAuditLogWriterTest.php` *(test)*
- `tests/Feature/Admin/AdminClientIndexUiPropsTest.php` *(test)*
- `tests/Unit/BootstrapVendorResolutionTest.php` *(test)*
- `docs/superpowers/security-hardening-deploy.md` *(doc)*

**Modify**
- `app/Services/Leads/LeadScoring.php` — add `HOT_THRESHOLD`/`WARM_THRESHOLD` consts; `temperature()` consumes them
- `app/Services/Analytics/AnalyticsService.php` — `getLeadScoreDistribution` consumes the consts
- `app/Services/Leads/LeadService.php` — `getStats` `high_quality` consumes `HOT_THRESHOLD`
- `tests/Unit/Services/Leads/LeadScoringTest.php` — update `test_temperature_thresholds` for new buckets
- `app/Models/AdminActivityLog.php` — extend `getActionLabelAttribute` label map
- `app/Http/Controllers/Admin/TransactionController.php` — audit `approve`/`reject`
- `app/Http/Controllers/Admin/ClientController.php` — audit `updateStatus`/`updatePlan`/`updateBotPersonality`/`restore`
- `app/Http/Controllers/Admin/PlanController.php` — audit `store`/`update`/`toggleStatus`
- `app/Http/Controllers/Admin/EnterpriseInquiryController.php` — audit `update`
- `resources/js/Pages/Admin/Clients/Index.vue` — trashed toggle + sortable headers + Restore button
- `tests/bootstrap.php` — dynamic main-repo vendor resolution

---

## Task 1: LeadScoring thresholds as single source of truth (§2.1)

Add `HOT_THRESHOLD = 70` and `WARM_THRESHOLD = 40` constants to `LeadScoring`. `temperature()` consumes them (canonical hot≥70 / warm≥40 / cold<40). Then make `AnalyticsService::getLeadScoreDistribution` and `LeadService::getStats` reference the constants instead of duplicating the literals. The dashboard "hot" count and the `temperature()` label become consistent.

**Files:**
- Modify: `app/Services/Leads/LeadScoring.php` (consts near top of class; `temperature()` at lines 168-179)
- Modify: `app/Services/Analytics/AnalyticsService.php` (`getLeadScoreDistribution`, lines 163-174)
- Modify: `app/Services/Leads/LeadService.php` (`getStats`, lines 247-261)
- Modify (test): `tests/Unit/Services/Leads/LeadScoringTest.php` (`test_temperature_thresholds`, lines 337-345)
- Create (test): `tests/Unit/Services/Analytics/LeadScoreDistributionTest.php`

### Steps

- [ ] **Step 1: Update the existing temperature test to the new buckets, and add a constants-binding assertion.** Replace the body of `test_temperature_thresholds` in `tests/Unit/Services/Leads/LeadScoringTest.php` (current lines 337-345) with the version below. Note the boundary moves: 60 and 69 are now `warm`, 70 is now `hot`, 40 is now `warm`, 39 is `cold`.

```php
    /* ---------- Temperature ---------- */

    public function test_temperature_thresholds(): void
    {
        $this->assertSame('cold', $this->service->temperature(0));
        $this->assertSame('cold', $this->service->temperature(39));
        $this->assertSame('warm', $this->service->temperature(40));
        $this->assertSame('warm', $this->service->temperature(69));
        $this->assertSame('hot', $this->service->temperature(70));
        $this->assertSame('hot', $this->service->temperature(100));
    }

    public function test_temperature_uses_the_published_threshold_constants(): void
    {
        $this->assertSame('hot', $this->service->temperature(LeadScoring::HOT_THRESHOLD));
        $this->assertSame('warm', $this->service->temperature(LeadScoring::HOT_THRESHOLD - 1));
        $this->assertSame('warm', $this->service->temperature(LeadScoring::WARM_THRESHOLD));
        $this->assertSame('cold', $this->service->temperature(LeadScoring::WARM_THRESHOLD - 1));
    }
```

- [ ] **Step 2: Run it, expect fail.** `php artisan test --filter=LeadScoringTest` — `test_temperature_thresholds` fails (current code returns `warm` for 70, `hot` for 61) and `test_temperature_uses_the_published_threshold_constants` errors on the undefined `LeadScoring::HOT_THRESHOLD` constant.

- [ ] **Step 3: Implement the constants + `temperature()`.** In `app/Services/Leads/LeadScoring.php`, add the two public constants at the very top of the class body (immediately after the `class LeadScoring` opening brace on line 26, before the `$weights` docblock) and rewrite `temperature()`:

Add at top of class:
```php
class LeadScoring
{
    /**
     * Canonical lead-temperature thresholds. Single source of truth — the
     * dashboard distribution buckets (AnalyticsService) and the high-quality
     * lead count (LeadService::getStats) consume these so a lead's bucket
     * always agrees with its temperature() label.
     */
    public const HOT_THRESHOLD = 70;

    public const WARM_THRESHOLD = 40;

```

Replace `temperature()` (lines 168-179):
```php
    public function temperature(int $score): string
    {
        if ($score >= self::HOT_THRESHOLD) {
            return 'hot';
        }

        if ($score >= self::WARM_THRESHOLD) {
            return 'warm';
        }

        return 'cold';
    }
```

- [ ] **Step 4: Run it, expect pass.** `php artisan test --filter=LeadScoringTest` — all green.

- [ ] **Step 5: Write the distribution test, then make AnalyticsService + LeadService consume the constants.** Create `tests/Unit/Services/Analytics/LeadScoreDistributionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Analytics;

use App\Models\Lead;
use App\Models\Tenant;
use App\Services\Analytics\AnalyticsService;
use App\Services\Leads\LeadScoring;
use Tests\TestCase;

class LeadScoreDistributionTest extends TestCase
{
    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Dist',
            'slug' => 'dist-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    private function makeLead(Tenant $tenant, int $score): Lead
    {
        return Lead::create([
            'tenant_id' => $tenant->id,
            'status' => 'new',
            'source' => 'widget',
            'score' => $score,
        ]);
    }

    public function test_distribution_buckets_match_the_canonical_thresholds(): void
    {
        $tenant = $this->makeTenant();

        // Boundary scores: one just below warm, exactly warm, just below hot, exactly hot.
        $this->makeLead($tenant, LeadScoring::WARM_THRESHOLD - 1); // 39 → cold
        $this->makeLead($tenant, LeadScoring::WARM_THRESHOLD);     // 40 → warm
        $this->makeLead($tenant, LeadScoring::HOT_THRESHOLD - 1);  // 69 → warm
        $this->makeLead($tenant, LeadScoring::HOT_THRESHOLD);      // 70 → hot

        $dist = app(AnalyticsService::class)->getLeadScoreDistribution($tenant);

        $this->assertSame(1, $dist['cold']);
        $this->assertSame(2, $dist['warm']);
        $this->assertSame(1, $dist['hot']);
    }
}
```

- [ ] **Step 6: Run it, expect pass (behavior is unchanged — both already used 70/40).** `php artisan test --filter=LeadScoreDistributionTest`. The test passes against current literals; the implementation change below is the dead-literal removal that makes the constant the source of truth.

- [ ] **Step 7: Refactor `AnalyticsService::getLeadScoreDistribution` to consume the constants.** In `app/Services/Analytics/AnalyticsService.php`, add the import and rewrite the method body (lines 163-174):

Add to the `use` block (after line 11, `use App\Models\UsageRecord;`):
```php
use App\Services\Leads\LeadScoring;
```

Replace the method body:
```php
    public function getLeadScoreDistribution(Tenant $tenant): array
    {
        $leads = Lead::forTenant($tenant)->get();

        return [
            'hot' => $leads->where('score', '>=', LeadScoring::HOT_THRESHOLD)->count(),
            'warm' => $leads->whereBetween('score', [LeadScoring::WARM_THRESHOLD, LeadScoring::HOT_THRESHOLD - 1])->count(),
            'cold' => $leads->where('score', '<', LeadScoring::WARM_THRESHOLD)->count(),
        ];
    }
```

- [ ] **Step 8: Refactor `LeadService::getStats` high_quality literal.** In `app/Services/Leads/LeadService.php`, change `high_quality` (line 259) to use the constant. `LeadService` is in the same `App\Services\Leads` namespace as `LeadScoring`, so no import is needed:

```php
            'high_quality' => (clone $leads)->where('score', '>=', LeadScoring::HOT_THRESHOLD)->count(),
```

- [ ] **Step 9: Run the filtered tests, expect pass.** `php artisan test --filter=LeadScoreDistributionTest && php artisan test --filter=LeadScoringTest`.

- [ ] **Step 10: Run full suite.** `php artisan test` — confirm no other test depended on the old 61/31 buckets.

- [ ] **Step 11: Commit.**
```bash
git add app/Services/Leads/LeadScoring.php app/Services/Analytics/AnalyticsService.php app/Services/Leads/LeadService.php tests/Unit/Services/Leads/LeadScoringTest.php tests/Unit/Services/Analytics/LeadScoreDistributionTest.php
git commit -m "$(cat <<'EOF'
refactor(leads): make LeadScoring the single source of truth for thresholds

Add HOT_THRESHOLD=70 / WARM_THRESHOLD=40 constants; temperature() and the
AnalyticsService distribution + LeadService high_quality counts consume them
so a lead's bucket always agrees with its temperature() label.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Admin audit-log writer at all mutation chokepoints (§2.2)

`AdminActivityLog::log()` has zero callers, so the Activity Logs page is always empty. Wire it into every admin mutation chokepoint, each wrapped best-effort (`try/catch` + `Log::warning`) so an audit-write failure never breaks the admin action — mirroring `WidgetAudit`'s never-throw posture. Extend `getActionLabelAttribute`'s label map for the new action types.

Action-type map (per spec §2.2):

| Controller::method | action_type |
| --- | --- |
| `TransactionController::approve` / `reject` | `approve_transaction` / `reject_transaction` |
| `ClientController::updateStatus` / `updatePlan` / `updateBotPersonality` / `restore` | `update_client_status` / `update_client_plan` / `update_client_bot_personality` / `restore_client` |
| `PlanController::store` / `update` / `toggleStatus` | `create_plan` / `update_plan` / `toggle_plan` |
| `EnterpriseInquiryController::update` | `update_inquiry` |

**Files:**
- Modify: `app/Models/AdminActivityLog.php` (`getActionLabelAttribute`, lines 73-85)
- Modify: `app/Http/Controllers/Admin/TransactionController.php` (`approve` lines 87-114, `reject` lines 116-147)
- Modify: `app/Http/Controllers/Admin/ClientController.php` (`restore` 153-164, `updateStatus` 166-175, `updatePlan` 177-197, `updateBotPersonality` 199-210)
- Modify: `app/Http/Controllers/Admin/PlanController.php` (`store` 76-90, `update` 99-108, `toggleStatus` 110-119)
- Modify: `app/Http/Controllers/Admin/EnterpriseInquiryController.php` (`update`, lines 71-81)
- Create (test): `tests/Feature/Admin/AdminAuditLogWriterTest.php`

### Steps

- [ ] **Step 1: Write the failing test.** Create `tests/Feature/Admin/AdminAuditLogWriterTest.php`. It proves (a) approving a transaction writes an `approve_transaction` row, (b) the other chokepoints write their rows, (c) an audit-write failure does NOT break the admin action (uses a fake `AdminActivityLog` whose `log()` always throws — the action must still succeed). The failure-isolation test partial-mocks the model's static `log` via a Mockery alias is brittle, so instead we force a DB-level failure by dropping the `admin_activity_logs` table inside the test and asserting the action still redirects with success.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AdminActivityLog;
use App\Models\EnterpriseInquiry;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAuditLogWriterTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createSuperAdmin();
    }

    private function makePlan(string $slug): Plan
    {
        return Plan::create([
            'name' => 'Starter',
            'slug' => $slug,
            'description' => 'Plan',
            'price' => 9.99,
            'billing_period' => 'monthly',
            'conversations_limit' => 100,
            'messages_per_conversation' => 50,
            'knowledge_items_limit' => 50,
            'tokens_limit' => 100000,
            'leads_limit' => 500,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Client Co',
            'slug' => 'client-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function test_approving_a_transaction_writes_an_approve_transaction_audit_row(): void
    {
        $tenant = $this->makeTenant();
        $plan = $this->makePlan('starter-audit-approve');

        $transaction = Transaction::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'transaction_number' => 'TXN-AUDIT-1',
            'reference_number' => 'REF1',
            'amount' => 9.99,
            'payment_method' => 'bob',
            'payment_date' => now()->format('Y-m-d'),
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/transactions/{$transaction->id}/approve", ['admin_notes' => 'ok'])
            ->assertRedirect();

        $log = AdminActivityLog::where('action_type', 'approve_transaction')->first();
        $this->assertNotNull($log, 'approve must write an approve_transaction audit row');
        $this->assertSame($this->admin->id, $log->admin_user_id);
        $this->assertSame(Transaction::class, $log->target_type);
        $this->assertSame($transaction->id, $log->target_id);
    }

    public function test_rejecting_a_transaction_writes_a_reject_transaction_audit_row(): void
    {
        $tenant = $this->makeTenant();
        $plan = $this->makePlan('starter-audit-reject');

        $transaction = Transaction::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'transaction_number' => 'TXN-AUDIT-2',
            'reference_number' => 'REF2',
            'amount' => 9.99,
            'payment_method' => 'bob',
            'payment_date' => now()->format('Y-m-d'),
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->post("/admin/transactions/{$transaction->id}/reject", ['admin_notes' => 'invalid proof'])
            ->assertRedirect();

        $this->assertDatabaseHas('admin_activity_logs', [
            'action_type' => 'reject_transaction',
            'target_id' => $transaction->id,
        ]);
    }

    public function test_client_mutations_write_their_audit_rows(): void
    {
        $tenant = $this->makeTenant();
        $plan = $this->makePlan('starter-audit-client');

        $this->actingAs($this->admin)
            ->put("/admin/clients/{$tenant->id}/status", ['status' => 'suspended'])
            ->assertRedirect();
        $this->assertDatabaseHas('admin_activity_logs', [
            'action_type' => 'update_client_status',
            'target_id' => $tenant->id,
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/clients/{$tenant->id}/plan", ['plan_id' => $plan->id])
            ->assertRedirect();
        $this->assertDatabaseHas('admin_activity_logs', [
            'action_type' => 'update_client_plan',
            'target_id' => $tenant->id,
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/clients/{$tenant->id}/bot-personality", [
                'bot_type' => 'sales',
                'bot_tone' => 'friendly',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('admin_activity_logs', [
            'action_type' => 'update_client_bot_personality',
            'target_id' => $tenant->id,
        ]);

        $tenant->delete();
        $this->actingAs($this->admin)
            ->post("/admin/clients/{$tenant->id}/restore")
            ->assertRedirect();
        $this->assertDatabaseHas('admin_activity_logs', [
            'action_type' => 'restore_client',
            'target_id' => $tenant->id,
        ]);
    }

    public function test_plan_mutations_write_their_audit_rows(): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/plans', [
                'name' => 'Pro',
                'slug' => 'pro-audit',
                'price' => 29,
                'billing_period' => 'monthly',
                'conversations_limit' => 500,
                'messages_per_conversation' => 100,
                'knowledge_items_limit' => 100,
                'tokens_limit' => 500000,
                'leads_limit' => 1000,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('admin_activity_logs', ['action_type' => 'create_plan']);

        $plan = Plan::where('slug', 'pro-audit')->firstOrFail();

        $this->actingAs($this->admin)
            ->put("/admin/plans/{$plan->id}", [
                'name' => 'Pro Plus',
                'slug' => 'pro-audit',
                'price' => 39,
                'billing_period' => 'monthly',
                'conversations_limit' => 600,
                'messages_per_conversation' => 120,
                'knowledge_items_limit' => 120,
                'tokens_limit' => 600000,
                'leads_limit' => 1200,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('admin_activity_logs', [
            'action_type' => 'update_plan',
            'target_id' => $plan->id,
        ]);

        $this->actingAs($this->admin)
            ->patch("/admin/plans/{$plan->id}/toggle")
            ->assertRedirect();
        $this->assertDatabaseHas('admin_activity_logs', [
            'action_type' => 'toggle_plan',
            'target_id' => $plan->id,
        ]);
    }

    public function test_inquiry_update_writes_an_audit_row(): void
    {
        $inquiry = EnterpriseInquiry::create([
            'name' => 'Lead Person',
            'email' => 'lead@example.com',
            'company' => 'BigCo',
            'message' => 'We want enterprise.',
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->put("/admin/inquiries/{$inquiry->id}", [
                'status' => 'contacted',
                'admin_notes' => 'called them',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('admin_activity_logs', [
            'action_type' => 'update_inquiry',
            'target_id' => $inquiry->id,
        ]);
    }

    public function test_audit_write_failure_does_not_break_the_admin_action(): void
    {
        $tenant = $this->makeTenant();
        $plan = $this->makePlan('starter-audit-resilient');

        $transaction = Transaction::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'transaction_number' => 'TXN-AUDIT-FAIL',
            'reference_number' => 'REFF',
            'amount' => 9.99,
            'payment_method' => 'bob',
            'payment_date' => now()->format('Y-m-d'),
            'status' => 'pending',
        ]);

        // Drop the audit table so AdminActivityLog::log() throws a QueryException.
        // The best-effort try/catch must swallow it and let the action succeed.
        Schema::drop('admin_activity_logs');

        $this->actingAs($this->admin)
            ->post("/admin/transactions/{$transaction->id}/approve", ['admin_notes' => 'ok'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('approved', $transaction->fresh()->status);
    }
}
```

- [ ] **Step 2: Run it, expect fail.** `php artisan test --filter=AdminAuditLogWriterTest` — every assertion fails: no audit rows are written because `log()` has no callers (and the resilience test's action succeeds for the wrong reason — there is no audit write to fail yet).

- [ ] **Step 3a: Extend the label map.** In `app/Models/AdminActivityLog.php`, replace the `$labels` array in `getActionLabelAttribute` (lines 75-82) so every new `action_type` has a human label:

```php
        $labels = [
            'login' => 'Logged in',
            'logout' => 'Logged out',
            'approve_transaction' => 'Approved transaction',
            'reject_transaction' => 'Rejected transaction',
            'update_client_status' => 'Updated client status',
            'update_client_plan' => 'Updated client plan',
            'update_client_bot_personality' => 'Updated client bot personality',
            'restore_client' => 'Restored client',
            'create_plan' => 'Created plan',
            'update_plan' => 'Updated plan',
            'toggle_plan' => 'Toggled plan status',
            'update_inquiry' => 'Updated inquiry',
        ];
```

- [ ] **Step 3b: Audit `TransactionController::approve` and `reject`.** In `app/Http/Controllers/Admin/TransactionController.php`, add the `AdminActivityLog` import (after `use App\Models\Transaction;`, line 11):

```php
use App\Models\AdminActivityLog;
```

In `approve()`, after the successful `approveAndActivate(...)` try/catch block and before the final `return back()->with('success', ...)` (currently line 113), add the best-effort audit call:

```php
        try {
            AdminActivityLog::log('approve_transaction', $transaction, [
                'admin_notes' => $validated['admin_notes'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Admin] Failed to write approve_transaction audit log', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
        }

        return back()->with('success', 'Transaction approved and plan activated.');
```

In `reject()`, after the `DB::transaction(...)` try/catch block and before the final `return back()->with('success', 'Transaction rejected.')` (currently line 146), add:

```php
        try {
            AdminActivityLog::log('reject_transaction', $transaction, [
                'admin_notes' => $validated['admin_notes'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Admin] Failed to write reject_transaction audit log', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);
        }

        return back()->with('success', 'Transaction rejected.');
```

- [ ] **Step 3c: Audit the four `ClientController` chokepoints.** In `app/Http/Controllers/Admin/ClientController.php`, add the imports (after `use App\Http\Controllers\Controller;`, line 7):

```php
use App\Models\AdminActivityLog;
```

and (after `use Inertia\Response;`, line 17):

```php
use Illuminate\Support\Facades\Log;
```

In `restore()`, before the final `return redirect()->route(...)` (currently lines 161-163):

```php
        try {
            AdminActivityLog::log('restore_client', $tenant);
        } catch (\Throwable $e) {
            Log::warning('[Admin] Failed to write restore_client audit log', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.clients.show', $tenant->id)
            ->with('success', 'Tenant restored.');
```

In `updateStatus()`, before the final `return back()->with(...)` (currently line 174):

```php
        try {
            AdminActivityLog::log('update_client_status', $client, ['status' => $validated['status']]);
        } catch (\Throwable $e) {
            Log::warning('[Admin] Failed to write update_client_status audit log', [
                'tenant_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
        }

        return back()->with('success', "Client status updated to {$validated['status']}.");
```

In `updatePlan()`, before the final `return back()->with(...)` (currently line 196):

```php
        try {
            AdminActivityLog::log('update_client_plan', $client, [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Admin] Failed to write update_client_plan audit log', [
                'tenant_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
        }

        return back()->with('success', "Client plan updated to {$plan->name}.");
```

In `updateBotPersonality()`, before the final `return back()->with(...)` (currently line 209):

```php
        try {
            AdminActivityLog::log('update_client_bot_personality', $client, [
                'bot_type' => $validated['bot_type'],
                'bot_tone' => $validated['bot_tone'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Admin] Failed to write update_client_bot_personality audit log', [
                'tenant_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
        }

        return back()->with('success', 'Bot personality updated successfully.');
```

- [ ] **Step 3d: Audit the three `PlanController` chokepoints.** In `app/Http/Controllers/Admin/PlanController.php`, add the imports (after `use App\Http\Requests\Admin\UpdatePlanRequest;`, line 9):

```php
use App\Models\AdminActivityLog;
```

and (after `use Inertia\Response;`, line 14):

```php
use Illuminate\Support\Facades\Log;
```

In `store()`, change the `Plan::create($validated)` line (line 85) to capture the model, add the audit call, then redirect:

```php
        $plan = Plan::create($validated);

        try {
            AdminActivityLog::log('create_plan', $plan, ['name' => $plan->name, 'slug' => $plan->slug]);
        } catch (\Throwable $e) {
            Log::warning('[Admin] Failed to write create_plan audit log', [
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.plans.index')
            ->with('success', 'Plan created successfully.');
```

In `update()`, after `$plan->update($validated);` (line 103) and before the redirect:

```php
        $plan->update($validated);

        try {
            AdminActivityLog::log('update_plan', $plan, ['name' => $plan->name, 'slug' => $plan->slug]);
        } catch (\Throwable $e) {
            Log::warning('[Admin] Failed to write update_plan audit log', [
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('admin.plans.index')
            ->with('success', 'Plan updated successfully.');
```

In `toggleStatus()`, after `$status = $plan->is_active ? 'activated' : 'deactivated';` (line 116) and before the final `return back()`:

```php
        $status = $plan->is_active ? 'activated' : 'deactivated';

        try {
            AdminActivityLog::log('toggle_plan', $plan, ['is_active' => $plan->is_active]);
        } catch (\Throwable $e) {
            Log::warning('[Admin] Failed to write toggle_plan audit log', [
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);
        }

        return back()->with('success', "Plan {$status} successfully.");
```

- [ ] **Step 3e: Audit `EnterpriseInquiryController::update`.** In `app/Http/Controllers/Admin/EnterpriseInquiryController.php`, add imports (after `use App\Models\EnterpriseInquiry;`, line 8):

```php
use App\Models\AdminActivityLog;
```

and (after `use Inertia\Response;`, line 12):

```php
use Illuminate\Support\Facades\Log;
```

In `update()`, after `$inquiry->update($validated);` (line 78) and before the final `return back()`:

```php
        $inquiry->update($validated);

        try {
            AdminActivityLog::log('update_inquiry', $inquiry, ['status' => $validated['status']]);
        } catch (\Throwable $e) {
            Log::warning('[Admin] Failed to write update_inquiry audit log', [
                'inquiry_id' => $inquiry->id,
                'error' => $e->getMessage(),
            ]);
        }

        return back()->with('success', 'Inquiry updated successfully.');
```

- [ ] **Step 4: Run it, expect pass.** `php artisan test --filter=AdminAuditLogWriterTest` — all green, including `test_audit_write_failure_does_not_break_the_admin_action` (the dropped table makes `log()` throw a `QueryException`, the `\Throwable` catch swallows it, and the transaction is still approved).

- [ ] **Step 5: Run full suite.** `php artisan test` — confirm the existing `TransactionApprovalTest`, `AdminClientTrashedTest`, `UpdatePlanPreservesExpiryTest`, `UpdateBotPersonalityValidationTest`, and `ActivityLogTest` still pass (the new audit writes are additive and best-effort).

- [ ] **Step 6: Commit.**
```bash
git add app/Models/AdminActivityLog.php app/Http/Controllers/Admin/TransactionController.php app/Http/Controllers/Admin/ClientController.php app/Http/Controllers/Admin/PlanController.php app/Http/Controllers/Admin/EnterpriseInquiryController.php tests/Feature/Admin/AdminAuditLogWriterTest.php
git commit -m "$(cat <<'EOF'
feat(admin): wire AdminActivityLog writer into mutation chokepoints

Audit transaction approve/reject, client status/plan/bot-personality/restore,
plan create/update/toggle, and inquiry update. Each call is best-effort
(try/catch + Log::warning) so an audit failure never breaks the admin action,
mirroring WidgetAudit's never-throw posture. Extends the action-label map.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Admin client restore/sort/trashed UI (§2.3)

The `ClientController::index` already supports the `trashed` filter, `sort`/`direction`, and returns them in `filters`; `restore` is routed. But `Clients/Index.vue` only sends `search/status/plan` and renders no restore button or sort headers, so the features are unreachable. This task is frontend-only — the backend is done. A feature test asserts the Inertia page receives the `trashed`/`sort`/`direction` filters and that the restore route works end-to-end; the actual Vue controls are confirmed in the browser-smoke task.

**Files:**
- Modify: `resources/js/Pages/Admin/Clients/Index.vue` (full file)
- Create (test): `tests/Feature/Admin/AdminClientIndexUiPropsTest.php`

### Steps

- [ ] **Step 1: Write the failing test.** Create `tests/Feature/Admin/AdminClientIndexUiPropsTest.php`. It asserts the index page exposes the new filters in the Inertia props (so the Vue page can bind them) and that round-tripping `sort`/`direction`/`trashed` through the controller is honored.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;

class AdminClientIndexUiPropsTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createSuperAdmin();
    }

    public function test_index_exposes_trashed_sort_and_direction_filters_to_the_page(): void
    {
        $response = $this->actingAs($this->admin)
            ->get('/admin/clients?trashed=only&sort=name&direction=asc');
        $response->assertStatus(200);

        $filters = $response->viewData('page')['props']['filters'];
        $this->assertSame('only', $filters['trashed']);
        $this->assertSame('name', $filters['sort']);
        $this->assertSame('asc', $filters['direction']);
    }

    public function test_sort_by_name_ascending_orders_the_client_list(): void
    {
        Tenant::create([
            'name' => 'Zebra', 'slug' => 'zebra-'.uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        Tenant::create([
            'name' => 'Alpha', 'slug' => 'alpha-'.uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/admin/clients?sort=name&direction=asc');
        $response->assertStatus(200);

        $names = collect($response->viewData('page')['props']['clients']['data'])->pluck('name')->all();
        $this->assertSame('Alpha', $names[0]);
        $this->assertSame('Zebra', $names[1]);
    }

    public function test_restore_route_round_trips_for_a_trashed_client(): void
    {
        $tenant = Tenant::create([
            'name' => 'ToRestore', 'slug' => 'torestore-'.uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $tenant->delete();

        $this->actingAs($this->admin)
            ->post("/admin/clients/{$tenant->id}/restore")
            ->assertRedirect(route('admin.clients.show', $tenant->id));

        $this->assertNull(Tenant::withTrashed()->find($tenant->id)->deleted_at);
    }
}
```

- [ ] **Step 2: Run it, expect pass for the backend, then implement the Vue.** `php artisan test --filter=AdminClientIndexUiPropsTest` — these pass against the existing controller (the backend is already done). They are the regression guard that locks the props contract the Vue page depends on. The Vue control wiring itself has no Pest surface and is verified in the browser-smoke task; this test ensures the controller never silently drops the filters the page sends.

- [ ] **Step 3: Implement the Vue page.** Replace the full contents of `resources/js/Pages/Admin/Clients/Index.vue` with the version below. Changes vs. current: add `sort`/`direction`/`trashed` refs from `props.filters`; add them to `applyFilters` and the `watch` list; add a trashed `<select>`; make the Client / Status / Conversations / Leads / Created headers clickable to toggle sort; add a Restore button (`router.post(route('admin.clients.restore', client.id))`) on trashed rows (rows where `client.deleted_at` is set).

```vue
<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'
import { useRoute } from '@/composables/useRoute'
import { debounce } from 'lodash'
import { Card, CardContent } from '@/Components/ui/card'
import { Input } from '@/Components/ui/input'
import { Badge } from '@/Components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/Components/ui/table'

const route = useRoute()

const props = defineProps({
    clients: Object,
    plans: Array,
    filters: Object,
})

const search = ref(props.filters.search)
const status = ref(props.filters.status)
const plan = ref(props.filters.plan)
const trashed = ref(props.filters.trashed || '')
const sort = ref(props.filters.sort || 'created_at')
const direction = ref(props.filters.direction || 'desc')

const applyFilters = debounce(() => {
    router.get(route('admin.clients.index'), {
        search: search.value,
        status: status.value,
        plan: plan.value,
        trashed: trashed.value,
        sort: sort.value,
        direction: direction.value,
    }, {
        preserveState: true,
        replace: true,
    })
}, 300)

watch([search, status, plan, trashed, sort, direction], applyFilters)

const toggleSort = (field) => {
    if (sort.value === field) {
        direction.value = direction.value === 'asc' ? 'desc' : 'asc'
    } else {
        sort.value = field
        direction.value = 'asc'
    }
}

const sortIndicator = (field) => {
    if (sort.value !== field) {
        return ''
    }
    return direction.value === 'asc' ? ' ▲' : ' ▼'
}

const restore = (id) => {
    router.post(route('admin.clients.restore', id), {}, {
        preserveScroll: true,
    })
}

const getStatusVariant = (status) => {
    const variants = {
        active: 'success',
        inactive: 'secondary',
        suspended: 'destructive',
    }
    return variants[status] || 'secondary'
}

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    })
}
</script>

<template>
    <AdminLayout title="Clients">
        <!-- Filters -->
        <div class="mb-6 flex flex-col sm:flex-row gap-4">
            <div class="flex-1">
                <Input
                    v-model="search"
                    type="text"
                    placeholder="Search clients..."
                />
            </div>
            <select
                v-model="status"
                class="h-9 rounded-md bg-background border px-3 text-sm text-foreground focus:border-primary focus:ring-primary"
            >
                <option value="all">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="suspended">Suspended</option>
            </select>
            <select
                v-model="plan"
                class="h-9 rounded-md bg-background border px-3 text-sm text-foreground focus:border-primary focus:ring-primary"
            >
                <option value="all">All Plans</option>
                <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <select
                v-model="trashed"
                class="h-9 rounded-md bg-background border px-3 text-sm text-foreground focus:border-primary focus:ring-primary"
            >
                <option value="">Active only</option>
                <option value="with">Include deleted</option>
                <option value="only">Deleted only</option>
            </select>
        </div>

        <!-- Table -->
        <Card>
            <CardContent class="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead class="cursor-pointer select-none" @click="toggleSort('name')">
                                Client{{ sortIndicator('name') }}
                            </TableHead>
                            <TableHead>Plan</TableHead>
                            <TableHead class="cursor-pointer select-none" @click="toggleSort('status')">
                                Status{{ sortIndicator('status') }}
                            </TableHead>
                            <TableHead class="text-center">Users</TableHead>
                            <TableHead class="text-center cursor-pointer select-none" @click="toggleSort('conversations_count')">
                                Conversations{{ sortIndicator('conversations_count') }}
                            </TableHead>
                            <TableHead class="text-center cursor-pointer select-none" @click="toggleSort('leads_count')">
                                Leads{{ sortIndicator('leads_count') }}
                            </TableHead>
                            <TableHead class="cursor-pointer select-none" @click="toggleSort('created_at')">
                                Created{{ sortIndicator('created_at') }}
                            </TableHead>
                            <TableHead class="text-right">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="client in clients.data" :key="client.id">
                            <TableCell>
                                <div>
                                    <Link :href="route('admin.clients.show', client.id)" class="text-sm font-medium text-foreground hover:text-primary">
                                        {{ client.name }}
                                    </Link>
                                    <p class="text-xs text-muted-foreground">{{ client.slug }}</p>
                                </div>
                            </TableCell>
                            <TableCell class="text-muted-foreground">
                                {{ client.current_plan?.name || 'Free' }}
                            </TableCell>
                            <TableCell>
                                <Badge :variant="getStatusVariant(client.status)" class="capitalize">
                                    {{ client.status }}
                                </Badge>
                                <Badge v-if="client.deleted_at" variant="destructive" class="ml-1">
                                    Deleted
                                </Badge>
                            </TableCell>
                            <TableCell class="text-center text-muted-foreground">
                                {{ client.users_count }}
                            </TableCell>
                            <TableCell class="text-center text-muted-foreground">
                                {{ client.conversations_count }}
                            </TableCell>
                            <TableCell class="text-center text-muted-foreground">
                                {{ client.leads_count }}
                            </TableCell>
                            <TableCell class="text-muted-foreground">
                                {{ formatDate(client.created_at) }}
                            </TableCell>
                            <TableCell class="text-right">
                                <button
                                    v-if="client.deleted_at"
                                    type="button"
                                    class="text-sm text-primary hover:text-primary/80"
                                    @click="restore(client.id)"
                                >
                                    Restore
                                </button>
                                <Link v-else :href="route('admin.clients.show', client.id)" class="text-sm text-primary hover:text-primary/80">
                                    View
                                </Link>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="!clients.data?.length">
                            <TableCell colspan="8" class="text-center py-12 text-muted-foreground">
                                No clients found
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </CardContent>
        </Card>

        <!-- Pagination -->
        <div v-if="clients.links?.length > 3" class="mt-4 flex justify-center">
            <nav class="flex space-x-2">
                <template v-for="link in clients.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        :class="[
                            'px-3 py-2 text-sm rounded-md',
                            link.active ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-accent'
                        ]"
                        v-html="link.label"
                    />
                    <span
                        v-else
                        class="px-3 py-2 text-sm text-muted-foreground"
                        v-html="link.label"
                    />
                </template>
            </nav>
        </div>
    </AdminLayout>
</template>
```

- [ ] **Step 4: Build the frontend so the Vue compiles, expect success.** `pnpm run build` — confirms the page has no template/script errors (Inertia SSR/Vite catches malformed `<script setup>` here, which Pest cannot).

- [ ] **Step 5: Run full suite.** `php artisan test` — backend regression guard stays green.

- [ ] **Step 6: Commit.**
```bash
git add resources/js/Pages/Admin/Clients/Index.vue tests/Feature/Admin/AdminClientIndexUiPropsTest.php
git commit -m "$(cat <<'EOF'
feat(admin): surface client restore/sort/trashed UI in Clients/Index

Wire the already-built controller support (trashed filter, sortable
columns, restore route) into the Vue page: trashed select, clickable
sort headers, and a Restore button on deleted rows. Backend unchanged.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Test bootstrap portability (§2.4)

`tests/bootstrap.php:12` hardcodes `'/Users/sam/Dev/laravel/chatbot/vendor'`, breaking every other checkout/CI. Resolve the main-repo vendor dynamically via `git rev-parse --git-common-dir`, and **no-op on a normal (non-worktree) checkout or when the resolved path doesn't exist**, falling back to the worktree's own vendor. The worktree-isolation behavior is preserved; the absolute path is removed.

Key fact (verified): on a normal checkout `git rev-parse --git-common-dir` prints `.git` (relative); in a linked worktree it prints the absolute path to the main repo's `.git`. So: resolve the common-dir, take its parent (the main repo root), append `/vendor`; if that vendor's `autoload.php` doesn't exist (normal checkout, where the common dir is just this repo's own `.git`, so the parent is the worktree root itself), fall back to the worktree's `vendor`.

**Files:**
- Modify: `tests/bootstrap.php` (full file)
- Create (test): `tests/Unit/BootstrapVendorResolutionTest.php`

### Steps

- [ ] **Step 1: Write the failing test.** Create `tests/Unit/BootstrapVendorResolutionTest.php`. Since the bootstrap runs once before the test harness, we cannot re-run it inside a test; instead we assert (a) the hardcoded absolute path is gone from the file source, and (b) the resolution helper logic is correct by extracting it into a pure named function the bootstrap defines and the test calls directly.

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class BootstrapVendorResolutionTest extends TestCase
{
    public function test_bootstrap_does_not_hardcode_a_machine_specific_vendor_path(): void
    {
        $source = (string) file_get_contents(base_path('tests/bootstrap.php'));

        $this->assertStringNotContainsString(
            '/Users/sam/Dev/laravel/chatbot/vendor',
            $source,
            'tests/bootstrap.php must not hardcode a machine-specific absolute vendor path'
        );
        $this->assertStringContainsString(
            'git rev-parse --git-common-dir',
            $source,
            'tests/bootstrap.php must resolve the main-repo vendor dynamically via git-common-dir'
        );
    }

    public function test_resolve_main_vendor_returns_worktree_vendor_on_a_normal_checkout(): void
    {
        require_once base_path('tests/bootstrap.php');

        $worktreeRoot = dirname(base_path('tests'));

        // On a normal checkout (this repo IS the main repo), the resolver must
        // return the worktree's own vendor — git-common-dir resolves to a path
        // whose parent already equals the worktree root.
        $resolved = \tests_resolve_main_vendor($worktreeRoot);

        $this->assertSame($worktreeRoot.'/vendor', $resolved);
        $this->assertFileExists($resolved.'/autoload.php');
    }

    public function test_resolve_main_vendor_falls_back_when_resolved_path_is_missing(): void
    {
        require_once base_path('tests/bootstrap.php');

        // A bogus worktree root with no .git → git command fails / yields no
        // usable main vendor → fall back to "<root>/vendor".
        $bogusRoot = sys_get_temp_dir().'/nonexistent-worktree-'.uniqid();

        $resolved = \tests_resolve_main_vendor($bogusRoot);

        $this->assertSame($bogusRoot.'/vendor', $resolved);
    }
}
```

- [ ] **Step 2: Run it, expect fail.** `php artisan test --filter=BootstrapVendorResolutionTest` — fails: the file still contains the hardcoded path and `tests_resolve_main_vendor()` is undefined.

- [ ] **Step 3: Implement dynamic resolution.** Replace the full contents of `tests/bootstrap.php` with the version below. The hardcoded path is replaced by `tests_resolve_main_vendor($worktreeRoot)`, which: runs `git -C <worktreeRoot> rev-parse --git-common-dir`; resolves it to an absolute path; takes its parent as the main-repo root; appends `/vendor`; and **only uses that if its `autoload.php` exists and it differs from the worktree's own vendor** — otherwise returns `<worktreeRoot>/vendor`. The function is defined as a guarded global so it can be `require_once`'d safely from the test.

```php
<?php

/**
 * Test bootstrap for git-worktree agents.
 *
 * Git worktrees share the parent repo's vendor/ directory.
 * Composer's classmap hard-codes paths pointing to the main repo's app/ dir.
 * This bootstrap strips all App\* and Database\* classmap entries so that
 * PSR-4 fallback resolves them from the worktree instead.
 *
 * On a normal (non-worktree) checkout this is a no-op: the resolver returns
 * the worktree's own vendor and the classmap rewrite targets the same paths
 * it would have anyway.
 */
if (! function_exists('tests_resolve_main_vendor')) {
    /**
     * Resolve the vendor/ directory that owns the shared autoloader.
     *
     * In a linked git worktree, `git rev-parse --git-common-dir` prints an
     * absolute path to the MAIN repo's .git; its parent is the main repo root,
     * whose vendor/ holds the real (shared) autoloader. On a normal checkout
     * the common-dir resolves to this repo's own .git, so the parent equals
     * the worktree root and we fall through to the worktree's own vendor.
     *
     * Falls back to "<worktreeRoot>/vendor" whenever the git command fails or
     * the resolved main vendor has no autoload.php (e.g. CI, fresh checkout).
     */
    function tests_resolve_main_vendor(string $worktreeRoot): string
    {
        $worktreeVendor = $worktreeRoot.'/vendor';

        $output = [];
        $exitCode = 1;
        @exec(
            'git -C '.escapeshellarg($worktreeRoot).' rev-parse --git-common-dir 2>/dev/null',
            $output,
            $exitCode,
        );

        if ($exitCode !== 0 || $output === []) {
            return $worktreeVendor;
        }

        $commonDir = trim((string) $output[0]);
        if ($commonDir === '') {
            return $worktreeVendor;
        }

        // Make the common-dir absolute relative to the worktree root.
        if (! str_starts_with($commonDir, '/')) {
            $commonDir = $worktreeRoot.'/'.$commonDir;
        }

        $mainRoot = dirname((string) realpath($commonDir) ?: $commonDir);
        $mainVendor = $mainRoot.'/vendor';

        if ($mainVendor !== $worktreeVendor && is_file($mainVendor.'/autoload.php')) {
            return $mainVendor;
        }

        return $worktreeVendor;
    }
}

$worktreeRoot = dirname(__DIR__);
$mainVendor = tests_resolve_main_vendor($worktreeRoot);

// Load the shared autoloader
$loader = require $mainVendor.'/autoload.php';

// Strip App\* and Database\* entries from the compiled classmap via reflection.
// Without this, the classmap takes priority over PSR-4 and all App\* classes
// resolve to the main repo's app/ directory, ignoring worktree changes.
$ref = new ReflectionClass($loader);
$prop = $ref->getProperty('classMap');
$prop->setAccessible(true);

$classMap = $prop->getValue($loader);
$filtered = array_filter($classMap, static function (string $class): bool {
    return ! str_starts_with($class, 'App\\')
        && ! str_starts_with($class, 'Database\\')
        && ! str_starts_with($class, 'Tests\\');
}, ARRAY_FILTER_USE_KEY);
$prop->setValue($loader, $filtered);

// Redirect PSR-4 prefixes to the worktree directories.
// Must set more-specific sub-namespace prefixes first (PSR-4 uses longest-prefix wins),
// then the root prefix as fallback. Without the sub-namespace entries, autoload_psr4.php's
// pre-registered 'Database\\Seeders\\' and 'Database\\Factories\\' entries (pointing to
// the main repo) take precedence over our 'Database\\' root prefix.
$loader->setPsr4('App\\', [$worktreeRoot.'/app']);
$loader->setPsr4('Tests\\', [$worktreeRoot.'/tests']);
$loader->setPsr4('Database\\Seeders\\', [$worktreeRoot.'/database/seeders']);
$loader->setPsr4('Database\\Factories\\', [$worktreeRoot.'/database/factories']);
$loader->setPsr4('Database\\', [$worktreeRoot.'/database']);
```

- [ ] **Step 4: Run it, expect pass.** `php artisan test --filter=BootstrapVendorResolutionTest`. On this normal checkout the resolver returns the worktree's own vendor (verified earlier: `git rev-parse --git-common-dir` prints `.git`, whose parent is the repo root, so `$mainVendor === $worktreeVendor` and we fall back).

- [ ] **Step 5: Run full suite.** `php artisan test` — the harness must still bootstrap correctly on this machine (the resolver returns the same vendor it always did).

- [ ] **Step 6: Commit.**
```bash
git add tests/bootstrap.php tests/Unit/BootstrapVendorResolutionTest.php
git commit -m "$(cat <<'EOF'
fix(tests): resolve main-repo vendor dynamically in bootstrap

Replace the hardcoded /Users/sam absolute vendor path with a
git-common-dir resolver that no-ops on a normal checkout (returns the
worktree's own vendor) and only redirects to a shared main-repo vendor
inside a linked worktree. Unblocks CI and other checkouts.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Stream retry parity (§2.5)

`ChatService::generateResponse` wraps the provider call in `retry(3)` backoff (lines 80-109); `streamResponse` (lines 196-257) has no retry. Wrap the streamed provider dispatch in the same retry/backoff that retries only on 429/500/503/connection/timeout/CURL. Retry must wrap the **connection establishment** (`asStream()` returning the iterator), not mid-stream chunk iteration — once chunks start yielding, a mid-stream failure must not re-run and double-emit. Extract the stream-dispatch into a `dispatchStream()` protected method (mirroring `dispatchToProvider`) so the retry path is testable via a Mockery partial mock that throws on early attempts.

**Files:**
- Modify: `app/Services/LLM/ChatService.php` (`streamResponse` lines 196-257; add `dispatchStream()` helper near `dispatchToProvider`)
- Modify (test): `tests/Unit/Services/LLM/ChatServiceTest.php` (add stream-retry tests)

### Steps

- [ ] **Step 1: Write the failing test.** Append these two tests to `tests/Unit/Services/LLM/ChatServiceTest.php` (the file already imports `Mockery\MockInterface`, `Log`, and has `makeMockableService()` + `allowLogChannels()`). The first proves a retryable failure on the first stream attempt is retried and then yields chunks; the second proves a stream that fails on every attempt yields the fallback exactly once.

```php
    public function test_stream_retries_on_retryable_failure_then_yields_chunks(): void
    {
        $tenant = $this->configureTenant([]);
        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'session_id' => 'stream-retry',
            'status' => 'active',
        ]);

        $service = $this->makeMockableService();
        $callCount = 0;
        $service->shouldReceive('dispatchStream')
            ->times(2)
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                if ($callCount < 2) {
                    throw new \RuntimeException('HTTP 503 server error');
                }

                // Successful stream: one text event, no usage event.
                return (function () {
                    yield (object) ['text' => 'hello ', 'usage' => null];
                    yield (object) ['text' => 'world', 'usage' => null];
                })();
            });

        $this->allowLogChannels();

        $chunks = iterator_to_array($service->streamResponse($conversation, 'hi'));

        $this->assertSame(['hello ', 'world'], $chunks);
    }

    public function test_stream_total_failure_yields_fallback_once(): void
    {
        $tenant = $this->configureTenant([]);
        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'session_id' => 'stream-fail',
            'status' => 'active',
        ]);

        $service = $this->makeMockableService();
        $service->shouldReceive('dispatchStream')
            ->times(3)
            ->andThrow(new \RuntimeException('HTTP 503 server error'));

        $this->allowLogChannels();

        $chunks = iterator_to_array($service->streamResponse($conversation, 'hi'));

        $this->assertCount(1, $chunks, 'fallback yielded exactly once on total failure');
        $this->assertStringContainsString('having trouble', $chunks[0]);
    }
```

- [ ] **Step 2: Run it, expect fail.** `php artisan test --filter=ChatServiceTest` — the two new tests fail: `dispatchStream` is not a mockable method (it doesn't exist), so the mock expectation is never satisfied and the real `asStream()` runs against an unconfigured Prism.

- [ ] **Step 3: Implement `dispatchStream()` + retry in `streamResponse`.** In `app/Services/LLM/ChatService.php`, add the `dispatchStream()` helper immediately after `dispatchToProvider()` (after line 155):

```php
    /**
     * Single stream-dispatch wrapper around Prism, kept as its own method so
     * the retry path is testable: tests partial-mock this via Mockery to throw
     * on early attempts and return a generator on later ones. Establishes the
     * stream connection and returns the chunk iterator — retry wraps THIS call
     * (connection establishment), never mid-stream iteration.
     *
     * @param  array<int, UserMessage|AssistantMessage>  $messages
     * @return iterable<object>
     */
    protected function dispatchStream(string $systemPrompt, array $messages): iterable
    {
        return Prism::text()
            ->using($this->provider, $this->model)
            ->withSystemPrompt($systemPrompt)
            ->withMessages($messages)
            ->withClientOptions(['timeout' => 60])
            ->asStream();
    }
```

Then replace the `try { ... } catch (\Exception $e) { ... }` block in `streamResponse` (lines 214-256) so the `asStream()` call goes through `retry()` via `dispatchStream()`:

```php
        try {
            $stream = retry(
                times: 3,
                callback: function (int $attempt) use ($systemPrompt, $messages, $conversation) {
                    if ($attempt > 1) {
                        Log::warning('[LLM] (IS $) Stream retry attempt', [
                            'conversation_id' => $conversation->id,
                            'attempt' => $attempt,
                        ]);
                    }

                    return $this->dispatchStream($systemPrompt, $messages);
                },
                sleepMilliseconds: fn (int $attempt) => match ($attempt) {
                    1 => 1000,
                    2 => 2000,
                    default => 4000,
                },
                when: fn (\Throwable $e) => $this->isRetryable($e),
            );

            $promptTokens = 0;
            $completionTokens = 0;
            $totalTokens = 0;
            $fullResponse = '';

            foreach ($stream as $event) {
                // Only process text chunks, skip start/end events
                if (property_exists($event, 'text') && $event->text !== '') {
                    $fullResponse .= $event->text;
                    yield $event->text;
                }

                if (property_exists($event, 'usage') && $event->usage) {
                    $usage = $event->usage;
                    if (property_exists($usage, 'promptTokens') && $usage->promptTokens) {
                        $promptTokens = (int) $usage->promptTokens;
                    }
                    if (property_exists($usage, 'completionTokens') && $usage->completionTokens) {
                        $completionTokens = (int) $usage->completionTokens;
                    }
                    if (property_exists($usage, 'totalTokens') && $usage->totalTokens) {
                        $totalTokens = (int) $usage->totalTokens;
                    }
                }
            }

            $this->usageTracker->recordTokens($tenant, $conversation, $promptTokens, $completionTokens, $totalTokens);
        } catch (\Exception $e) {
            Log::error('[LLM] Stream failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            yield $this->getFallbackResponse();
        }
```

Finally, extract the retry predicate into a private helper so both paths share one definition. Add it after `dispatchStream()`:

```php
    /**
     * Whether a provider error is worth retrying. Transient transport/5xx/rate
     * conditions are retried; everything else (4xx other than 429, bad request,
     * auth) fails fast. Shared by generateResponse() and streamResponse().
     */
    private function isRetryable(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, '429')
            || str_contains($message, '500')
            || str_contains($message, '503')
            || str_contains($message, 'Connection')
            || str_contains($message, 'timeout')
            || str_contains($message, 'CURL');
    }
```

And replace the inline `when:` closure in `generateResponse` (lines 99-108) to call the shared helper:

```php
                when: fn (\Throwable $e) => $this->isRetryable($e),
```

- [ ] **Step 4: Run it, expect pass.** `php artisan test --filter=ChatServiceTest` — the two new stream tests pass and all pre-existing `generateResponse` retry tests (`test_failed_attempts_get_estimated_usage_record`, `test_total_failure_still_records_failed_attempt_usage`) still pass against the extracted `isRetryable()`.

- [ ] **Step 5: Run full suite.** `php artisan test`.

- [ ] **Step 6: Commit.**
```bash
git add app/Services/LLM/ChatService.php tests/Unit/Services/LLM/ChatServiceTest.php
git commit -m "$(cat <<'EOF'
feat(llm): add retry/backoff parity to ChatService::streamResponse

Wrap the stream connection-establishment (asStream) in the same retry(3)
backoff as generateResponse, gated on a shared isRetryable() predicate
(429/500/503/connection/timeout/CURL). Retry wraps connection setup, not
mid-stream chunk iteration, so a retried stream never double-emits.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Deploy runbook (§2.6)

Write `docs/superpowers/security-hardening-deploy.md` consolidating the ops gaps currently tracked only in agent memory: the two widget monitors (`widget_audit_failures` + the new `widget_dual_accept_passthrough` passthrough counter from PR 1) with alert thresholds, a sample systemd/supervisor unit for `egress-proxy.mjs`, the `TRUSTED_PROXIES` + dual-accept live-flip sequence, and the MCC/credit-account config decisions to confirm with DK. This task only writes the doc.

> **Dependency note:** This doc references the `widget_dual_accept_passthrough` counter and the `.env.example` dual-accept=false default that ship in **PR 1**. Since this branch is cut off `main` after PR 1 merges, those exist. If for any reason PR 1 has not merged, write the doc as-is (it documents the intended end state) and flag the dependency in the PR description.

**Files:**
- Create: `docs/superpowers/security-hardening-deploy.md`

### Steps

- [ ] **Step 1: Write the runbook.** Create `docs/superpowers/security-hardening-deploy.md` with the content below.

````markdown
# Security Hardening — Deploy & Ops Runbook

**Specs:** `docs/superpowers/specs/2026-06-16-security-hardening-cleanup-design.md`
**Plans:** `docs/superpowers/plans/2026-06-16-security-hardening.md` (PR 1),
`docs/superpowers/plans/2026-06-16-tech-debt-cleanup.md` (PR 2)

Consolidates the ops actions for the 2026-06-16 security + tech-debt batch that
do **not** ship automatically at merge. Nothing here changes merchant-facing
behavior on its own — each item is an explicit, gated ops step.

> Companion runbook: `docs/superpowers/crawler-ssrf-render-deploy.md` covers the
> crawler egress proxy + `CRAWLER_JS_RENDERING` flip. This document does not
> duplicate it; it references it where the egress proxy overlaps.

---

## 1. Widget monitors + alert thresholds

Two cache counters surface the health of the widget write-surface defenses.
Both are incremented in middleware and read by ops dashboards / alerts.

| Counter (cache key) | Incremented when | What it means | Alert |
|---|---|---|---|
| `widget_audit_failures` | A `WidgetAudit::log()` write throws and is swallowed | The audit trail is silently dropping events (DB/cache pressure, schema drift) | Page on **any** sustained nonzero rate (> 5 / 5 min) — audit gaps are a compliance risk |
| `widget_dual_accept_passthrough` | `RequireWidgetSessionToken` lets a token-less request through because `widget.session_dual_accept=true` | Legacy widgets are still hitting the write surface without a JWT | Track as a **burn-down to zero**. Alert if it stays nonzero approaching the planned strict-mode flip |

**Reading them (ad hoc):**
```bash
php artisan tinker --execute="echo cache('widget_audit_failures', 0).PHP_EOL; echo cache('widget_dual_accept_passthrough', 0).PHP_EOL;"
```

**Why the passthrough counter gates the dual-accept flip:** the strict-mode flip
(`WIDGET_SESSION_DUAL_ACCEPT=false`) is only safe once
`widget_dual_accept_passthrough` has been **flat at zero** for a full widget
cache/JWT TTL window — that proves every live widget is sending a JWT and no
real traffic will start 401'ing on the flip.

---

## 2. `TRUSTED_PROXIES` + dual-accept strict-mode flip

The widget JWT is IP-bound and the per-IP throttle keys on the client IP. Behind
a load balancer / CDN, the app sees the proxy IP unless `TRUSTED_PROXIES` is set
— so flipping dual-accept off **before** trusting proxies would collapse every
request's IP to the proxy, breaking IP-binding and rate-limits for all tenants.

**Order is mandatory:**

1. **Set `TRUSTED_PROXIES`** to the LB/CDN egress CIDR(s) (or `*` only if the app
   is not directly reachable from the internet). Deploy. Confirm `request()->ip()`
   resolves to real client IPs (spot-check the widget audit log's `ip` field — it
   should show varied client IPs, not one proxy IP).
2. **Confirm the burn-down:** `widget_dual_accept_passthrough` flat at zero for ≥
   one JWT TTL window (see §1).
3. **Flip:** set `WIDGET_SESSION_DUAL_ACCEPT=false`. Token-less
   `POST /api/v1/widget/message` now returns `401 session_token_required`.
4. **Restart the queue workers** after the env change — a long-running
   `queue:work` caches `.env` and will keep the old value otherwise.

`.env.example` already ships `WIDGET_SESSION_DUAL_ACCEPT=false` (PR 1) with the
TrustProxies caveat inline; the live default in prod stays `true` until this
sequence completes.

---

## 3. Egress proxy as a supervised process

When `CRAWLER_JS_RENDERING` is enabled, the Node validate-and-pin egress proxy
(`resources/node/egress-proxy.mjs`) must run as a supervised localhost process.
Full rationale and the env interlock live in
`docs/superpowers/crawler-ssrf-render-deploy.md` §2; this is the in-repo process
unit that doc said was missing.

### systemd unit (`/etc/systemd/system/abitchat-egress-proxy.service`)

```ini
[Unit]
Description=AbitChat crawler egress validate-and-pin proxy
After=network.target

[Service]
Type=simple
User=abitchat
WorkingDirectory=/var/www/abitchat
ExecStart=/usr/bin/node resources/node/egress-proxy.mjs 8118
Restart=always
RestartSec=2
# Bound to localhost inside the app; do not expose externally.
Environment=NODE_ENV=production

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload
systemctl enable --now abitchat-egress-proxy
systemctl status abitchat-egress-proxy
```

### supervisor alternative (`/etc/supervisor/conf.d/abitchat-egress-proxy.conf`)

```ini
[program:abitchat-egress-proxy]
command=/usr/bin/node resources/node/egress-proxy.mjs 8118
directory=/var/www/abitchat
user=abitchat
autostart=true
autorestart=true
startsecs=2
stdout_logfile=/var/log/abitchat/egress-proxy.out.log
stderr_logfile=/var/log/abitchat/egress-proxy.err.log
```

```bash
supervisorctl reread && supervisorctl update && supervisorctl start abitchat-egress-proxy
```

Then set `CRAWLER_EGRESS_PROXY=127.0.0.1:8118` and only after that
`CRAWLER_JS_RENDERING=true` (the `PageRenderer::enabled()` interlock keeps render
off until the proxy is configured).

---

## 4. DK Bank config decisions to confirm with DK

PR 1 made the DK integration tolerant/configurable but kept it dark behind
`DK_BANK_ENABLED=false`. Before any production flip, confirm these with DK and
set the env accordingly:

| Config | Default (PR 1) | Confirm with DK |
|---|---|---|
| `services.dk_bank.mcc_code` | `5817` | DK may require `5734`. Set `DK_BANK_MCC_CODE` to the value DK assigns. |
| `services.dk_bank.account_match` | `exact` | If DK returns a masked/reformatted `credit_account`, switch to `suffix`. |
| `services.dk_bank.account_match_digits` | `4` | Last-N digits compared in `suffix` mode. |
| RRN format | regex `^[A-Za-z0-9\/\- ]{4,32}$` (32 = `dk_rrn` column width) | Confirm real cross-bank RRNs (hyphens/slashes) pass; tighten only if DK specifies an exact format. |
| `extractPaidStatusData` envelope | object `response_data.status` with array `response_data[0].status` fallback | Confirm which shape DK production returns; both are parsed. |

DK end-to-end production verification stays out of scope until DK answers the
open settlement/masking questions (per spec Out-of-Scope). The killswitch keeps
the feature invisible (`EnsureDkBankEnabled` → 404) until then.

---

## 5. Carried-over pending deploys (not new in this batch)

These predate this batch and remain pending — listed here so the deploy owner
has one place to check:

- **Free-plan lifecycle (PR #40):** `php artisan migrate --force` (+ backfill),
  ensure the scheduler runs the daily lifecycle command, queue + Resend
  configured.
- **Post-scraping re-crawl (PR #41/#42):** re-crawl existing tenants after the
  clean-extraction + render changes deploy, so thin/SPA pages re-index.
````

- [ ] **Step 2: No test (pure doc).** Verify the file renders as valid Markdown and the fenced code blocks are balanced. `git diff --stat docs/superpowers/security-hardening-deploy.md` should show the new file.

- [ ] **Step 3: Commit.**
```bash
git add docs/superpowers/security-hardening-deploy.md
git commit -m "$(cat <<'EOF'
docs: add security-hardening deploy runbook

Consolidate widget monitors (audit-failures + dual-accept passthrough)
with alert thresholds, the TRUSTED_PROXIES + dual-accept flip sequence,
a systemd/supervisor unit for the egress proxy, and DK config decisions
to confirm before any production flip.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Pint + PHPStan-zero + browser smoke

Final quality gate. Pint (deterministic, never skip), PHPStan baseline must stay at zero, and a browser smoke of the admin restore/sort/trashed flow plus an audit row appearing on the Activity Logs page.

**Files:** none new — runs against everything touched in Tasks 1-6.

### Steps

- [ ] **Step 1: Pint check.** `./vendor/bin/pint --test` (scope mentally to PR-touched files: the three service files, `AdminActivityLog`, the four admin controllers, `ChatService`, `tests/bootstrap.php`, and the five new test files). If anything is flagged:
  - `./vendor/bin/pint` (apply fixes)
  - `php artisan test` (confirm nothing broke)
  - Commit: `git add -A && git commit -m "style(pint): apply auto-fixes` … with the Co-Authored-By trailer. Scope the commit to PR-touched files only — do not sweep pre-existing style debt on unrelated files.

- [ ] **Step 2: PHPStan baseline stays zero.** `vendor/bin/phpstan analyse` — must report **no** errors and **no** new baseline entries. Pay attention to: the new `AdminActivityLog` import in four controllers, the `LeadScoring` import in `AnalyticsService`, the new `dispatchStream(): iterable` return type, and the `tests_resolve_main_vendor()` global function (it lives in a non-namespaced bootstrap file, so confirm PHPStan's paths config doesn't flag it; if it does, it is test-bootstrap infra and may be excluded the same way `tests/bootstrap.php` already is). If PHPStan flags the `\Throwable` catch blocks or the `iterable<object>` generic, fix the code — **do not** add baseline entries.

- [ ] **Step 3: Full suite one more time.** `php artisan test` — everything green.

- [ ] **Step 4: Build the frontend.** `pnpm run build` — confirms the updated `Clients/Index.vue` compiles for production.

- [ ] **Step 5: Browser smoke (manual, per CLAUDE.md Layer 3).** Start the dev server (`composer dev` or the project's run skill), log in as a super_admin (create via `php artisan admin:create` if needed), then:
  1. **Trashed + sort:** Go to `http://127.0.0.1:8001/admin/clients`. Confirm the new trashed `<select>` (Active only / Include deleted / Deleted only) and clickable sort headers (Client, Status, Conversations, Leads, Created with ▲/▼ indicators) render and re-query on change. Soft-delete a tenant (or pick an existing deleted one), switch trashed to "Deleted only", confirm it appears with a red "Deleted" badge.
  2. **Restore:** Click **Restore** on a deleted row. Confirm it redirects to the client show page with the "Tenant restored." flash and the tenant is active again.
  3. **Audit row appears:** Perform an admin mutation (approve/reject a transaction, or update a client status), then open the Activity Logs page (`/admin/activity-logs` or wherever the `AdminActivityLog` index renders) and confirm a new row appears with the correct human label (e.g. "Updated client status", "Restored client") — proving the formerly-empty page is now populated.
  4. Watch the browser console + Laravel log for errors during all of the above.

- [ ] **Step 6: Run `/simplify` twice interleaved with Pint** (per CLAUDE.md feature-dev process): `/simplify` → apply real fixes → `./vendor/bin/pint --test` (+ fix/commit if flagged) → `/simplify` again. Skip stylistic-only noise with a one-line reason each.

- [ ] **Step 7: No code commit if Steps 1-2 were clean.** If Pint/`/simplify` produced fixes, they were committed in their own `style(pint):` / cleanup commits above. The PR is now ready: open it off `main` (after PR 1 is merged) with the Deploy-steps template, linking the spec and this plan.
