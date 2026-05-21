<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\Lead;
use App\Models\Tenant;
use App\Policies\LeadPolicy;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\SeedsRoleMatrix;
use Tests\TestCase;

/**
 * Tests LeadPolicy stacks tenant-ownership check AND role-tier check (Agent+):
 * - Owner, Manager, Agent of the same tenant can view/update/delete leads
 * - Agent of a different tenant cannot access (ownership clause)
 * - SuperAdmin without a tenant role cannot access (orthogonal)
 * - Agent can also create leads (Agent+ tier with no resource ownership clause)
 */
class LeadPolicyTest extends TestCase
{
    use SeedsRoleMatrix;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $this->lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Lead',
            'email' => 'lead@example.com',
            'status' => 'new',
        ]);
    }

    /** @test */
    public function test_owner_can_view_lead(): void
    {
        $this->actingAsOwner($this->tenant);
        $this->assertTrue(Gate::forUser($this->ownerUser)->allows('view', $this->lead));
    }

    /** @test */
    public function test_owner_can_update_lead(): void
    {
        $this->actingAsOwner($this->tenant);
        $this->assertTrue(Gate::forUser($this->ownerUser)->allows('update', $this->lead));
    }

    /** @test */
    public function test_owner_can_delete_lead(): void
    {
        $this->actingAsOwner($this->tenant);
        $this->assertTrue(Gate::forUser($this->ownerUser)->allows('delete', $this->lead));
    }

    /** @test */
    public function test_manager_can_view_lead(): void
    {
        $this->actingAsManager($this->tenant);
        $this->assertTrue(Gate::forUser($this->managerUser)->allows('view', $this->lead));
    }

    /** @test */
    public function test_agent_can_view_lead(): void
    {
        $this->actingAsAgent($this->tenant);
        $this->assertTrue(Gate::forUser($this->agentUser)->allows('view', $this->lead));
    }

    /** @test */
    public function test_agent_can_update_lead(): void
    {
        $this->actingAsAgent($this->tenant);
        $this->assertTrue(Gate::forUser($this->agentUser)->allows('update', $this->lead));
    }

    /** @test */
    public function test_agent_can_create_lead(): void
    {
        // create has no resource arg — ownership enforced on insert via BelongsToTenant
        $this->actingAsAgent($this->tenant);
        $this->assertTrue(Gate::forUser($this->agentUser)->allows('create', Lead::class));
    }

    /** @test */
    public function test_cross_tenant_user_cannot_view_lead(): void
    {
        $otherTenant = Tenant::factory()->create();
        $this->actingAsAgent($otherTenant);
        $this->assertFalse(Gate::forUser($this->agentUser)->allows('view', $this->lead));
    }

    /** @test */
    public function test_cross_tenant_user_cannot_delete_lead(): void
    {
        $otherTenant = Tenant::factory()->create();
        $this->actingAsOwner($otherTenant);
        $this->assertFalse(Gate::forUser($this->ownerUser)->allows('delete', $this->lead));
    }

    /** @test */
    public function test_super_admin_cannot_access_tenant_lead(): void
    {
        $this->actingAsSuperAdmin();
        $this->assertFalse(Gate::forUser($this->superAdminUser)->allows('view', $this->lead));
    }

    /** @test */
    public function test_super_admin_cannot_create_lead(): void
    {
        // super_admin has no tenant context, so manage-leads returns false
        $this->actingAsSuperAdmin();
        $this->assertFalse(Gate::forUser($this->superAdminUser)->allows('create', Lead::class));
    }
}
