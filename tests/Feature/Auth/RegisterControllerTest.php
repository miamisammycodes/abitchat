<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\TestCase;

/**
 * Tests that RegisterController inserts a user_roles row (role=owner) inside the
 * same DB transaction as User::create + Tenant::create.
 *
 * Filled by Plan 05 (D-18: RegisterController writes user_roles row on signup).
 */
class RegisterControllerTest extends TestCase
{
    public function test_placeholder(): void
    {
        $this->markTestSkipped('Awaiting Plan 05 — see 16.1-VALIDATION.md task ID 16.1-05');
    }
}
