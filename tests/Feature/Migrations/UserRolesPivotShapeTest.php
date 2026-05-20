<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserRolesPivotShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_roles_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('user_roles'));
    }

    public function test_user_roles_table_has_required_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('user_roles', 'id'));
        $this->assertTrue(Schema::hasColumn('user_roles', 'user_id'));
        $this->assertTrue(Schema::hasColumn('user_roles', 'role'));
        $this->assertTrue(Schema::hasColumn('user_roles', 'tenant_id'));
        $this->assertTrue(Schema::hasColumn('user_roles', 'created_at'));
        $this->assertTrue(Schema::hasColumn('user_roles', 'updated_at'));
    }

    public function test_user_roles_tenant_id_is_nullable(): void
    {
        // Create a user with no tenant first
        $userId = DB::table('users')->insertGetId([
            'name' => 'Super Admin User',
            'email' => 'super-admin-test@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert a super_admin row with null tenant_id
        $id = DB::table('user_roles')->insertGetId([
            'user_id' => $userId,
            'role' => 'super_admin',
            'tenant_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('user_roles')->where('id', $id)->first();
        $this->assertNull($row->tenant_id);
    }

    public function test_user_roles_unique_constraint_prevents_duplicate_role_per_user_and_tenant(): void
    {
        // Use factory to handle all required columns (slug, status, etc.)
        $tenant = Tenant::factory()->create();

        $userId = DB::table('users')->insertGetId([
            'name' => 'Owner User',
            'email' => 'owner-test@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $tenant->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'role' => 'owner',
            'tenant_id' => $tenant->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Second insert of the same (user_id, role, tenant_id) must fail
        $this->expectException(\Exception::class);

        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'role' => 'owner',
            'tenant_id' => $tenant->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
