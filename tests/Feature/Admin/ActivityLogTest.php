<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AdminActivityLog;
use App\Models\Tenant;
use Tests\Concerns\SeedsRoleMatrix;
use Tests\TestCase;

/**
 * Tests that AdminActivityLog::log() reads Auth::user() (unified web guard)
 * and enforces the SuperAdmin role invariant.
 *
 * Covers: D-05 (admin log invariant after admin guard removal in Plan 03).
 */
class ActivityLogTest extends TestCase
{
    use SeedsRoleMatrix;

    /**
     * Happy path: super_admin logs an action → row inserted with admin_user_id = super_admin id.
     */
    public function test_log_inserts_row_for_super_admin(): void
    {
        $this->actingAsSuperAdmin();

        $log = AdminActivityLog::log('plan.updated', null, ['key' => 'val']);

        $this->assertNotNull($log->id);
        $this->assertSame($this->superAdminUser->id, $log->admin_user_id);
        $this->assertSame('plan.updated', $log->action_type);
        $this->assertSame(['key' => 'val'], $log->details);
    }

    /**
     * Acting as a tenant Owner (not super_admin) → LogicException thrown.
     */
    public function test_log_throws_logic_exception_for_non_super_admin(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Corp',
            'slug' => 'test-corp',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $this->actingAsOwner($tenant);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AdminActivityLog::log called outside super_admin context');

        AdminActivityLog::log('plan.updated', null, []);
    }

    /**
     * Unauthenticated call → LogicException thrown.
     */
    public function test_log_throws_logic_exception_when_unauthenticated(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AdminActivityLog::log called outside super_admin context');

        AdminActivityLog::log('plan.updated', null, []);
    }

    /**
     * Regression: log() does not reference Auth::guard('admin').
     */
    public function test_log_does_not_use_admin_guard(): void
    {
        $source = file_get_contents(app_path('Models/AdminActivityLog.php'));

        $this->assertStringNotContainsString(
            "Auth::guard('admin')",
            (string) $source,
            "AdminActivityLog::log must not reference the deleted 'admin' guard"
        );

        $this->assertStringContainsString(
            'Auth::user()',
            (string) $source,
            'AdminActivityLog::log must call Auth::user() directly'
        );

        $this->assertStringContainsString(
            'hasRole(Role::SuperAdmin)',
            (string) $source,
            'AdminActivityLog::log must assert SuperAdmin role'
        );
    }
}
