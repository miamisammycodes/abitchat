<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Tests\TestCase;

/**
 * Tests the /login/choose dual-role chooser flow:
 * - dual-role user can choose admin context → redirected to /admin/dashboard
 * - dual-role user can choose tenant context → redirected to /dashboard
 * - single-role user is not shown the chooser
 *
 * Filled by Plan 03 (D-08: dual-role chooser branch).
 */
class ChooseRoleTest extends TestCase
{
    private function makeDualRoleUser(): User
    {
        $tenant = Tenant::create([
            'name' => 'Test Co',
            'slug' => 'test-co-choose-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $user = User::create([
            'name' => 'Dual Role',
            'email' => uniqid('dual_').'@test.com',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
        ]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::SuperAdmin, 'tenant_id' => null]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $tenant->id]);

        return $user;
    }

    private function makeSuperAdminUser(): User
    {
        $user = User::create([
            'name' => 'Super Admin',
            'email' => uniqid('super_').'@test.com',
            'password' => bcrypt('password'),
            'tenant_id' => null,
        ]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::SuperAdmin, 'tenant_id' => null]);

        return $user;
    }

    private function makeOwnerUser(): User
    {
        $tenant = Tenant::create([
            'name' => 'Owner Co',
            'slug' => 'owner-co-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $user = User::create([
            'name' => 'Owner Only',
            'email' => uniqid('owner_').'@test.com',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
        ]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $tenant->id]);

        return $user;
    }

    public function test_dual_role_user_sees_choose_page(): void
    {
        $user = $this->makeDualRoleUser();
        $this->actingAs($user);

        // Use X-Inertia header to get JSON response (avoids Vite manifest in test env).
        // assertInertia() works with Blade view data; for JSON we check the JSON directly.
        $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
            ->get('/login/choose');

        $response->assertOk();
        $response->assertJson(['component' => 'Auth/ChooseRole']);
        $this->assertCount(2, $response->json('props.availableContexts'));
    }

    public function test_single_role_super_admin_redirected_away_from_chooser(): void
    {
        $user = $this->makeSuperAdminUser();
        $this->actingAs($user);

        $response = $this->get('/login/choose');

        $response->assertRedirect(route('admin.dashboard'));
    }

    public function test_single_role_owner_redirected_away_from_chooser(): void
    {
        $user = $this->makeOwnerUser();
        $this->actingAs($user);

        $response = $this->get('/login/choose');

        $response->assertRedirect(route('dashboard'));
    }

    public function test_dual_role_user_can_choose_admin_context(): void
    {
        $user = $this->makeDualRoleUser();
        $this->actingAs($user);

        $response = $this->post('/login/choose', ['context' => 'admin']);

        $response->assertRedirect(route('admin.dashboard'));
    }

    public function test_dual_role_user_can_choose_tenant_context(): void
    {
        $user = $this->makeDualRoleUser();
        $this->actingAs($user);

        $response = $this->post('/login/choose', ['context' => 'tenant']);

        $response->assertRedirect(route('dashboard'));
    }

    public function test_single_role_owner_cannot_choose_admin_context(): void
    {
        $user = $this->makeOwnerUser();
        $this->actingAs($user);

        $response = $this->post('/login/choose', ['context' => 'admin']);

        $response->assertForbidden();
    }

    public function test_single_role_super_admin_cannot_choose_tenant_context(): void
    {
        $user = $this->makeSuperAdminUser();
        $this->actingAs($user);

        $response = $this->post('/login/choose', ['context' => 'tenant']);

        $response->assertForbidden();
    }

    public function test_missing_context_returns_validation_error(): void
    {
        $user = $this->makeDualRoleUser();
        $this->actingAs($user);

        // Use JSON request to get 422 instead of redirect-with-errors
        $response = $this->postJson('/login/choose', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['context']);
    }

    public function test_invalid_context_returns_validation_error(): void
    {
        $user = $this->makeDualRoleUser();
        $this->actingAs($user);

        // Use JSON request to get 422 instead of redirect-with-errors
        $response = $this->postJson('/login/choose', ['context' => 'superuser']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['context']);
    }
}
