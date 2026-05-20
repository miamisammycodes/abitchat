<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tests role-aware post-login redirect:
 * - super_admin-only → /admin/dashboard
 * - tenant-only → /dashboard
 * - dual-role → /login/choose (chooser page)
 *
 * Filled by Plan 03 (D-08: single /login page, role-aware redirect).
 */
class LoginRedirectTest extends TestCase
{
    private function createUserWithPassword(array $attributes): User
    {
        return User::create(array_merge($attributes, [
            'password' => Hash::make('password'),
        ]));
    }

    public function test_super_admin_only_redirects_to_admin_dashboard(): void
    {
        $user = $this->createUserWithPassword([
            'name' => 'Admin Only',
            'email' => 'superadmin@test.com',
            'tenant_id' => null,
        ]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::SuperAdmin, 'tenant_id' => null]);

        $sessionBefore = session()->getId();

        $response = $this->post('/login', [
            'email' => 'superadmin@test.com',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('admin.dashboard'));

        // Session ID must change after login (anti-fixation per V3 ASVS)
        $this->assertNotEquals($sessionBefore, session()->getId());
    }

    public function test_tenant_owner_only_redirects_to_dashboard(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Co',
            'slug' => 'test-co',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $user = $this->createUserWithPassword([
            'name' => 'Owner Only',
            'email' => 'owner@test.com',
            'tenant_id' => $tenant->id,
        ]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $tenant->id]);

        $sessionBefore = session()->getId();

        $response = $this->post('/login', [
            'email' => 'owner@test.com',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertNotEquals($sessionBefore, session()->getId());
    }

    public function test_dual_role_user_redirects_to_choose(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Co',
            'slug' => 'test-co',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $user = $this->createUserWithPassword([
            'name' => 'Dual Role',
            'email' => 'dual@test.com',
            'tenant_id' => $tenant->id,
        ]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::SuperAdmin, 'tenant_id' => null]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $tenant->id]);

        $sessionBefore = session()->getId();

        $response = $this->post('/login', [
            'email' => 'dual@test.com',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('login.choose'));
        $this->assertNotEquals($sessionBefore, session()->getId());
    }

    public function test_no_roles_user_redirects_to_home_with_error(): void
    {
        $user = $this->createUserWithPassword([
            'name' => 'No Roles',
            'email' => 'noroles@test.com',
            'tenant_id' => null,
        ]);
        // No UserRole rows created

        $response = $this->post('/login', [
            'email' => 'noroles@test.com',
            'password' => 'password',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas('error', 'No roles assigned. Contact support.');
    }
}
