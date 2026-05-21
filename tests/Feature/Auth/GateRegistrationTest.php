<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Ability;
use App\Models\Conversation;
use App\Models\KnowledgeItem;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Policies\ConversationPolicy;
use App\Policies\KnowledgeItemPolicy;
use App\Policies\LeadPolicy;
use App\Policies\TransactionPolicy;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\SeedsRoleMatrix;
use Tests\TestCase;

/**
 * Tests that all 13 ability Gates are registered in AppServiceProvider::boot().
 * Covers D-10: Laravel Gates registered for every Ability case.
 */
class GateRegistrationTest extends TestCase
{
    use SeedsRoleMatrix;

    /** @test */
    public function test_all_13_ability_gates_are_registered(): void
    {
        foreach (Ability::cases() as $ability) {
            $this->assertTrue(
                Gate::has($ability->value),
                "Gate '{$ability->value}' is not registered"
            );
        }

        $this->assertCount(13, Ability::cases(), 'Expected 13 Ability cases');
    }

    /** @test */
    public function test_four_policy_bindings_are_registered(): void
    {
        $this->assertInstanceOf(ConversationPolicy::class, Gate::getPolicyFor(Conversation::class));
        $this->assertInstanceOf(LeadPolicy::class, Gate::getPolicyFor(Lead::class));
        $this->assertInstanceOf(KnowledgeItemPolicy::class, Gate::getPolicyFor(KnowledgeItem::class));
        $this->assertInstanceOf(TransactionPolicy::class, Gate::getPolicyFor(Transaction::class));
    }

    /** @test */
    public function test_owner_can_manage_billing(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsOwner($tenant);

        $this->assertTrue(Gate::forUser($this->ownerUser)->check('manage-billing'));
    }

    /** @test */
    public function test_manager_cannot_manage_billing(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsManager($tenant);

        $this->assertFalse(Gate::forUser($this->managerUser)->check('manage-billing'));
    }

    /** @test */
    public function test_manager_can_manage_knowledge_base(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsManager($tenant);

        $this->assertTrue(Gate::forUser($this->managerUser)->check('manage-knowledge-base'));
    }

    /** @test */
    public function test_agent_cannot_manage_knowledge_base(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsAgent($tenant);

        $this->assertFalse(Gate::forUser($this->agentUser)->check('manage-knowledge-base'));
    }

    /** @test */
    public function test_agent_can_manage_conversations(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsAgent($tenant);

        $this->assertTrue(Gate::forUser($this->agentUser)->check('manage-conversations'));
    }

    /** @test */
    public function test_super_admin_can_view_admin_dashboard(): void
    {
        $this->actingAsSuperAdmin();

        $this->assertTrue(Gate::forUser($this->superAdminUser)->check('view-admin-dashboard'));
    }

    /** @test */
    public function test_super_admin_cannot_manage_billing_tenant_ability(): void
    {
        // super_admin is orthogonal — not in tenant hierarchy
        $this->actingAsSuperAdmin();

        $this->assertFalse(Gate::forUser($this->superAdminUser)->check('manage-billing'));
    }
}
