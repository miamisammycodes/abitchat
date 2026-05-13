<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Models\Plan;
use Tests\TestCase;

class ActivateTrialErrorsTest extends TestCase
{
    private function makeFreePlan(): Plan
    {
        return Plan::create([
            'name' => 'Free Trial',
            'slug' => 'free-trial-'.uniqid(),
            'description' => null,
            'price' => 0,
            'billing_period' => 'monthly',
            'is_active' => true,
            'is_contact_sales' => false,
            'conversations_limit' => 10,
            'messages_per_conversation' => 20,
            'leads_limit' => 5,
            'tokens_limit' => 1000,
            'knowledge_items_limit' => 1,
            'features' => [],
            'sort_order' => 1,
        ]);
    }

    public function test_paid_plan_returns_flash_error(): void
    {
        $this->actingAsTenantUser();
        $plan = Plan::create([
            'name' => 'Pro',
            'slug' => 'pro-'.uniqid(),
            'description' => null,
            'price' => 500,
            'billing_period' => 'monthly',
            'is_active' => true,
            'is_contact_sales' => false,
            'conversations_limit' => 100,
            'messages_per_conversation' => 50,
            'leads_limit' => 50,
            'tokens_limit' => 10000,
            'knowledge_items_limit' => 5,
            'features' => [],
            'sort_order' => 2,
        ]);

        $response = $this->post(route('client.billing.activate-trial', $plan));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'This plan requires payment.');
    }

    public function test_already_used_trial_returns_flash_error(): void
    {
        $this->actingAsTenantUser();
        $plan = $this->makeFreePlan();
        $this->tenant->update(['trial_activated_at' => now()]);

        $response = $this->post(route('client.billing.activate-trial', $plan));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Your free trial has already been used.');
    }

    public function test_already_on_plan_returns_flash_error(): void
    {
        $this->actingAsTenantUser();
        $plan = $this->makeFreePlan();
        $this->tenant->update([
            'plan_id' => $plan->id,
            'plan_expires_at' => now()->addMonths(1),
        ]);

        $response = $this->post(route('client.billing.activate-trial', $plan));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'You already have an active plan.');
    }

    public function test_inactive_plan_returns_404(): void
    {
        $this->actingAsTenantUser();
        $plan = Plan::create([
            'name' => 'Retired Free Plan',
            'slug' => 'retired-free-'.uniqid(),
            'description' => null,
            'price' => 0,
            'billing_period' => 'monthly',
            'is_active' => false,
            'is_contact_sales' => false,
            'conversations_limit' => 10,
            'messages_per_conversation' => 20,
            'leads_limit' => 5,
            'tokens_limit' => 1000,
            'knowledge_items_limit' => 1,
            'features' => [],
            'sort_order' => 1,
        ]);

        $response = $this->post(route('client.billing.activate-trial', $plan));

        $response->assertNotFound();
        $this->tenant->refresh();
        $this->assertNull($this->tenant->trial_activated_at);
        $this->assertNull($this->tenant->plan_id);
    }
}
