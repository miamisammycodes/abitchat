<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;

/**
 * D-16: DatabaseSeeder seeds the full 7-user role matrix.
 *
 * Extends Illuminate\Foundation\Testing\TestCase (not project TestCase)
 * to avoid RefreshDatabase's transaction wrapper — migrate:fresh issues DDL
 * which causes implicit commits in MySQL and cannot run inside a transaction.
 * DatabaseMigrations runs migrate:fresh/migrate:rollback directly instead.
 */
class DatabaseSeederMatrixTest extends TestCase
{
    use DatabaseMigrations;

    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function afterRefreshingDatabase(): void
    {
        $this->artisan('db:seed');
    }

    public function test_seeder_creates_exactly_seven_users(): void
    {
        $this->assertSame(7, User::count());
    }

    public function test_admin_user_has_no_tenant(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertNull($admin->tenant_id);
    }

    public function test_admin_user_has_super_admin_role(): void
    {
        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $role = UserRole::where('user_id', $admin->id)->firstOrFail();
        $this->assertSame(Role::SuperAdmin, $role->role);
        $this->assertNull($role->tenant_id);
    }

    public function test_test_user_has_two_roles(): void
    {
        $user = User::where('email', 'test@example.com')->firstOrFail();
        $this->assertSame(2, UserRole::where('user_id', $user->id)->count());
    }

    public function test_test_user_has_owner_role_for_test_company(): void
    {
        $user = User::where('email', 'test@example.com')->firstOrFail();
        $tenant = Tenant::where('slug', 'test-company')->firstOrFail();
        $role = UserRole::where('user_id', $user->id)
            ->where('role', Role::Owner)
            ->first();
        $this->assertNotNull($role);
        $this->assertSame($tenant->id, $role->tenant_id);
    }

    public function test_test_user_also_has_super_admin_role(): void
    {
        $user = User::where('email', 'test@example.com')->firstOrFail();
        $role = UserRole::where('user_id', $user->id)
            ->where('role', Role::SuperAdmin)
            ->first();
        $this->assertNotNull($role);
        $this->assertNull($role->tenant_id);
    }

    public function test_manager_user_belongs_to_test_company(): void
    {
        $user = User::where('email', 'manager@example.com')->firstOrFail();
        $tenant = Tenant::where('slug', 'test-company')->firstOrFail();
        $this->assertSame($tenant->id, $user->tenant_id);
        $role = UserRole::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(Role::Manager, $role->role);
        $this->assertSame($tenant->id, $role->tenant_id);
    }

    public function test_agent_user_belongs_to_test_company(): void
    {
        $user = User::where('email', 'agent@example.com')->firstOrFail();
        $tenant = Tenant::where('slug', 'test-company')->firstOrFail();
        $this->assertSame($tenant->id, $user->tenant_id);
        $role = UserRole::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(Role::Agent, $role->role);
        $this->assertSame($tenant->id, $role->tenant_id);
    }

    public function test_demo_co_tenant_exists(): void
    {
        $tenant = Tenant::where('slug', 'demo-co')->first();
        $this->assertNotNull($tenant);
    }

    public function test_owner_at_demo_has_owner_role(): void
    {
        $user = User::where('email', 'owner@demo.example')->firstOrFail();
        $tenant = Tenant::where('slug', 'demo-co')->firstOrFail();
        $this->assertSame($tenant->id, $user->tenant_id);
        $role = UserRole::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(Role::Owner, $role->role);
        $this->assertSame($tenant->id, $role->tenant_id);
    }

    public function test_manager_at_demo_has_manager_role(): void
    {
        $user = User::where('email', 'manager@demo.example')->firstOrFail();
        $tenant = Tenant::where('slug', 'demo-co')->firstOrFail();
        $this->assertSame($tenant->id, $user->tenant_id);
        $role = UserRole::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(Role::Manager, $role->role);
        $this->assertSame($tenant->id, $role->tenant_id);
    }

    public function test_agent_at_demo_has_agent_role(): void
    {
        $user = User::where('email', 'agent@demo.example')->firstOrFail();
        $tenant = Tenant::where('slug', 'demo-co')->firstOrFail();
        $this->assertSame($tenant->id, $user->tenant_id);
        $role = UserRole::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(Role::Agent, $role->role);
        $this->assertSame($tenant->id, $role->tenant_id);
    }

    /** Pitfall 4: every UserRole row must have correct tenant_id (WithoutModelEvents skips the creating hook) */
    public function test_all_tenant_user_roles_have_non_null_tenant_id(): void
    {
        $tenantRoles = UserRole::whereIn('role', [
            Role::Owner->value,
            Role::Manager->value,
            Role::Agent->value,
        ])->get();

        $this->assertNotEmpty($tenantRoles);

        foreach ($tenantRoles as $userRole) {
            $this->assertNotNull(
                $userRole->tenant_id,
                "UserRole id={$userRole->id} role={$userRole->role->value} has null tenant_id"
            );
        }
    }

    /** SuperAdmin roles must have null tenant_id */
    public function test_all_super_admin_roles_have_null_tenant_id(): void
    {
        $superAdminRoles = UserRole::where('role', Role::SuperAdmin)->get();

        $this->assertNotEmpty($superAdminRoles);

        foreach ($superAdminRoles as $userRole) {
            $this->assertNull(
                $userRole->tenant_id,
                "UserRole id={$userRole->id} SuperAdmin must have null tenant_id"
            );
        }
    }
}
