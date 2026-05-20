<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\RolePermissions;
use App\Enums\Ability;
use App\Enums\Role;
use App\Models\Tenant;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\Concerns\SeedsRoleMatrix;
use Tests\TestCase;

/**
 * Phase 16.1-01 structural tests + Plan 07 52-cell RolePermissions::can() matrix.
 * The 8 structural tests (reflection-based) validate the MIN_ROLE constant shape.
 * The 52-cell matrix validates RolePermissions::can() directly (below Gate layer).
 */
class RolePermissionsTest extends TestCase
{
    use SeedsRoleMatrix;
    public function test_all_abilities_returns_thirteen_items(): void
    {
        $this->assertCount(13, RolePermissions::allAbilities());
    }

    public function test_all_abilities_returns_ability_instances(): void
    {
        foreach (RolePermissions::allAbilities() as $ability) {
            $this->assertInstanceOf(Ability::class, $ability);
        }
    }

    public function test_min_role_map_has_thirteen_entries(): void
    {
        $reflection = new ReflectionClass(RolePermissions::class);
        $constants = $reflection->getConstants();
        $minRole = $constants['MIN_ROLE'] ?? null;

        $this->assertIsArray($minRole);
        $this->assertCount(13, $minRole);
    }

    public function test_min_role_map_contains_all_ability_values_as_keys(): void
    {
        $reflection = new ReflectionClass(RolePermissions::class);
        $constants = $reflection->getConstants();
        $minRole = $constants['MIN_ROLE'];

        foreach (Ability::cases() as $ability) {
            $this->assertArrayHasKey(
                $ability->value,
                $minRole,
                "MIN_ROLE is missing key for Ability::{$ability->name} ('{$ability->value}')"
            );
        }
    }

    public function test_min_role_map_values_are_role_instances(): void
    {
        $reflection = new ReflectionClass(RolePermissions::class);
        $constants = $reflection->getConstants();
        $minRole = $constants['MIN_ROLE'];

        foreach ($minRole as $abilityValue => $role) {
            $this->assertInstanceOf(
                Role::class,
                $role,
                "MIN_ROLE['{$abilityValue}'] is not a Role instance"
            );
        }
    }

    public function test_super_admin_abilities_map_to_super_admin_role(): void
    {
        $reflection = new ReflectionClass(RolePermissions::class);
        $constants = $reflection->getConstants();
        $minRole = $constants['MIN_ROLE'];

        $superAdminAbilities = [
            Ability::ViewAdminDashboard->value,
            Ability::ManageTenantAsAdmin->value,
            Ability::ManagePlatformSettings->value,
        ];

        foreach ($superAdminAbilities as $abilityValue) {
            $this->assertSame(
                Role::SuperAdmin,
                $minRole[$abilityValue],
                "Ability '{$abilityValue}' should map to Role::SuperAdmin"
            );
        }
    }

    public function test_owner_only_abilities_map_to_owner_role(): void
    {
        $reflection = new ReflectionClass(RolePermissions::class);
        $constants = $reflection->getConstants();
        $minRole = $constants['MIN_ROLE'];

        $ownerAbilities = [
            Ability::ManageBilling->value,
            Ability::ManageTeam->value,
            Ability::ManageTenantSettings->value,
            Ability::DeleteTenant->value,
        ];

        foreach ($ownerAbilities as $abilityValue) {
            $this->assertSame(
                Role::Owner,
                $minRole[$abilityValue],
                "Ability '{$abilityValue}' should map to Role::Owner"
            );
        }
    }

    public function test_manager_plus_abilities_map_to_manager_role(): void
    {
        $reflection = new ReflectionClass(RolePermissions::class);
        $constants = $reflection->getConstants();
        $minRole = $constants['MIN_ROLE'];

        $managerAbilities = [
            Ability::ManageKnowledgeBase->value,
            Ability::ManageIntegrations->value,
            Ability::ViewAnalyticsFull->value,
        ];

        foreach ($managerAbilities as $abilityValue) {
            $this->assertSame(
                Role::Manager,
                $minRole[$abilityValue],
                "Ability '{$abilityValue}' should map to Role::Manager"
            );
        }
    }

    public function test_agent_plus_abilities_map_to_agent_role(): void
    {
        $reflection = new ReflectionClass(RolePermissions::class);
        $constants = $reflection->getConstants();
        $minRole = $constants['MIN_ROLE'];

        $agentAbilities = [
            Ability::ManageConversations->value,
            Ability::ManageLeads->value,
            Ability::ViewDashboard->value,
        ];

        foreach ($agentAbilities as $abilityValue) {
            $this->assertSame(
                Role::Agent,
                $minRole[$abilityValue],
                "Ability '{$abilityValue}' should map to Role::Agent"
            );
        }
    }

    #[DataProvider('roleAbilityMatrix')]
    public function test_role_permissions_can_matrix(string $roleKey, string $abilityValue, bool $expected): void
    {
        $tenant = Tenant::create([
            'name' => 'RP Matrix Tenant',
            'slug' => 'rp-matrix-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $user = match ($roleKey) {
            'super_admin' => $this->actingAsSuperAdmin()->superAdminUser,
            'owner'       => $this->actingAsOwner($tenant)->ownerUser,
            'manager'     => $this->actingAsManager($tenant)->managerUser,
            'agent'       => $this->actingAsAgent($tenant)->agentUser,
        };

        $ability = Ability::from($abilityValue);
        $result = RolePermissions::can($user, $ability, $user->tenant);

        $this->assertSame(
            $expected,
            $result,
            sprintf(
                'Expected RolePermissions::can() for role "%s" on ability "%s" to return %s',
                $roleKey,
                $abilityValue,
                $expected ? 'true' : 'false'
            )
        );
    }

    /** @return array<string, array{string, string, bool}> */
    public static function roleAbilityMatrix(): array
    {
        return [
            // ── SuperAdmin-only abilities ──────────────────────────────────
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
