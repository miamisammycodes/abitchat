<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\TestCase;

/**
 * Tests role-aware post-login redirect:
 * - super_admin-only → /admin/dashboard
 * - tenant-only → /dashboard
 * - dual-role → /login/choose (chooser page)
 *
 * Filled by Plan 03 (D-08: single /login page, role-aware redirect).
 */
class LoginRedirectTest extends TestCase
{
    public function test_placeholder(): void
    {
        $this->markTestSkipped('Awaiting Plan 03 — see 16.1-VALIDATION.md task ID 16.1-03');
    }
}
