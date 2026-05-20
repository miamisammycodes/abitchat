<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use Tests\TestCase;

/**
 * Tests LeadPolicy stacks tenant-ownership check AND role-tier check (Agent+):
 * - Owner, Manager, Agent of the same tenant can view/update/delete leads
 * - Agent of a different tenant cannot access
 * - SuperAdmin without a tenant role cannot access (orthogonal)
 *
 * Filled by Plan 04 (D-11: existing policies upgraded to stack ownership + role-tier).
 */
class LeadPolicyTest extends TestCase
{
    public function test_placeholder(): void
    {
        $this->markTestSkipped('Awaiting Plan 04 — see 16.1-VALIDATION.md task ID 16.1-04');
    }
}
