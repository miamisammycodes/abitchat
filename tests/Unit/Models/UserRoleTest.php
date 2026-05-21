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
 * Tests for App\Models\UserRole pivot model.
 * Plan 02 (D-03: multi-role storage, UserRole model + migrations).
 */
class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_role_can_be_created_with_role_enum_cast(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::create([
            'name' => 'Test User',
            'email' => uniqid('user').'@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
        ]);

        $userRole = UserRole::create([
            'user_id' => $user->id,
            'role' => Role::Owner,
            'tenant_id' => $tenant->id,
        ]);

        $this->assertInstanceOf(Role::class, $userRole->role);
        $this->assertSame(Role::Owner, $userRole->role);
    }

    public function test_user_relation_returns_the_correct_user(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::create([
            'name' => 'Test User',
            'email' => uniqid('user').'@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
        ]);

        $userRole = UserRole::create([
            'user_id' => $user->id,
            'role' => Role::Owner,
            'tenant_id' => $tenant->id,
        ]);

        $foundUser = $userRole->user()->first();
        $this->assertNotNull($foundUser);
        $this->assertSame($user->id, $foundUser->id);
    }

    public function test_for_tenant_scope_filters_by_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user = User::create([
            'name' => 'Test User',
            'email' => uniqid('user').'@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $tenantA->id,
        ]);

        UserRole::create([
            'user_id' => $user->id,
            'role' => Role::Owner,
            'tenant_id' => $tenantA->id,
        ]);

        UserRole::create([
            'user_id' => $user->id,
            'role' => Role::Manager,
            'tenant_id' => $tenantB->id,
        ]);

        $rolesForTenantA = UserRole::query()->forTenant($tenantA)->get();
        $this->assertCount(1, $rolesForTenantA);
        $this->assertSame(Role::Owner, $rolesForTenantA->first()->role);

        $rolesForTenantB = UserRole::query()->forTenant($tenantB)->get();
        $this->assertCount(1, $rolesForTenantB);
        $this->assertSame(Role::Manager, $rolesForTenantB->first()->role);
    }

    public function test_super_admin_role_can_have_null_tenant_id(): void
    {
        $user = User::create([
            'name' => 'Super Admin',
            'email' => uniqid('admin').'@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => null,
        ]);

        $userRole = UserRole::create([
            'user_id' => $user->id,
            'role' => Role::SuperAdmin,
            'tenant_id' => null,
        ]);

        $this->assertSame(Role::SuperAdmin, $userRole->role);
        $this->assertNull($userRole->tenant_id);
    }

    public function test_duplicate_super_admin_row_for_same_user_is_rejected(): void
    {
        // D-09 invariant: schema-level UNIQUE(user_id, role, tenant_id) does NOT enforce
        // uniqueness when tenant_id IS NULL on MySQL. The app-layer guard in UserRole::booted()
        // rejects a second super_admin row for the same user.
        $user = User::create([
            'name' => 'Super Admin',
            'email' => uniqid('admin').'@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => null,
        ]);

        UserRole::create([
            'user_id' => $user->id,
            'role' => Role::SuperAdmin,
            'tenant_id' => null,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already has a super_admin role');

        UserRole::create([
            'user_id' => $user->id,
            'role' => Role::SuperAdmin,
            'tenant_id' => null,
        ]);
    }

    public function test_super_admin_row_with_non_null_tenant_id_is_rejected(): void
    {
        // D-09 invariant: super_admin must always have tenant_id = NULL.
        $tenant = Tenant::create([
            'name' => 'Test Co',
            'slug' => 'test-co-'.uniqid(),
            'status' => 'active',
        ]);

        $user = User::create([
            'name' => 'Super Admin',
            'email' => uniqid('admin').'@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => null,
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SuperAdmin role must have tenant_id = NULL');

        UserRole::create([
            'user_id' => $user->id,
            'role' => Role::SuperAdmin,
            'tenant_id' => $tenant->id,
        ]);
    }
}
