<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\SeedsRoleMatrix;
use Tests\TestCase;

/**
 * Tests that the admin guard is removed and a single web guard is active.
 * Filled by Plan 03 (D-05: drop admin guard, single web guard).
 */
class SingleGuardTest extends TestCase
{
    use SeedsRoleMatrix;

    public function test_only_web_guard_exists_in_config(): void
    {
        $guards = array_keys(config('auth.guards'));
        $this->assertSame(['web'], $guards, 'Only the web guard should exist; admin guard must be removed.');
    }

    public function test_only_users_provider_exists_in_config(): void
    {
        $providers = array_keys(config('auth.providers'));
        $this->assertSame(['users'], $providers, 'Only the users provider should exist; admin_users must be removed.');
    }

    public function test_admin_authenticate_middleware_file_does_not_exist(): void
    {
        $this->assertFalse(
            file_exists(app_path('Http/Middleware/AdminAuthenticate.php')),
            'AdminAuthenticate.php must be deleted'
        );
    }

    public function test_require_super_admin_alias_blocks_unauthenticated_request(): void
    {
        Route::get('/__test_super_admin_unauth__', fn () => response('ok'))
            ->middleware(['auth', 'require.super_admin']);

        $response = $this->get('/__test_super_admin_unauth__');

        $response->assertRedirect('/login');
    }

    public function test_require_super_admin_alias_allows_super_admin(): void
    {
        Route::get('/__test_super_admin_allow__', fn () => response('ok'))
            ->middleware(['auth', 'require.super_admin']);

        $this->actingAsSuperAdmin();

        $response = $this->get('/__test_super_admin_allow__');

        $response->assertOk();
    }

    public function test_require_super_admin_alias_rejects_tenant_user(): void
    {
        Route::get('/__test_super_admin_reject__', fn () => response('ok'))
            ->middleware(['auth', 'require.super_admin']);

        $tenant = Tenant::create([
            'name' => 'Test Co',
            'slug' => 'test-co',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $this->actingAsOwner($tenant);

        $response = $this->get('/__test_super_admin_reject__');

        $response->assertForbidden();
    }
}
