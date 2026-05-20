<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

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
    public function test_placeholder(): void
    {
        $this->markTestSkipped('Awaiting Plan 03 — see 16.1-VALIDATION.md task ID 16.1-03');
    }
}
