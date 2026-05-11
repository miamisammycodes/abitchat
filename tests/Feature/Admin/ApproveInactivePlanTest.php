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
