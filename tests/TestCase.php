<?php

namespace Tests;

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
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
     * Create a tenant with a user for testing
     */
    protected function createTenantWithUser(): User
    {
        $this->tenant = Tenant::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
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
     * Create an admin user
     */
    protected function createAdmin(): Admin
    {
        return Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ]);
    }
}
