<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\TestCase;

/**
 * Tests the /login/choose dual-role chooser flow:
 * - dual-role user can choose admin context → redirected to /admin/dashboard
 * - dual-role user can choose tenant context → redirected to /dashboard
 * - single-role user is not shown the chooser
 *
 * Filled by Plan 03 (D-08: dual-role chooser branch).
 */
class ChooseRoleTest extends TestCase
{
    public function test_placeholder(): void
    {
        $this->markTestSkipped('Awaiting Plan 03 — see 16.1-VALIDATION.md task ID 16.1-03');
    }
}
