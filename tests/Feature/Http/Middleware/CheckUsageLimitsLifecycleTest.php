<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use Tests\TestCase;

class CheckUsageLimitsLifecycleTest extends TestCase
{
    public function test_setup_tenant_can_reach_knowledge_store(): void
    {
        $this->createFreePlan();
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
        $free = $this->createFreePlan();
        $this->actingAsSetupTenant();
        $this->tenant->update(['plan_id' => $free->id, 'plan_expires_at' => now()->subDay()]);

        $this->post(route('client.knowledge.store'), [])
            ->assertRedirect(route('client.billing.plans'));
    }
}
