<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Tenant;

/**
 * Test helper trait exposing fluent actingAs* methods for each role.
 *
 * Method bodies throw RuntimeException until Plan 02 ships UserRole + User::hasRole.
 * Plan 02 Task 4 replaces the throws with real User + UserRole creation logic.
 */
trait SeedsRoleMatrix
{
    /**
     * Authenticate as a SuperAdmin user (platform-level role, no tenant required).
     */
    public function actingAsSuperAdmin(): static
    {
        throw new \RuntimeException(
            'SeedsRoleMatrix::actingAsSuperAdmin awaiting Plan 02 (UserRole model + User::hasRole).'
        );
    }

    /**
     * Authenticate as an Owner within the given tenant.
     */
    public function actingAsOwner(Tenant $tenant): static
    {
        throw new \RuntimeException(
            'SeedsRoleMatrix::actingAsOwner awaiting Plan 02 (UserRole model + User::hasRole).'
        );
    }

    /**
     * Authenticate as a Manager within the given tenant.
     */
    public function actingAsManager(Tenant $tenant): static
    {
        throw new \RuntimeException(
            'SeedsRoleMatrix::actingAsManager awaiting Plan 02 (UserRole model + User::hasRole).'
        );
    }

    /**
     * Authenticate as an Agent within the given tenant.
     */
    public function actingAsAgent(Tenant $tenant): static
    {
        throw new \RuntimeException(
            'SeedsRoleMatrix::actingAsAgent awaiting Plan 02 (UserRole model + User::hasRole).'
        );
    }
}
