<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\RolePermissions;
use App\Enums\Ability;
use App\Enums\Role;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Phase 16.1-01 subset: tests that do NOT require User::hasRole (Plan 02).
 * The full 52-cell matrix test is in tests/Feature/Auth/RoleMatrixTest.php (Plan 07).
 */
class RolePermissionsTest extends TestCase
{
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
}
