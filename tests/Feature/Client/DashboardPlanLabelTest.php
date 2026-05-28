<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Models\Plan;
use Tests\TestCase;

class DashboardPlanLabelTest extends TestCase
{
    public function test_expired_tenant_shows_plan_name_with_expired_suffix(): void
    {
        $free = Plan::create([
            'name' => 'Free', 'slug' => 'free', 'description' => null, 'price' => 0,
            'billing_period' => 'monthly', 'conversations_limit' => 100,
            'messages_per_conversation' => 50, 'knowledge_items_limit' => 10,
            'tokens_limit' => 50000, 'leads_limit' => 50, 'is_active' => true,
            'is_contact_sales' => false, 'features' => [], 'sort_order' => 0,
        ]);
        $this->actingAsSetupTenant();
        $this->tenant->update(['plan_id' => $free->id, 'plan_expires_at' => now()->subDay()]);

        $this->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('tenant.plan', 'Free (expired)'));
    }

    public function test_setup_tenant_shows_not_started(): void
    {
        $this->actingAsSetupTenant();

        $this->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('tenant.plan', 'Not started'));
    }
}
