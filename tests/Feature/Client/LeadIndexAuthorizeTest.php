<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Models\Tenant;
use App\Models\User;
use Tests\Concerns\SeedsRoleMatrix;
use Tests\TestCase;

/**
 * LeadController::index() must gate on Ability::ManageLeads like every other
 * method in the controller — it previously relied only on route-group auth
 * middleware, leaving the list readable by any authenticated user without the
 * manage-leads ability.
 */
class LeadIndexAuthorizeTest extends TestCase
{
    use SeedsRoleMatrix;

    private Tenant $leadTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->leadTenant = Tenant::create([
            'name' => 'Lead Co', 'slug' => 'lead-co',
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function test_agent_with_manage_leads_can_view_index(): void
    {
        // Agent is the minimum role that holds ManageLeads.
        $this->actingAsAgent($this->leadTenant);

        $this->get('/leads')->assertOk();
    }

    public function test_user_without_manage_leads_is_forbidden(): void
    {
        // A tenant user with NO role fails the ManageLeads gate.
        $user = User::create([
            'name' => 'No Role', 'email' => 'norole@example.com',
            'password' => bcrypt('password'), 'tenant_id' => $this->leadTenant->id,
        ]);
        $this->actingAs($user);

        $this->get('/leads')->assertForbidden();
    }
}
