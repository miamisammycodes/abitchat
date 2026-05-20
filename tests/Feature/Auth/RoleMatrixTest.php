<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Ability;
use App\Enums\Role;
use App\Models\Tenant;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SeedsRoleMatrix;
use Tests\TestCase;

/**
 * The 52-cell load-bearing role × ability matrix test.
 * For each of the 13 abilities, verifies that the minimum required role passes
 * and every lower role fails at the Gate level.
 *
 * Filled by Plan 07 (D-19: full role × ability matrix coverage).
 */
class RoleMatrixTest extends TestCase
{
    use SeedsRoleMatrix;

    private Tenant $testTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testTenant = Tenant::create([
            'name' => 'Matrix Test Tenant',
            'slug' => 'matrix-test-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    #[DataProvider('roleAbilityMatrix')]
    public function test_role_ability_matrix(string $roleKey, string $abilityValue, bool $expected): void
    {
        $user = match ($roleKey) {
            'super_admin' => $this->actingAsSuperAdmin()->superAdminUser,
            'owner'       => $this->actingAsOwner($this->testTenant)->ownerUser,
            'manager'     => $this->actingAsManager($this->testTenant)->managerUser,
            'agent'       => $this->actingAsAgent($this->testTenant)->agentUser,
        };

        $result = Gate::forUser($user)->check($abilityValue);

        $this->assertSame(
            $expected,
            $result,
            sprintf(
                'Expected role "%s" to %s ability "%s" via Gate',
                $roleKey,
                $expected ? 'PASS' : 'FAIL',
                $abilityValue
            )
        );
    }

    /** @return array<string, array{string, string, bool}> */
    public static function roleAbilityMatrix(): array
    {
        return [
            // ── SuperAdmin-only abilities ──────────────────────────────────
            // Requires Role::SuperAdmin exactly; tenant-scoped roles cannot pass.
            'super_admin can view-admin-dashboard'       => ['super_admin', Ability::ViewAdminDashboard->value,    true],
            'owner cannot view-admin-dashboard'          => ['owner',       Ability::ViewAdminDashboard->value,    false],
            'manager cannot view-admin-dashboard'        => ['manager',     Ability::ViewAdminDashboard->value,    false],
            'agent cannot view-admin-dashboard'          => ['agent',       Ability::ViewAdminDashboard->value,    false],

            'super_admin can manage-tenant-as-admin'     => ['super_admin', Ability::ManageTenantAsAdmin->value,  true],
            'owner cannot manage-tenant-as-admin'        => ['owner',       Ability::ManageTenantAsAdmin->value,  false],
            'manager cannot manage-tenant-as-admin'      => ['manager',     Ability::ManageTenantAsAdmin->value,  false],
            'agent cannot manage-tenant-as-admin'        => ['agent',       Ability::ManageTenantAsAdmin->value,  false],

            'super_admin can manage-platform-settings'   => ['super_admin', Ability::ManagePlatformSettings->value, true],
            'owner cannot manage-platform-settings'      => ['owner',       Ability::ManagePlatformSettings->value, false],
            'manager cannot manage-platform-settings'    => ['manager',     Ability::ManagePlatformSettings->value, false],
            'agent cannot manage-platform-settings'      => ['agent',       Ability::ManagePlatformSettings->value, false],

            // ── Owner-only abilities ───────────────────────────────────────
            // Requires at least Role::Owner in the tenant; Manager and Agent fail.
            // SuperAdmin has no tenant role → also fails.
            'super_admin cannot manage-billing'          => ['super_admin', Ability::ManageBilling->value,        false],
            'owner can manage-billing'                   => ['owner',       Ability::ManageBilling->value,         true],
            'manager cannot manage-billing'              => ['manager',     Ability::ManageBilling->value,         false],
            'agent cannot manage-billing'                => ['agent',       Ability::ManageBilling->value,         false],

            'super_admin cannot manage-team'             => ['super_admin', Ability::ManageTeam->value,           false],
            'owner can manage-team'                      => ['owner',       Ability::ManageTeam->value,            true],
            'manager cannot manage-team'                 => ['manager',     Ability::ManageTeam->value,            false],
            'agent cannot manage-team'                   => ['agent',       Ability::ManageTeam->value,            false],

            'super_admin cannot manage-tenant-settings'  => ['super_admin', Ability::ManageTenantSettings->value, false],
            'owner can manage-tenant-settings'           => ['owner',       Ability::ManageTenantSettings->value,  true],
            'manager cannot manage-tenant-settings'      => ['manager',     Ability::ManageTenantSettings->value,  false],
            'agent cannot manage-tenant-settings'        => ['agent',       Ability::ManageTenantSettings->value,  false],

            'super_admin cannot delete-tenant'           => ['super_admin', Ability::DeleteTenant->value,         false],
            'owner can delete-tenant'                    => ['owner',       Ability::DeleteTenant->value,          true],
            'manager cannot delete-tenant'               => ['manager',     Ability::DeleteTenant->value,          false],
            'agent cannot delete-tenant'                 => ['agent',       Ability::DeleteTenant->value,          false],

            // ── Manager+ abilities ─────────────────────────────────────────
            // Requires at least Role::Manager; Owner also passes; Agent fails.
            // SuperAdmin has no tenant role → fails.
            'super_admin cannot manage-knowledge-base'   => ['super_admin', Ability::ManageKnowledgeBase->value,  false],
            'owner can manage-knowledge-base'            => ['owner',       Ability::ManageKnowledgeBase->value,   true],
            'manager can manage-knowledge-base'          => ['manager',     Ability::ManageKnowledgeBase->value,   true],
            'agent cannot manage-knowledge-base'         => ['agent',       Ability::ManageKnowledgeBase->value,   false],

            'super_admin cannot manage-integrations'     => ['super_admin', Ability::ManageIntegrations->value,   false],
            'owner can manage-integrations'              => ['owner',       Ability::ManageIntegrations->value,    true],
            'manager can manage-integrations'            => ['manager',     Ability::ManageIntegrations->value,    true],
            'agent cannot manage-integrations'           => ['agent',       Ability::ManageIntegrations->value,    false],

            'super_admin cannot view-analytics-full'     => ['super_admin', Ability::ViewAnalyticsFull->value,    false],
            'owner can view-analytics-full'              => ['owner',       Ability::ViewAnalyticsFull->value,     true],
            'manager can view-analytics-full'            => ['manager',     Ability::ViewAnalyticsFull->value,     true],
            'agent cannot view-analytics-full'           => ['agent',       Ability::ViewAnalyticsFull->value,     false],

            // ── Agent+ abilities ───────────────────────────────────────────
            // Requires at least Role::Agent; Owner, Manager, Agent all pass.
            // SuperAdmin has no tenant role → fails.
            'super_admin cannot manage-conversations'    => ['super_admin', Ability::ManageConversations->value,  false],
            'owner can manage-conversations'             => ['owner',       Ability::ManageConversations->value,   true],
            'manager can manage-conversations'           => ['manager',     Ability::ManageConversations->value,   true],
            'agent can manage-conversations'             => ['agent',       Ability::ManageConversations->value,   true],

            'super_admin cannot manage-leads'            => ['super_admin', Ability::ManageLeads->value,          false],
            'owner can manage-leads'                     => ['owner',       Ability::ManageLeads->value,           true],
            'manager can manage-leads'                   => ['manager',     Ability::ManageLeads->value,           true],
            'agent can manage-leads'                     => ['agent',       Ability::ManageLeads->value,           true],

            'super_admin cannot view-dashboard'          => ['super_admin', Ability::ViewDashboard->value,        false],
            'owner can view-dashboard'                   => ['owner',       Ability::ViewDashboard->value,         true],
            'manager can view-dashboard'                 => ['manager',     Ability::ViewDashboard->value,         true],
            'agent can view-dashboard'                   => ['agent',       Ability::ViewDashboard->value,         true],
        ];
    }
}
