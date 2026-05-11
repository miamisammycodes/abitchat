<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Models\Plan;
use Tests\TestCase;

class BillingSubmitPaymentTest extends TestCase
{
    private function makePlan(int $price = 500): Plan
    {
        return Plan::create([
            'name' => 'Pro',
            'slug' => 'pro',
            'price' => $price,
            'billing_period' => 'monthly',
            'is_active' => true,
            'conversations_limit' => 100,
            'leads_limit' => 50,
            'tokens_limit' => 10000,
            'knowledge_items_limit' => 5,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'transaction_number' => 'TXN-' . uniqid(),
            'reference_number' => 'ABC123',
            'amount' => 500,
            'payment_method' => 'bob',
            'payment_date' => now()->toDateString(),
            'notes' => null,
        ], $overrides);
    }

    public function test_zero_amount_is_rejected(): void
    {
        $this->actingAsTenantUser();
        $plan = $this->makePlan(500);

        $response = $this->post(
            route('client.billing.submit-payment', ['plan' => $plan->id]),
            $this->payload(['amount' => 0])
        );

        $response->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_amount_below_plan_price_is_rejected(): void
    {
        $this->actingAsTenantUser();
        $plan = $this->makePlan(500);

        $response = $this->post(
            route('client.billing.submit-payment', ['plan' => $plan->id]),
            $this->payload(['amount' => 499])
        );

        $response->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_amount_equal_to_plan_price_is_accepted(): void
    {
        $this->actingAsTenantUser();
        $plan = $this->makePlan(500);

        $response = $this->post(
            route('client.billing.submit-payment', ['plan' => $plan->id]),
            $this->payload(['amount' => 500])
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_amount_above_plan_price_is_accepted(): void
    {
        // Tenants paying more than the price (rounding, tip, currency
        // confusion) should not be blocked — admin will reconcile.
        $this->actingAsTenantUser();
        $plan = $this->makePlan(500);

        $response = $this->post(
            route('client.billing.submit-payment', ['plan' => $plan->id]),
            $this->payload(['amount' => 600])
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('transactions', 1);
    }
}
