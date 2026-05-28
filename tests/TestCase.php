<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\Role;
use App\Models\Plan;
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
     * Create a tenant in the Setup lifecycle state (no plan, no trial clock).
     */
    protected function createSetupTenantWithUser(): User
    {
        $this->tenant = Tenant::create([
            'name' => 'Setup Company',
            'slug' => 'setup-company',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'name' => 'Setup User',
            'email' => 'setup@example.com',
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

    protected function actingAsSetupTenant(): static
    {
        $this->createSetupTenantWithUser();
        $this->actingAs($this->user);

        return $this;
    }

    /**
     * Create the reference Free plan (slug 'free', price 0). Tests use
     * RefreshDatabase with no seeding, so each test that needs it creates it.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function createFreePlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Free',
            'slug' => 'free',
            'description' => null,
            'price' => 0,
            'billing_period' => 'monthly',
            'conversations_limit' => 100,
            'messages_per_conversation' => 50,
            'knowledge_items_limit' => 10,
            'tokens_limit' => 50000,
            'leads_limit' => 50,
            'is_active' => true,
            'is_contact_sales' => false,
            'features' => [],
            'sort_order' => 0,
        ], $overrides));
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
