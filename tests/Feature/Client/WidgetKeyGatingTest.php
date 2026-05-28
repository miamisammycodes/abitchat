<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Models\Plan;
use Tests\TestCase;

class WidgetKeyGatingTest extends TestCase
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

    public function test_setup_tenant_does_not_receive_api_key(): void
    {
        $this->actingAsSetupTenant();

        $this->get(route('client.widget.index'))
            ->assertInertia(fn ($page) => $page
                ->where('tenant.api_key', null)
                ->where('widgetUnlocked', false));
    }

    public function test_active_tenant_receives_api_key(): void
    {
        $free = $this->freePlan();
        $this->actingAsSetupTenant();
        $this->tenant->update(['plan_id' => $free->id, 'plan_expires_at' => now()->addDays(14), 'trial_activated_at' => now()]);

        $this->get(route('client.widget.index'))
            ->assertInertia(fn ($page) => $page
                ->where('widgetUnlocked', true)
                ->where('tenant.api_key', fn ($v) => $v !== null)); // whereNot() unavailable in this Inertia version
    }

    public function test_setup_tenant_cannot_regenerate_key(): void
    {
        $this->actingAsSetupTenant();
        $before = $this->tenant->api_key;

        $this->post(route('client.widget.regenerate-key'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($before, $this->tenant->fresh()->api_key);
    }
}
