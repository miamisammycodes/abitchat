<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Exceptions\Billing\TransactionAlreadyProcessed;
use App\Exceptions\Billing\TransactionPlanInactive;
use App\Exceptions\Billing\TransactionStatusNotAllowed;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use Tests\TestCase;

class TransactionApproveAndActivateTest extends TestCase
{
    private function makePlan(): Plan
    {
        return Plan::create([
            'name' => 'Pro', 'slug' => 'pro', 'description' => 'Pro plan',
            'price' => 1000, 'billing_period' => 'yearly',
            'conversations_limit' => 1000, 'messages_per_conversation' => 100,
            'knowledge_items_limit' => 50, 'tokens_limit' => 100000, 'leads_limit' => 1000,
            'is_active' => true, 'sort_order' => 1,
        ]);
    }

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'trial_ends_at' => now()->subDay(),
        ]);
    }

    public function test_approves_from_pending(): void
    {
        $tenant = $this->makeTenant();
        $plan = $this->makePlan();
        $admin = $this->createSuperAdmin();
        $tx = Transaction::create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
            'transaction_number' => 'TXN1', 'reference_number' => 'REF001',
            'amount' => 1000, 'payment_method' => 'bob',
            'payment_date' => now(), 'status' => 'pending',
        ]);

        $tx->approveAndActivate(['pending'], adminId: $admin->id, adminNotes: 'Verified');

        $tx->refresh();
        $this->assertSame('approved', $tx->status);
        $this->assertSame($admin->id, $tx->approved_by);
        $this->assertSame('Verified', $tx->admin_notes);
        $this->assertNotNull($tx->approved_at);

        $tenant->refresh();
        $this->assertSame($plan->id, $tenant->plan_id);
        $this->assertNotNull($tenant->plan_expires_at);
    }

    public function test_approves_from_awaiting_payment(): void
    {
        $tenant = $this->makeTenant();
        $plan = $this->makePlan();
        $tx = Transaction::create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
            'amount' => 1000, 'payment_method' => 'dk_qr',
            'payment_date' => now(), 'status' => 'awaiting_payment',
            'dk_reference_no' => 'DKQR-001-AAAA',
        ]);

        $tx->approveAndActivate(['awaiting_payment'], adminId: null);

        $tx->refresh();
        $this->assertSame('approved', $tx->status);
        $this->assertNull($tx->approved_by);
    }

    public function test_throws_when_status_not_in_allowed_list(): void
    {
        $tenant = $this->makeTenant();
        $plan = $this->makePlan();
        $tx = Transaction::create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
            'amount' => 1000, 'payment_method' => 'bob',
            'payment_date' => now(), 'status' => 'awaiting_payment',
            'dk_reference_no' => 'DKQR-002-BBBB',
        ]);

        $this->expectException(TransactionStatusNotAllowed::class);
        $tx->approveAndActivate(['pending']);
    }

    public function test_throws_when_already_approved(): void
    {
        $tenant = $this->makeTenant();
        $plan = $this->makePlan();
        $admin = $this->createSuperAdmin();
        $tx = Transaction::create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
            'transaction_number' => 'TXN3', 'reference_number' => 'REF003',
            'amount' => 1000, 'payment_method' => 'bob',
            'payment_date' => now(), 'status' => 'approved',
            'approved_at' => now(), 'approved_by' => $admin->id,
        ]);

        $this->expectException(TransactionAlreadyProcessed::class);
        $tx->approveAndActivate(['pending']);
    }

    public function test_throws_when_plan_inactive(): void
    {
        $tenant = $this->makeTenant();
        $plan = $this->makePlan();
        $plan->update(['is_active' => false]);

        $tx = Transaction::create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
            'transaction_number' => 'TXN4', 'reference_number' => 'REF004',
            'amount' => 1000, 'payment_method' => 'bob',
            'payment_date' => now(), 'status' => 'pending',
        ]);

        $this->expectException(TransactionPlanInactive::class);
        $tx->approveAndActivate(['pending']);
    }
}
