<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use Tests\TestCase;

/**
 * Tests KnowledgeItemPolicy stacks tenant-ownership check AND role-tier check (Manager+):
 * - Owner and Manager of the same tenant can view/create/update/delete knowledge items
 * - Agent of the same tenant is denied (fails Manager+ check)
 * - Agent of a different tenant cannot access
 *
 * Filled by Plan 04 (D-11: existing policies upgraded to stack ownership + role-tier).
 */
class KnowledgeItemPolicyTest extends TestCase
{
    public function test_placeholder(): void
    {
        $this->markTestSkipped('Awaiting Plan 04 — see 16.1-VALIDATION.md task ID 16.1-04');
    }
}
