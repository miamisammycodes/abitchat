<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Policies\TransactionPolicy;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\SeedsRoleMatrix;
use Tests\TestCase;

/**
 * Tests TransactionPolicy stacks tenant-ownership check AND role-tier check (Owner only):
 * - Owner of the same tenant can view transactions
 * - Manager and Agent of the same tenant are denied (fails Owner-exact check)
 * - User from a different tenant cannot access (ownership clause)
 * - SuperAdmin is orthogonal — no access to tenant transactions
 */
class TransactionPolicyTest extends TestCase
{
    use SeedsRoleMatrix;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $plan = Plan::first() ?? Plan::create([
            'name' => 'Test Plan',
            'slug' => 'test-plan',
            'price' => 1000,
            'is_active' => true,
            'limits' => [],
        ]);

        $this->transaction = Transaction::create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'transaction_number' => 'TXN-POLICY-' . uniqid(),
            'amount' => 1000,
            'payment_method' => 'bank_transfer',
            'payment_date' => now(),
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function test_owner_can_view_transaction(): void
    {
        $this->actingAsOwner($this->tenant);
        $this->assertTrue(Gate::forUser($this->ownerUser)->allows('view', $this->transaction));
    }

    /** @test */
    public function test_manager_cannot_view_transaction(): void
    {
        // manage-billing is Owner-only; Manager fails the role-tier check
        $this->actingAsManager($this->tenant);
        $this->assertFalse(Gate::forUser($this->managerUser)->allows('view', $this->transaction));
    }

    /** @test */
    public function test_agent_cannot_view_transaction(): void
    {
        $this->actingAsAgent($this->tenant);
        $this->assertFalse(Gate::forUser($this->agentUser)->allows('view', $this->transaction));
    }

    /** @test */
    public function test_cross_tenant_user_cannot_view_transaction(): void
    {
        $otherTenant = Tenant::factory()->create();
        $this->actingAsOwner($otherTenant);
        $this->assertFalse(Gate::forUser($this->ownerUser)->allows('view', $this->transaction));
    }

    /** @test */
    public function test_super_admin_cannot_view_tenant_transaction(): void
    {
        $this->actingAsSuperAdmin();
        $this->assertFalse(Gate::forUser($this->superAdminUser)->allows('view', $this->transaction));
    }
}
