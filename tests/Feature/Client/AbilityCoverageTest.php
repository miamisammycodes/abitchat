<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use Tests\TestCase;

/**
 * Sweeps every Client controller action and asserts each role-gated endpoint
 * has an authorize() call for the correct ability (D-10 controller sweep).
 *
 * Filled by Plan 05 (D-10: authorize() calls added to all Client controllers).
 */
class AbilityCoverageTest extends TestCase
{
    public function test_placeholder(): void
    {
        $this->markTestSkipped('Awaiting Plan 05 — see 16.1-VALIDATION.md task ID 16.1-05');
    }
}
