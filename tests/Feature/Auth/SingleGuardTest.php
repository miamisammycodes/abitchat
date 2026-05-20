<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\TestCase;

/**
 * Tests that the admin guard is removed and a single web guard is active.
 * Filled by Plan 03 (D-05: drop admin guard, single web guard).
 */
class SingleGuardTest extends TestCase
{
    public function test_placeholder(): void
    {
        $this->markTestSkipped('Awaiting Plan 03 — see 16.1-VALIDATION.md task ID 16.1-03');
    }
}
