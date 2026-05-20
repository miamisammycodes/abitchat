<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use Tests\TestCase;

/**
 * Tests that Stripe/Cashier webhook routes are NOT protected by RequireSuperAdmin middleware.
 * Webhook handlers use signature verification, not user authentication.
 * This is Pitfall 7 from 16.1-RESEARCH.md — webhooks must remain outside the auth guard tree.
 *
 * Filled by Plan 03 (threat: webhook routes must stay outside RequireSuperAdmin middleware).
 */
class StripeAnonymousTest extends TestCase
{
    public function test_placeholder(): void
    {
        $this->markTestSkipped('Awaiting Plan 03 — see 16.1-VALIDATION.md Pitfall 7 guard');
    }
}
