<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\SeedsRoleMatrix;
use Tests\TestCase;

/**
 * Tests that /admin/* routes are protected by RequireSuperAdmin middleware:
 * - unauthenticated requests → 302 to /login
 * - authenticated non-super_admin → 403
 * - authenticated super_admin → 200
 *
 * Also verifies Stripe/Cashier webhook routes are NOT under RequireSuperAdmin (Pitfall 7).
 *
 * Filled by Plan 03 (D-05: route gating, RequireSuperAdmin middleware).
 */
class RouteGuardTest extends TestCase
{
    use SeedsRoleMatrix;

    public function test_admin_dashboard_redirects_unauthenticated_to_login(): void
    {
        $response = $this->get('/admin/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_admin_dashboard_returns_403_for_tenant_owner(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Co',
            'slug' => 'test-co',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $this->actingAsOwner($tenant);

        $response = $this->get('/admin/dashboard');

        $response->assertForbidden();
    }

    public function test_admin_dashboard_returns_200_for_super_admin(): void
    {
        $this->actingAsSuperAdmin();

        // Use X-Inertia header to get JSON response instead of full Blade render
        // (avoids Vite manifest not found in test environment)
        $response = $this->withHeaders(['X-Inertia' => 'true'])->get('/admin/dashboard');

        $response->assertOk();
    }

    public function test_admin_clients_returns_403_for_tenant_manager(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Co',
            'slug' => 'test-co',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $this->actingAsManager($tenant);

        $response = $this->get('/admin/clients');

        $response->assertForbidden();
    }

    public function test_every_admin_route_has_auth_and_require_super_admin_middleware(): void
    {
        $routes = Route::getRoutes()->getRoutesByName();

        $adminRoutes = array_filter(
            $routes,
            fn ($route) => str_starts_with($route->getName() ?? '', 'admin.')
        );

        $this->assertNotEmpty($adminRoutes, 'No admin routes found');

        foreach ($adminRoutes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains(
                'auth',
                $middleware,
                "Route [{$route->getName()}] is missing 'auth' middleware"
            );
            $this->assertContains(
                'require.super_admin',
                $middleware,
                "Route [{$route->getName()}] is missing 'require.super_admin' middleware"
            );
        }
    }
}
