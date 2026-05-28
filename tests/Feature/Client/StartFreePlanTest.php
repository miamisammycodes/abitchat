<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Enums\TenantLifecycle;
use App\Models\Plan;
use Tests\TestCase;

class StartFreePlanTest extends TestCase
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

    public function test_start_free_plan_activates_14_day_window(): void
    {
        $this->actingAsSetupTenant();
        $free = $this->freePlan();

        $this->post(route('client.billing.start-free-plan'))
            ->assertRedirect(route('client.billing.index'));

        $this->tenant->refresh();
        $this->assertSame($free->id, $this->tenant->plan_id);
        $this->assertNotNull($this->tenant->trial_activated_at);
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, $this->tenant->plan_expires_at->timestamp, 60);
        $this->assertSame(TenantLifecycle::Active, $this->tenant->lifecycleState());
    }

    public function test_cannot_start_free_plan_twice(): void
    {
        $this->actingAsSetupTenant();
        $this->freePlan();
        $this->tenant->update(['trial_activated_at' => now()]);

        $this->post(route('client.billing.start-free-plan'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_missing_free_plan_flashes_error(): void
    {
        $this->actingAsSetupTenant(); // no Free plan seeded

        $this->post(route('client.billing.start-free-plan'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
