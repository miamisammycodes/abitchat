<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use Tests\TestCase;

/**
 * Tests for User::hasRole(Role) and User::hasRoleAtLeast(Role, Tenant) resolution logic.
 * Filled by Plan 02 (D-04: backed enum + hasRole/hasRoleAtLeast methods on User).
 */
class UserRoleResolutionTest extends TestCase
{
    public function test_placeholder(): void
    {
        $this->markTestSkipped('Awaiting Plan 02 — see 16.1-VALIDATION.md task ID 16.1-02');
    }
}
