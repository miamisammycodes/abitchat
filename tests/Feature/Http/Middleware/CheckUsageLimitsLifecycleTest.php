<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use App\Models\Plan;
use Tests\TestCase;

class CheckUsageLimitsLifecycleTest extends TestCase
{
    private function freePlan(): Plan
    {
        return Plan::create([
            'name' => 'Free', 'slug' => 'free', 'description' => null, 'price' => 0,
            'billing_period' => 'monthly', 'conversations_limit' => 100,
            'messages_per_conversation' => 50, 'knowledge_items_limit' => 10,
            'tokens_limit' => 50000, 'leads_limit' => 50, 'is_active' => true,
            'is_contact_sales' => false, 'features' => [], 'sort_order' => 0,
        ]);
    }

    public function test_setup_tenant_can_reach_knowledge_store(): void
    {
        $this->freePlan();
        $this->actingAsSetupTenant();

        $response = $this->post(route('client.knowledge.store'), []);
        // Setup-allowed: the request reaches the controller (its own validation),
        // it is NOT short-circuited to billing the way an Expired tenant would be.
        // (Do NOT assert session-missing 'errors' — controller validation legitimately
        // writes errors once the gate lets the request through.)
        $this->assertNotSame(route('client.billing.plans'), $response->headers->get('Location'));
    }

    public function test_expired_tenant_blocked_from_knowledge_store(): void
    {
        $free = $this->freePlan();
        $this->actingAsSetupTenant();
        $this->tenant->update(['plan_id' => $free->id, 'plan_expires_at' => now()->subDay()]);

        $this->post(route('client.knowledge.store'), [])
            ->assertRedirect(route('client.billing.plans'));
    }
}
