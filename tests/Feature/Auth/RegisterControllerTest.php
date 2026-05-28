<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\Role;
use App\Enums\TenantLifecycle;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests that RegisterController inserts a user_roles row (role=owner) inside the
 * same DB transaction as User::create + Tenant::create.
 *
 * Covers D-18: RegisterController writes user_roles row on signup.
 */
class RegisterControllerTest extends TestCase
{
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Test Corp',
            'name' => 'Alice Smith',
            'email' => 'alice@testcorp.example',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'website_url' => null,
        ], $overrides);
    }

    /**
     * Happy path: POST /register creates Tenant + User + UserRole(owner) in one shot.
     */
    public function test_registration_creates_tenant_user_and_owner_role(): void
    {
        $response = $this->post(route('register.store'), $this->validPayload());

        $response->assertRedirect(route('dashboard'));

        // Tenant exists
        $tenant = Tenant::query()->where('name', 'Test Corp')->firstOrFail();

        // User exists and belongs to tenant
        $user = User::query()->where('email', 'alice@testcorp.example')->firstOrFail();
        $this->assertSame($tenant->id, $user->tenant_id);

        // UserRole row with Role::Owner exists
        $roleRow = UserRole::query()
            ->where('user_id', $user->id)
            ->where('tenant_id', $tenant->id)
            ->first();

        $this->assertNotNull($roleRow, 'A user_roles row must be created for the new owner');
        $this->assertSame(Role::Owner, $roleRow->role);

        // The authenticated user must be the newly created user
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Transactional rollback: if UserRole::create fails the entire transaction rolls back
     * so no orphan Tenant or User row is left.
     */
    public function test_registration_rolls_back_on_user_role_failure(): void
    {
        // Force the user_roles table insert to fail by dropping a NOT NULL column
        // constraint via a raw DB statement that will be rolled back with the transaction.
        // Easier: intercept with a callback that throws before we touch the table.
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into') && str_contains($query->sql, 'user_roles')) {
                throw new \RuntimeException('Simulated user_roles insert failure');
            }
        });

        $countBefore = [
            'tenants' => Tenant::count(),
            'users' => User::count(),
            'user_roles' => UserRole::count(),
        ];

        try {
            $this->post(route('register.store'), $this->validPayload());
        } catch (\RuntimeException) {
            // Expected — the simulated failure propagated out of the transaction.
        }

        // Nothing must have persisted — all three rows should be absent
        $this->assertSame($countBefore['tenants'], Tenant::count(), 'Tenant row must be rolled back');
        $this->assertSame($countBefore['users'], User::count(), 'User row must be rolled back');
        $this->assertSame($countBefore['user_roles'], UserRole::count(), 'UserRole row must be rolled back');
    }

    /**
     * Mass-assignment guard: a request body containing role=super_admin must not
     * elevate the registered user beyond Owner.
     */
    public function test_registration_lands_in_setup_state(): void
    {
        $this->post(route('register.store'), $this->validPayload())
            ->assertRedirect(route('dashboard'));

        $tenant = Tenant::query()->where('name', 'Test Corp')->firstOrFail();

        $this->assertNull($tenant->plan_id);
        $this->assertNull($tenant->plan_expires_at);
        $this->assertNull($tenant->trial_ends_at);
        $this->assertNull($tenant->trial_activated_at);
        $this->assertSame(TenantLifecycle::Setup, $tenant->lifecycleState());
    }

    public function test_registration_ignores_role_field_in_request_body(): void
    {
        $response = $this->post(route('register.store'), $this->validPayload([
            'role' => 'super_admin',
        ]));

        $response->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'alice@testcorp.example')->firstOrFail();

        $roleRow = UserRole::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(Role::Owner, $roleRow->role, 'Role must be forced to owner regardless of request payload');
        $this->assertNotSame(Role::SuperAdmin, $roleRow->role);
    }
}
