<?php

declare(strict_types=1);

namespace Tests\Feature\Webhook;

use Illuminate\Support\Facades\Route;
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
    public function test_stripe_webhook_endpoint_is_anonymously_reachable_and_not_403(): void
    {
        // Stripe webhook route auto-registered by Cashier's service provider at /stripe/webhook.
        // An anonymous POST (no auth) should NOT receive 403 from RequireSuperAdmin.
        // Cashier will return 400/422 for an unsigned/invalid payload — that is the correct behaviour.
        $response = $this->withoutMiddleware(['throttle'])->postJson('/stripe/webhook', []);

        // Assert we did NOT get a 403 (which would indicate RequireSuperAdmin fired)
        $this->assertNotEquals(403, $response->getStatusCode(), 'Stripe webhook must not be gated by RequireSuperAdmin. Got 403.');

        // The response from Cashier for an invalid webhook signature is 400/403 from Cashier itself.
        // We specifically check it is NOT 403 from our middleware, but a Cashier 400 is acceptable.
        // Verify no `require.super_admin` in the webhook route's middleware stack.
        $stripeRoute = Route::getRoutes()->getByName('cashier.webhook');

        if ($stripeRoute !== null) {
            $middleware = $stripeRoute->gatherMiddleware();
            $this->assertNotContains(
                'require.super_admin',
                $middleware,
                'Stripe webhook route must not include require.super_admin middleware'
            );
        } else {
            // Route name may differ; verify by inspecting the route list
            $found = false;
            foreach (Route::getRoutes() as $route) {
                if (str_contains($route->uri(), 'stripe/webhook')) {
                    $found = true;
                    $middleware = $route->gatherMiddleware();
                    $this->assertNotContains(
                        'require.super_admin',
                        $middleware,
                        'Stripe webhook route must not include require.super_admin middleware'
                    );
                    break;
                }
            }
            if (! $found) {
                $this->markTestSkipped('No Stripe webhook route found — Cashier may not be installed or routes not loaded.');
            }
        }
    }
}
