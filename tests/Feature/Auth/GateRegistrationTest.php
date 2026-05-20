<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\TestCase;

/**
 * Tests that all 13 ability Gates are registered in AppServiceProvider::boot().
 * Filled by Plan 04 (D-10: Laravel Gates registered for every Ability case).
 */
class GateRegistrationTest extends TestCase
{
    public function test_placeholder(): void
    {
        $this->markTestSkipped('Awaiting Plan 04 — see 16.1-VALIDATION.md task ID 16.1-04');
    }
}
