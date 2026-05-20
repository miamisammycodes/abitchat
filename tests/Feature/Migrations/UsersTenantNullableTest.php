<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UsersTenantNullableTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_tenant_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'tenant_id'));
    }

    public function test_users_tenant_id_is_nullable(): void
    {
        // Insert a user with tenant_id = null via raw DB to bypass model defaults
        $id = DB::table('users')->insertGetId([
            'name' => 'Null Tenant User',
            'email' => 'null-tenant@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('users')->where('id', $id)->first();
        $this->assertNull($row->tenant_id);
    }

    public function test_users_role_column_does_not_exist(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'role'));
    }
}
