<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use Tests\TestCase;

/**
 * Tests TransactionPolicy stacks tenant-ownership check AND role-tier check (Owner only):
 * - Owner of the same tenant can view transactions
 * - Manager and Agent of the same tenant are denied (fails Owner-exact check)
 * - User from a different tenant cannot access
 *
 * Filled by Plan 04 (D-11: existing policies upgraded to stack ownership + role-tier).
 */
class TransactionPolicyTest extends TestCase
{
    public function test_placeholder(): void
    {
        $this->markTestSkipped('Awaiting Plan 04 — see 16.1-VALIDATION.md task ID 16.1-04');
    }
}
