<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Policies\ConversationPolicy;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\SeedsRoleMatrix;
use Tests\TestCase;

/**
 * Tests ConversationPolicy stacks tenant-ownership check AND role-tier check (Agent+):
 * - Owner, Manager, Agent of the same tenant can view/update/delete conversations
 * - Agent of a different tenant cannot access (ownership clause)
 * - SuperAdmin without a tenant role cannot access (orthogonal)
 */
class ConversationPolicyTest extends TestCase
{
    use SeedsRoleMatrix;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $this->conversation = Conversation::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    /** @test */
    public function test_owner_can_view_conversation(): void
    {
        $this->actingAsOwner($this->tenant);
        $this->assertTrue(Gate::forUser($this->ownerUser)->allows('view', $this->conversation));
    }

    /** @test */
    public function test_owner_can_update_conversation(): void
    {
        $this->actingAsOwner($this->tenant);
        $this->assertTrue(Gate::forUser($this->ownerUser)->allows('update', $this->conversation));
    }

    /** @test */
    public function test_owner_can_delete_conversation(): void
    {
        $this->actingAsOwner($this->tenant);
        $this->assertTrue(Gate::forUser($this->ownerUser)->allows('delete', $this->conversation));
    }

    /** @test */
    public function test_manager_can_view_conversation(): void
    {
        $this->actingAsManager($this->tenant);
        $this->assertTrue(Gate::forUser($this->managerUser)->allows('view', $this->conversation));
    }

    /** @test */
    public function test_manager_can_update_conversation(): void
    {
        $this->actingAsManager($this->tenant);
        $this->assertTrue(Gate::forUser($this->managerUser)->allows('update', $this->conversation));
    }

    /** @test */
    public function test_manager_can_delete_conversation(): void
    {
        $this->actingAsManager($this->tenant);
        $this->assertTrue(Gate::forUser($this->managerUser)->allows('delete', $this->conversation));
    }

    /** @test */
    public function test_agent_can_view_conversation(): void
    {
        $this->actingAsAgent($this->tenant);
        $this->assertTrue(Gate::forUser($this->agentUser)->allows('view', $this->conversation));
    }

    /** @test */
    public function test_agent_can_update_conversation(): void
    {
        $this->actingAsAgent($this->tenant);
        $this->assertTrue(Gate::forUser($this->agentUser)->allows('update', $this->conversation));
    }

    /** @test */
    public function test_agent_can_delete_conversation(): void
    {
        $this->actingAsAgent($this->tenant);
        $this->assertTrue(Gate::forUser($this->agentUser)->allows('delete', $this->conversation));
    }

    /** @test */
    public function test_cross_tenant_user_cannot_view_conversation(): void
    {
        $otherTenant = Tenant::factory()->create();
        $this->actingAsAgent($otherTenant);
        $this->assertFalse(Gate::forUser($this->agentUser)->allows('view', $this->conversation));
    }

    /** @test */
    public function test_cross_tenant_user_cannot_update_conversation(): void
    {
        $otherTenant = Tenant::factory()->create();
        $this->actingAsOwner($otherTenant);
        $this->assertFalse(Gate::forUser($this->ownerUser)->allows('update', $this->conversation));
    }

    /** @test */
    public function test_super_admin_cannot_access_tenant_conversation(): void
    {
        // super_admin is orthogonal to tenant hierarchy — no tenant role means no access
        $this->actingAsSuperAdmin();
        $this->assertFalse(Gate::forUser($this->superAdminUser)->allows('view', $this->conversation));
    }
}
