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
