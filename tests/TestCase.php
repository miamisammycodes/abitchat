<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Create a tenant with a user for testing.
     * The user is assigned the Owner role so Gate checks pass for owner-level mutations.
     */
    protected function createTenantWithUser(): User
    {
        $this->tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $this->tenant->id,
        ]);

        UserRole::create([
            'user_id' => $this->user->id,
            'role' => Role::Owner,
            'tenant_id' => $this->tenant->id,
        ]);

        return $this->user;
    }

    /**
     * Create and authenticate a tenant user
     */
    protected function actingAsTenantUser(): static
    {
        $this->createTenantWithUser();
        $this->actingAs($this->user);

        return $this;
    }

    /**
     * Create a SuperAdmin user for testing.
     * Uses a unique email so repeated calls within a test never collide.
     */
    protected function createSuperAdmin(): User
    {
        $admin = User::create([
            'name' => 'Test Admin',
            'email' => 'admin_test_'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => null,
        ]);

        UserRole::create([
            'user_id' => $admin->id,
            'role' => Role::SuperAdmin,
            'tenant_id' => null,
        ]);

        return $admin;
    }
}
