<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Policies\KnowledgeItemPolicy;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\SeedsRoleMatrix;
use Tests\TestCase;

/**
 * Tests KnowledgeItemPolicy stacks tenant-ownership check AND role-tier check (Manager+):
 * - Owner and Manager of the same tenant can view/create/update/delete knowledge items
 * - Agent of the same tenant is denied (fails Manager+ check)
 * - Agent of a different tenant cannot access (ownership clause)
 * - SuperAdmin is orthogonal — no access to tenant knowledge items
 */
class KnowledgeItemPolicyTest extends TestCase
{
    use SeedsRoleMatrix;

    private KnowledgeItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $this->item = KnowledgeItem::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    /** @test */
    public function test_owner_can_view_knowledge_item(): void
    {
        $this->actingAsOwner($this->tenant);
        $this->assertTrue(Gate::forUser($this->ownerUser)->allows('view', $this->item));
    }

    /** @test */
    public function test_owner_can_create_knowledge_item(): void
    {
        // create has no resource arg — ownership enforced on insert via BelongsToTenant
        $this->actingAsOwner($this->tenant);
        $this->assertTrue(Gate::forUser($this->ownerUser)->allows('create', KnowledgeItem::class));
    }

    /** @test */
    public function test_owner_can_update_knowledge_item(): void
    {
        $this->actingAsOwner($this->tenant);
        $this->assertTrue(Gate::forUser($this->ownerUser)->allows('update', $this->item));
    }

    /** @test */
    public function test_owner_can_delete_knowledge_item(): void
    {
        $this->actingAsOwner($this->tenant);
        $this->assertTrue(Gate::forUser($this->ownerUser)->allows('delete', $this->item));
    }

    /** @test */
    public function test_manager_can_view_knowledge_item(): void
    {
        $this->actingAsManager($this->tenant);
        $this->assertTrue(Gate::forUser($this->managerUser)->allows('view', $this->item));
    }

    /** @test */
    public function test_manager_can_create_knowledge_item(): void
    {
        $this->actingAsManager($this->tenant);
        $this->assertTrue(Gate::forUser($this->managerUser)->allows('create', KnowledgeItem::class));
    }

    /** @test */
    public function test_manager_can_update_knowledge_item(): void
    {
        $this->actingAsManager($this->tenant);
        $this->assertTrue(Gate::forUser($this->managerUser)->allows('update', $this->item));
    }

    /** @test */
    public function test_agent_cannot_view_knowledge_item(): void
    {
        // Agent fails Manager+ check
        $this->actingAsAgent($this->tenant);
        $this->assertFalse(Gate::forUser($this->agentUser)->allows('view', $this->item));
    }

    /** @test */
    public function test_agent_cannot_create_knowledge_item(): void
    {
        $this->actingAsAgent($this->tenant);
        $this->assertFalse(Gate::forUser($this->agentUser)->allows('create', KnowledgeItem::class));
    }

    /** @test */
    public function test_agent_cannot_update_knowledge_item(): void
    {
        $this->actingAsAgent($this->tenant);
        $this->assertFalse(Gate::forUser($this->agentUser)->allows('update', $this->item));
    }

    /** @test */
    public function test_cross_tenant_user_cannot_view_knowledge_item(): void
    {
        $otherTenant = Tenant::factory()->create();
        $this->actingAsManager($otherTenant);
        $this->assertFalse(Gate::forUser($this->managerUser)->allows('view', $this->item));
    }

    /** @test */
    public function test_super_admin_cannot_access_tenant_knowledge_item(): void
    {
        $this->actingAsSuperAdmin();
        $this->assertFalse(Gate::forUser($this->superAdminUser)->allows('view', $this->item));
    }
}
