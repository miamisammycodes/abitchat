<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for User::hasRole(Role) and User::hasRoleAtLeast(Role, Tenant) resolution logic.
 * Plan 02 (D-04: backed enum + hasRole/hasRoleAtLeast methods on User).
 */
class UserRoleResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(?int $tenantId = null): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => uniqid('user').'@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $tenantId,
        ]);
    }

    private function giveRole(User $user, Role $role, ?int $tenantId = null): void
    {
        UserRole::create([
            'user_id' => $user->id,
            'role' => $role,
            'tenant_id' => $tenantId,
        ]);
    }

    public function test_user_with_super_admin_pivot_has_super_admin_role(): void
    {
        $user = $this->createUser();
        $this->giveRole($user, Role::SuperAdmin, null);

        $user->unsetRelation('roles');
        $this->assertTrue($user->hasRole(Role::SuperAdmin));
    }

    public function test_super_admin_role_check_ignores_tenant_argument(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->createUser();
        $this->giveRole($user, Role::SuperAdmin, null);

        $user->unsetRelation('roles');
        // Even when a tenant is passed, super_admin check should still work
        $this->assertTrue($user->hasRole(Role::SuperAdmin, $tenant));
    }

    public function test_user_with_owner_pivot_in_tenant_a_has_owner_role_for_that_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user = $this->createUser($tenantA->id);
        $this->giveRole($user, Role::Owner, $tenantA->id);

        $user->unsetRelation('roles');
        $this->assertTrue($user->hasRole(Role::Owner, $tenantA));
        $this->assertFalse($user->hasRole(Role::Owner, $tenantB));
    }

    public function test_has_role_at_least_returns_true_for_higher_rank(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->createUser($tenant->id);
        $this->giveRole($user, Role::Owner, $tenant->id);

        $user->unsetRelation('roles');
        // Owner (rank 3) >= Manager (rank 2) => true
        $this->assertTrue($user->hasRoleAtLeast(Role::Manager, $tenant));
        // Owner (rank 3) >= Agent (rank 1) => true
        $this->assertTrue($user->hasRoleAtLeast(Role::Agent, $tenant));
    }

    public function test_has_role_at_least_returns_true_for_equal_rank(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->createUser($tenant->id);
        $this->giveRole($user, Role::Manager, $tenant->id);

        $user->unsetRelation('roles');
        // Manager (rank 2) >= Manager (rank 2) => true
        $this->assertTrue($user->hasRoleAtLeast(Role::Manager, $tenant));
    }

    public function test_has_role_at_least_returns_false_for_lower_rank(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->createUser($tenant->id);
        $this->giveRole($user, Role::Manager, $tenant->id);

        $user->unsetRelation('roles');
        // Manager (rank 2) >= Owner (rank 3) => false
        $this->assertFalse($user->hasRoleAtLeast(Role::Owner, $tenant));
    }

    public function test_super_admin_is_orthogonal_to_tenant_hierarchy(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->createUser();
        $this->giveRole($user, Role::SuperAdmin, null);

        $user->unsetRelation('roles');
        // SuperAdmin has rank 0 (platform-level, not in hierarchy)
        // hasRoleAtLeast only considers non-platform-level roles
        $this->assertFalse($user->hasRoleAtLeast(Role::Agent, $tenant));
    }

    public function test_has_role_at_least_only_considers_roles_for_the_given_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user = $this->createUser($tenantA->id);
        $this->giveRole($user, Role::Owner, $tenantA->id);

        $user->unsetRelation('roles');
        // User is Owner in tenantA but not tenantB
        $this->assertTrue($user->hasRoleAtLeast(Role::Agent, $tenantA));
        $this->assertFalse($user->hasRoleAtLeast(Role::Agent, $tenantB));
    }

    public function test_roles_relation_returns_all_user_roles(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user = $this->createUser($tenantA->id);
        $this->giveRole($user, Role::Owner, $tenantA->id);
        $this->giveRole($user, Role::Manager, $tenantB->id);
        $this->giveRole($user, Role::SuperAdmin, null);

        $user->unsetRelation('roles');
        $roles = $user->roles()->get();
        $this->assertCount(3, $roles);
    }
}
