<?php

declare(strict_types=1);

namespace Tests\Feature\Inertia;

use Tests\TestCase;

/**
 * Tests the Inertia shared auth data contract:
 * - auth.user.can map contains all 13 ability keys (snake_case slugs)
 * - auth.user.roles array contains the user's assigned roles
 * - auth.user.has_super_admin_role bool is correctly set
 * - auth.admin block is removed (replaced by unified auth.user)
 *
 * Filled by Plan 06a (D-13: resolved abilities shared via HandleInertiaRequests::share()).
 */
class AuthShareTest extends TestCase
{
    public function test_placeholder(): void
    {
        $this->markTestSkipped('Awaiting Plan 06a — see 16.1-VALIDATION.md task ID 16.1-06a');
    }
}
