<?php

declare(strict_types=1);

namespace App\Auth;

use App\Enums\Ability;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;

final class RolePermissions
{
    /**
     * Single source of truth: ability → minimum role required.
     *
     * SuperAdmin abilities require the SuperAdmin role specifically (orthogonal — not a "max rank").
     * Tenant-scoped abilities require hasRoleAtLeast() within the given tenant.
     *
     * @var array<value-of<Ability>, Role>
     */
    private const MIN_ROLE = [
        // SuperAdmin-only (exact-match; no tenant context required)
        Ability::ViewAdminDashboard->value => Role::SuperAdmin,
        Ability::ManageTenantAsAdmin->value => Role::SuperAdmin,
        Ability::ManagePlatformSettings->value => Role::SuperAdmin,
        // Owner-only (tenant-scoped; exact-match within hierarchy means Owner only passes)
        Ability::ManageBilling->value => Role::Owner,
        Ability::ManageTeam->value => Role::Owner,
        Ability::ManageTenantSettings->value => Role::Owner,
        Ability::DeleteTenant->value => Role::Owner,
        // Manager+ (Owner OR Manager; Agent fails)
        Ability::ManageKnowledgeBase->value => Role::Manager,
        Ability::ManageIntegrations->value => Role::Manager,
        Ability::ViewAnalyticsFull->value => Role::Manager,
        // Agent+ (Owner OR Manager OR Agent)
        Ability::ManageConversations->value => Role::Agent,
        Ability::ManageLeads->value => Role::Agent,
        Ability::ViewDashboard->value => Role::Agent,
    ];

    /**
     * Determine if the given user holds the minimum role required for the ability.
     *
     * For SuperAdmin abilities, checks that the user holds the SuperAdmin role (platform-level;
     * no tenant context required). For tenant abilities, checks that the user holds at least
     * the minimum role within the given tenant.
     *
     * Note: User::hasRole(Role) and User::hasRoleAtLeast(Role, Tenant) are added in Plan 02.
     */
    public static function can(User $user, Ability $ability, ?Tenant $tenant = null): bool
    {
        $minRole = self::MIN_ROLE[$ability->value];

        if ($minRole === Role::SuperAdmin) {
            // @phpstan-ignore-next-line argument.type
            return $user->hasRole(Role::SuperAdmin);
        }

        // @phpstan-ignore-next-line method.notFound
        return $tenant !== null && $user->hasRoleAtLeast($minRole, $tenant);
    }

    /** @return list<Ability> */
    public static function allAbilities(): array
    {
        return Ability::cases();
    }
}
