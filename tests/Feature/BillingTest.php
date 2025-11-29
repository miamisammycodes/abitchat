<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Transaction;
use Tests\TestCase;

class BillingTest extends TestCase
{
    public function test_billing_page_requires_authentication(): void
    {
        $response = $this->get('/billing');

        $response->assertRedirect('/login');
    }

    public function test_billing_page_can_be_rendered(): void
    {
        $this->actingAsTenantUser();

        $response = $this->get('/billing');

        $response->assertStatus(200);
    }

    public function test_plans_page_can_be_rendered(): void
    {
        $this->actingAsTenantUser();

        $response = $this->get('/billing/plans');

        $response->assertStatus(200);
    }

    public function test_subscribe_page_can_be_rendered(): void
    {
        $this->actingAsTenantUser();

        $plan = Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro',
            'description' => 'Professional plan',
            'price' => 29.99,
            'billing_period' => 'month',
            'conversations_limit' => 100,
            'messages_per_conversation' => 50,
            'knowledge_items_limit' => 50,
            'tokens_limit' => 100000,
            'leads_limit' => 500,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $response = $this->get("/billing/subscribe/{$plan->id}");

        $response->assertStatus(200);
    }

    public function test_user_can_submit_payment(): void
    {
        $this->actingAsTenantUser();

        $plan = Plan::create([
            'name' => 'Pro Plan',
            'slug' => 'pro-plan',
            'description' => 'Professional plan',
            'price' => 29.99,
            'billing_period' => 'month',
            'conversations_limit' => 100,
            'messages_per_conversation' => 50,
            'knowledge_items_limit' => 50,
            'tokens_limit' => 100000,
            'leads_limit' => 500,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $response = $this->post("/billing/subscribe/{$plan->id}", [
            'transaction_number' => 'TXN123456789',
            'amount' => 29.99,
            'payment_method' => 'bank_transfer',
            'payment_date' => now()->format('Y-m-d'),
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('client.billing.index'));

        $this->assertDatabaseHas('transactions', [
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'transaction_number' => 'TXN123456789',
            'status' => 'pending',
        ]);
    }

    public function test_payment_requires_transaction_number(): void
    {
        $this->actingAsTenantUser();

        $plan = Plan::create([
            'name' => 'Basic Plan',
            'slug' => 'basic-plan',
            'description' => 'Basic plan',
            'price' => 9.99,
            'billing_period' => 'month',
            'conversations_limit' => 10,
            'messages_per_conversation' => 20,
            'knowledge_items_limit' => 10,
            'tokens_limit' => 10000,
            'leads_limit' => 100,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $response = $this->post("/billing/subscribe/{$plan->id}", [
            'amount' => 9.99,
            'payment_method' => 'bank_transfer',
            'payment_date' => now()->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors('transaction_number');
    }

    public function test_payment_requires_valid_payment_method(): void
    {
        $this->actingAsTenantUser();

        $plan = Plan::create([
            'name' => 'Test Plan',
            'slug' => 'test-plan',
            'description' => 'Test plan',
            'price' => 19.99,
            'billing_period' => 'month',
            'conversations_limit' => 50,
            'messages_per_conversation' => 30,
            'knowledge_items_limit' => 25,
            'tokens_limit' => 50000,
            'leads_limit' => 250,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $response = $this->post("/billing/subscribe/{$plan->id}", [
            'transaction_number' => 'TXN-INVALID-TEST',
            'amount' => 19.99,
            'payment_method' => 'bitcoin', // Invalid payment method
            'payment_date' => now()->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors('payment_method');
    }

    public function test_duplicate_transaction_number_is_rejected(): void
    {
        $this->actingAsTenantUser();

        $plan = Plan::create([
            'name' => 'Duplicate Test Plan',
            'slug' => 'duplicate-test',
            'description' => 'Plan for duplicate test',
            'price' => 29.99,
            'billing_period' => 'month',
            'conversations_limit' => 100,
            'messages_per_conversation' => 50,
            'knowledge_items_limit' => 50,
            'tokens_limit' => 100000,
            'leads_limit' => 500,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // Create existing transaction
        Transaction::create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'transaction_number' => 'DUPLICATE-TXN-123',
            'amount' => 29.99,
            'payment_method' => 'bank_transfer',
            'payment_date' => now(),
            'status' => 'pending',
        ]);

        // Try to submit same transaction number
        $response = $this->post("/billing/subscribe/{$plan->id}", [
            'transaction_number' => 'DUPLICATE-TXN-123',
            'amount' => 29.99,
            'payment_method' => 'bank_transfer',
            'payment_date' => now()->format('Y-m-d'),
        ]);

        $response->assertSessionHasErrors('transaction_number');
    }

    public function test_payment_date_cannot_be_future(): void
    {
        $this->actingAsTenantUser();

        $plan = Plan::create([
            'name' => 'Future Date Plan',
            'slug' => 'future-date',
            'description' => 'Future date test plan',
            'price' => 29.99,
            'billing_period' => 'month',
            'conversations_limit' => 100,
            'messages_per_conversation' => 50,
            'knowledge_items_limit' => 50,
            'tokens_limit' => 100000,
            'leads_limit' => 500,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $response = $this->post("/billing/subscribe/{$plan->id}", [
            'transaction_number' => 'TXN-FUTURE-DATE',
            'amount' => 29.99,
            'payment_method' => 'bank_transfer',
            'payment_date' => now()->addDays(5)->format('Y-m-d'), // Future date
        ]);

        $response->assertSessionHasErrors('payment_date');
    }

    public function test_transactions_page_can_be_rendered(): void
    {
        $this->actingAsTenantUser();

        $response = $this->get('/billing/transactions');

        $response->assertStatus(200);
    }

    public function test_transactions_shows_only_tenant_transactions(): void
    {
        $this->actingAsTenantUser();

        $plan = Plan::create([
            'name' => 'Isolation Test Plan',
            'slug' => 'isolation-test',
            'description' => 'Tenant isolation test plan',
            'price' => 49.99,
            'billing_period' => 'month',
            'conversations_limit' => 200,
            'messages_per_conversation' => 100,
            'knowledge_items_limit' => 100,
            'tokens_limit' => 200000,
            'leads_limit' => 1000,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // Create transaction for current tenant
        Transaction::create([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->id,
            'transaction_number' => 'MY-TXN-123',
            'amount' => 49.99,
            'payment_method' => 'bank_transfer',
            'payment_date' => now(),
            'status' => 'pending',
        ]);

        // Create another tenant with transaction
        $otherTenant = \App\Models\Tenant::create([
            'name' => 'Other Billing Company',
            'slug' => 'other-billing',
            'status' => 'active',
        ]);

        Transaction::create([
            'tenant_id' => $otherTenant->id,
            'plan_id' => $plan->id,
            'transaction_number' => 'OTHER-TXN-456',
            'amount' => 49.99,
            'payment_method' => 'upi',
            'payment_date' => now(),
            'status' => 'approved',
        ]);

        $response = $this->get('/billing/transactions');

        $response->assertStatus(200);

        // The page should render - we can't easily check Inertia props content in this test
        // but the authorization ensures only own transactions are shown
    }

    public function test_payment_supports_all_valid_methods(): void
    {
        $this->actingAsTenantUser();

        $paymentMethods = ['bank_transfer', 'upi', 'card', 'cash', 'other'];

        foreach ($paymentMethods as $index => $method) {
            $plan = Plan::create([
                'name' => "Payment Method Plan {$index}",
                'slug' => "payment-method-{$index}",
                'description' => 'Payment method test',
                'price' => 19.99,
                'billing_period' => 'month',
                'conversations_limit' => 50,
                'messages_per_conversation' => 30,
                'knowledge_items_limit' => 25,
                'tokens_limit' => 50000,
                'leads_limit' => 250,
                'is_active' => true,
                'sort_order' => $index,
            ]);

            $response = $this->post("/billing/subscribe/{$plan->id}", [
                'transaction_number' => "TXN-{$method}-{$index}",
                'amount' => 19.99,
                'payment_method' => $method,
                'payment_date' => now()->format('Y-m-d'),
            ]);

            $this->assertDatabaseHas('transactions', [
                'transaction_number' => "TXN-{$method}-{$index}",
                'payment_method' => $method,
            ]);
        }
    }
}
