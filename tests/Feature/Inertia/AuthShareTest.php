<?php

declare(strict_types=1);

namespace Tests\Feature\Inertia;

use App\Enums\Ability;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsRoleMatrix;
use Tests\TestCase;

/**
 * Tests the Inertia shared auth data contract (D-13):
 * - auth.user.can map contains all 13 ability keys (snake_case slugs)
 * - auth.user.roles array contains the user's assigned roles
 * - auth.user.has_super_admin_role bool is correctly set
 * - auth.user.has_tenant_role bool is correctly set
 * - auth.user.primary_role reflects URL context (admin/* vs dashboard/*)
 * - auth.admin key does NOT exist
 *
 * Filled by Plan 06a (D-13: resolved abilities shared via HandleInertiaRequests::share()).
 */
class AuthShareTest extends TestCase
{
    use RefreshDatabase;
    use SeedsRoleMatrix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    /** @return array<string, mixed> */
    private function getSharedAuth(string $url = '/dashboard'): array
    {
        $response = $this->get($url, ['X-Inertia' => 'true']);
        $json = $response->json();

        return $json['props']['auth'] ?? [];
    }

    // -------------------------------------------------------------------------
    // Anonymous request
    // -------------------------------------------------------------------------

    public function test_anonymous_request_has_null_user_and_no_admin_key(): void
    {
        // Use the public home route — /dashboard requires auth and would redirect
        $auth = $this->getSharedAuth('/');

        $this->assertNull($auth['user'], 'Anonymous request should have null auth.user');
        $this->assertArrayNotHasKey('admin', $auth, 'auth.admin key must not exist');
    }

    // -------------------------------------------------------------------------
    // auth.admin must NEVER exist
    // -------------------------------------------------------------------------

    public function test_auth_admin_key_never_exists_for_any_user(): void
    {
        $this->actingAsOwner($this->tenant);

        $auth = $this->getSharedAuth('/dashboard');

        $this->assertArrayNotHasKey('admin', $auth, 'auth.admin key must be removed entirely');
    }

    // -------------------------------------------------------------------------
    // can map: 13 snake_case keys
    // -------------------------------------------------------------------------

    public function test_can_map_contains_exactly_13_keys(): void
    {
        $this->actingAsOwner($this->tenant);

        $auth = $this->getSharedAuth('/dashboard');

        $this->assertArrayHasKey('user', $auth);
        $this->assertArrayHasKey('can', $auth['user']);
        $this->assertCount(
            13,
            $auth['user']['can'],
            'can map must contain exactly 13 keys — one per Ability case',
        );
    }

    public function test_can_map_uses_snake_case_keys(): void
    {
        $this->actingAsOwner($this->tenant);

        $auth = $this->getSharedAuth('/dashboard');
        $canKeys = array_keys($auth['user']['can']);

        // Every key must be snake_case (no hyphens)
        foreach ($canKeys as $key) {
            $this->assertStringNotContainsString(
                '-',
                $key,
                "can key '{$key}' must use snake_case, not kebab-case",
            );
        }

        // Spot-check expected snake_case keys derived from Ability slug values
        $expectedKeys = array_map(
            fn (Ability $a): string => str_replace('-', '_', $a->value),
            Ability::cases(),
        );

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey(
                $key,
                $auth['user']['can'],
                "can map must contain key '{$key}'",
            );
        }
    }

    // -------------------------------------------------------------------------
    // Role-specific ability assertions: Owner
    // -------------------------------------------------------------------------

    public function test_owner_can_manage_billing_but_not_view_admin_dashboard(): void
    {
        $this->actingAsOwner($this->tenant);

        $auth = $this->getSharedAuth('/dashboard');
        $can = $auth['user']['can'];

        $this->assertTrue($can['manage_billing'], 'Owner can manage_billing');
        $this->assertTrue($can['manage_knowledge_base'], 'Owner can manage_knowledge_base');
        $this->assertTrue($can['view_dashboard'], 'Owner can view_dashboard');
        $this->assertFalse($can['view_admin_dashboard'], 'Owner cannot view_admin_dashboard');
        $this->assertFalse($can['manage_platform_settings'], 'Owner cannot manage_platform_settings');
    }

    // -------------------------------------------------------------------------
    // Role-specific ability assertions: Manager
    // -------------------------------------------------------------------------

    public function test_manager_can_manage_knowledge_but_not_billing(): void
    {
        $this->actingAsManager($this->tenant);

        $auth = $this->getSharedAuth('/dashboard');
        $can = $auth['user']['can'];

        $this->assertTrue($can['manage_knowledge_base'], 'Manager can manage_knowledge_base');
        $this->assertTrue($can['view_analytics_full'], 'Manager can view_analytics_full');
        $this->assertTrue($can['view_dashboard'], 'Manager can view_dashboard');
        $this->assertFalse($can['manage_billing'], 'Manager cannot manage_billing');
        $this->assertFalse($can['manage_team'], 'Manager cannot manage_team');
        $this->assertFalse($can['view_admin_dashboard'], 'Manager cannot view_admin_dashboard');
    }

    // -------------------------------------------------------------------------
    // Role-specific ability assertions: Agent
    // -------------------------------------------------------------------------

    public function test_agent_can_manage_conversations_but_not_knowledge_or_billing(): void
    {
        $this->actingAsAgent($this->tenant);

        $auth = $this->getSharedAuth('/dashboard');
        $can = $auth['user']['can'];

        $this->assertTrue($can['manage_conversations'], 'Agent can manage_conversations');
        $this->assertTrue($can['manage_leads'], 'Agent can manage_leads');
        $this->assertTrue($can['view_dashboard'], 'Agent can view_dashboard');
        $this->assertFalse($can['manage_knowledge_base'], 'Agent cannot manage_knowledge_base');
        $this->assertFalse($can['manage_billing'], 'Agent cannot manage_billing');
        $this->assertFalse($can['view_admin_dashboard'], 'Agent cannot view_admin_dashboard');
    }

    // -------------------------------------------------------------------------
    // Role-specific ability assertions: SuperAdmin
    // -------------------------------------------------------------------------

    public function test_super_admin_can_view_admin_dashboard_but_not_tenant_billing(): void
    {
        $this->actingAsSuperAdmin();

        $auth = $this->getSharedAuth('/admin/dashboard');
        $can = $auth['user']['can'];

        $this->assertTrue($can['view_admin_dashboard'], 'SuperAdmin can view_admin_dashboard');
        $this->assertTrue($can['manage_platform_settings'], 'SuperAdmin can manage_platform_settings');
        $this->assertTrue($can['manage_tenant_as_admin'], 'SuperAdmin can manage_tenant_as_admin');
        // SuperAdmin has no tenant context — tenant-scoped abilities return false
        $this->assertFalse($can['manage_billing'], 'SuperAdmin cannot manage_billing (no tenant)');
        $this->assertFalse($can['manage_knowledge_base'], 'SuperAdmin cannot manage_knowledge_base (no tenant)');
    }

    // -------------------------------------------------------------------------
    // has_super_admin_role + has_tenant_role flags
    // -------------------------------------------------------------------------

    public function test_owner_has_tenant_role_but_not_super_admin_role(): void
    {
        $this->actingAsOwner($this->tenant);

        $auth = $this->getSharedAuth('/dashboard');

        $this->assertFalse($auth['user']['has_super_admin_role']);
        $this->assertTrue($auth['user']['has_tenant_role']);
    }

    public function test_super_admin_has_super_admin_role_but_not_tenant_role(): void
    {
        $this->actingAsSuperAdmin();

        $auth = $this->getSharedAuth('/admin/dashboard');

        $this->assertTrue($auth['user']['has_super_admin_role']);
        $this->assertFalse($auth['user']['has_tenant_role']);
    }

    // -------------------------------------------------------------------------
    // roles array
    // -------------------------------------------------------------------------

    public function test_roles_array_contains_user_role_values(): void
    {
        $this->actingAsOwner($this->tenant);

        $auth = $this->getSharedAuth('/dashboard');

        $this->assertArrayHasKey('roles', $auth['user']);
        $this->assertContains('owner', $auth['user']['roles']);
    }

    public function test_super_admin_roles_array_contains_super_admin(): void
    {
        $this->actingAsSuperAdmin();

        $auth = $this->getSharedAuth('/admin/dashboard');

        $this->assertContains('super_admin', $auth['user']['roles']);
    }

    // -------------------------------------------------------------------------
    // primary_role: URL context — admin/* vs dashboard/*
    // -------------------------------------------------------------------------

    public function test_dual_role_user_primary_role_is_super_admin_on_admin_routes(): void
    {
        // Create a user who is both Owner of a tenant AND SuperAdmin
        $user = User::create([
            'name' => 'Dual Role',
            'email' => uniqid('dual_').'@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $this->tenant->id,
        ]);

        UserRole::create(['user_id' => $user->id, 'role' => Role::SuperAdmin, 'tenant_id' => null]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $this->tenant->id]);

        $this->actingAs($user);

        $auth = $this->getSharedAuth('/admin/dashboard');

        $this->assertSame('super_admin', $auth['user']['primary_role']['value']);
        $this->assertSame('Platform Admin', $auth['user']['primary_role']['label']);
        $this->assertTrue($auth['user']['has_super_admin_role']);
        $this->assertTrue($auth['user']['has_tenant_role']);
    }

    public function test_dual_role_user_primary_role_is_owner_on_dashboard_routes(): void
    {
        // Same dual-role user but on dashboard route → primary_role should be tenant role
        $user = User::create([
            'name' => 'Dual Role',
            'email' => uniqid('dual_').'@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $this->tenant->id,
        ]);

        UserRole::create(['user_id' => $user->id, 'role' => Role::SuperAdmin, 'tenant_id' => null]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $this->tenant->id]);

        $this->actingAs($user);

        $auth = $this->getSharedAuth('/dashboard');

        $this->assertSame('owner', $auth['user']['primary_role']['value']);
        $this->assertSame('Owner', $auth['user']['primary_role']['label']);
    }

    public function test_super_admin_only_primary_role_is_super_admin(): void
    {
        $this->actingAsSuperAdmin();

        $auth = $this->getSharedAuth('/admin/dashboard');

        $this->assertSame('super_admin', $auth['user']['primary_role']['value']);
        $this->assertSame('Platform Admin', $auth['user']['primary_role']['label']);
    }

    public function test_owner_primary_role_is_owner(): void
    {
        $this->actingAsOwner($this->tenant);

        $auth = $this->getSharedAuth('/dashboard');

        $this->assertSame('owner', $auth['user']['primary_role']['value']);
        $this->assertSame('Owner', $auth['user']['primary_role']['label']);
    }

    // -------------------------------------------------------------------------
    // N+1 protection: roles loaded once via loadMissing
    // -------------------------------------------------------------------------

    public function test_roles_loaded_once_via_load_missing(): void
    {
        $this->actingAsOwner($this->tenant);

        $userRoleSelectCount = 0;
        DB::listen(function ($query) use (&$userRoleSelectCount): void {
            if (str_contains($query->sql, 'user_roles')) {
                $userRoleSelectCount++;
            }
        });

        $this->getSharedAuth('/dashboard');

        $this->assertLessThanOrEqual(
            1,
            $userRoleSelectCount,
            "Expected at most 1 user_roles query (loadMissing), got {$userRoleSelectCount}",
        );
    }
}
