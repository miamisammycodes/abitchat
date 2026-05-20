<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;

/**
 * Test helper trait exposing fluent actingAs* methods for each role.
 *
 * Each method creates a User + UserRole pair and authenticates via actingAs().
 * The created user is stored in a protected property for chaining.
 */
trait SeedsRoleMatrix
{
    protected ?User $superAdminUser = null;

    protected ?User $ownerUser = null;

    protected ?User $managerUser = null;

    protected ?User $agentUser = null;

    /**
     * Authenticate as a SuperAdmin user (platform-level role, no tenant required).
     */
    public function actingAsSuperAdmin(): static
    {
        $this->superAdminUser = User::create([
            'name' => 'Super Admin',
            'email' => uniqid('super_admin_').'@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => null,
        ]);

        UserRole::create([
            'user_id' => $this->superAdminUser->id,
            'role' => Role::SuperAdmin,
            'tenant_id' => null,
        ]);

        $this->actingAs($this->superAdminUser);

        return $this;
    }

    /**
     * Authenticate as an Owner within the given tenant.
     */
    public function actingAsOwner(Tenant $tenant): static
    {
        $this->ownerUser = User::create([
            'name' => 'Owner',
            'email' => uniqid('owner_').'@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
        ]);

        UserRole::create([
            'user_id' => $this->ownerUser->id,
            'role' => Role::Owner,
            'tenant_id' => $tenant->id,
        ]);

        $this->actingAs($this->ownerUser);

        return $this;
    }

    /**
     * Authenticate as a Manager within the given tenant.
     */
    public function actingAsManager(Tenant $tenant): static
    {
        $this->managerUser = User::create([
            'name' => 'Manager',
            'email' => uniqid('manager_').'@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
        ]);

        UserRole::create([
            'user_id' => $this->managerUser->id,
            'role' => Role::Manager,
            'tenant_id' => $tenant->id,
        ]);

        $this->actingAs($this->managerUser);

        return $this;
    }

    /**
     * Authenticate as an Agent within the given tenant.
     */
    public function actingAsAgent(Tenant $tenant): static
    {
        $this->agentUser = User::create([
            'name' => 'Agent',
            'email' => uniqid('agent_').'@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
        ]);

        UserRole::create([
            'user_id' => $this->agentUser->id,
            'role' => Role::Agent,
            'tenant_id' => $tenant->id,
        ]);

        $this->actingAs($this->agentUser);

        return $this;
    }
}
