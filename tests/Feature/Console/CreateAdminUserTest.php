<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_platform_admin_with_super_admin_role(): void
    {
        $this->artisan('admin:create')
            ->expectsQuestion('Name', 'Sam Admin')
            ->expectsQuestion('Email', 'sam@abit.bt')
            ->expectsQuestion('Password', 'supersecret')
            ->expectsOutputToContain('Platform Admin created: sam@abit.bt')
            ->assertExitCode(0);

        $admin = User::where('email', 'sam@abit.bt')->firstOrFail();

        $this->assertNull($admin->tenant_id);
        $this->assertTrue(Hash::check('supersecret', $admin->password));

        $role = UserRole::where('user_id', $admin->id)->firstOrFail();
        $this->assertSame(Role::SuperAdmin, $role->role);
        $this->assertNull($role->tenant_id);
    }
}
