<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TransactionApprovalTest extends TestCase
{
    public function test_approval_preserves_remaining_paid_time(): void
    {
        $admin = $this->createAdmin();
        $this->actingAsTenantUser(); // sets $this->tenant

        $plan = Plan::create([
            'name' => 'Starter',
            'slug' => 'starter-approval-test',
            'description' => 'Plan',
            'price' => 9.99,
            'billing_period' => 'month',
            'conversations_limit' => 100,
            'messages_per_conversation' => 50,
            'knowledge_items_limit' => 50,
            'tokens_limit' => 100000,
            'leads_limit' => 500,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $existingExpiry = now()->addDays(10);
        $this->tenant->update([
            'plan_id' => $plan->id,
            'plan_expires_at' => $existingExpiry,
        ]);

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'transaction_number' => 'TXN-RENEWAL',
            'reference_number' => 'ABC123',
            'amount' => 9.99,
            'payment_method' => 'bob',
            'payment_date' => now()->format('Y-m-d'),
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->post("/admin/transactions/{$transaction->id}/approve", ['admin_notes' => 'ok'])
            ->assertRedirect();

        $this->tenant->refresh();
        // Expected: existing expiry + 1 month, not now + 1 month.
        $expected = $existingExpiry->copy()->addMonth();
        $this->assertSame(
            $expected->format('Y-m-d H:i'),
            $this->tenant->plan_expires_at->format('Y-m-d H:i'),
            'Approval should extend from existing future expiry, not from now.'
        );
    }

    public function test_approval_uses_now_when_no_existing_expiry(): void
    {
        $admin = $this->createAdmin();
        $this->actingAsTenantUser();

        $plan = Plan::create([
            'name' => 'Starter',
            'slug' => 'starter-fresh-test',
            'description' => 'Plan',
            'price' => 9.99,
            'billing_period' => 'month',
            'conversations_limit' => 100,
            'messages_per_conversation' => 50,
            'knowledge_items_limit' => 50,
            'tokens_limit' => 100000,
            'leads_limit' => 500,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'transaction_number' => 'TXN-FRESH',
            'reference_number' => 'XYZ123',
            'amount' => 9.99,
            'payment_method' => 'bob',
            'payment_date' => now()->format('Y-m-d'),
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->post("/admin/transactions/{$transaction->id}/approve")
            ->assertRedirect();

        $this->tenant->refresh();
        $this->assertSame(
            now()->addMonth()->format('Y-m-d H:i'),
            $this->tenant->plan_expires_at->format('Y-m-d H:i')
        );
    }

    public function test_extend_plan_re_reads_expiry_under_lock(): void
    {
        $this->actingAsTenantUser();

        $plan = Plan::create([
            'name' => 'Starter',
            'slug' => 'starter-lock-test',
            'description' => 'Plan',
            'price' => 9.99,
            'billing_period' => 'month',
            'conversations_limit' => 100,
            'messages_per_conversation' => 50,
            'knowledge_items_limit' => 50,
            'tokens_limit' => 100000,
            'leads_limit' => 500,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $initialExpiry = now()->addDays(10)->startOfDay();
        $this->tenant->update([
            'plan_id' => $plan->id,
            'plan_expires_at' => $initialExpiry,
        ]);

        // Load a stale in-memory copy (simulates the second concurrent admin
        // request having read the tenant before the first admin's commit).
        $stale = Tenant::find($this->tenant->id);
        $this->assertTrue($stale->plan_expires_at->equalTo($initialExpiry));

        // Simulate the first admin's transaction having already committed an
        // extension to T+10 + 1mo. The stale in-memory copy still shows T+10.
        $afterFirstExtension = $initialExpiry->copy()->addMonth();
        Tenant::whereKey($this->tenant->id)->update([
            'plan_expires_at' => $afterFirstExtension,
        ]);

        // Second admin calls extendPlan on the stale instance. With the fix,
        // it must re-read the fresh expiry under lockForUpdate and add a
        // month to T+10+1mo, NOT to T+10.
        DB::transaction(function () use ($stale, $plan) {
            $stale->extendPlan($plan);
        });

        $this->tenant->refresh();
        $expected = $afterFirstExtension->copy()->addMonth();
        $this->assertSame(
            $expected->format('Y-m-d H:i'),
            $this->tenant->plan_expires_at->format('Y-m-d H:i'),
            'extendPlan must base off the fresh DB row, not the stale in-memory expiry.'
        );
    }
}
