<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Plan;
use Tests\TestCase;

class TenantExtendPlanTest extends TestCase
{
    public function test_yearly_plan_extends_by_twelve_months(): void
    {
        $this->createTenantWithUser();
        $this->tenant->update(['plan_id' => null, 'plan_expires_at' => null]);

        $plan = Plan::create([
            'name' => 'Yearly Pro',
            'slug' => 'yearly-pro',
            'price' => 1200,
            'billing_period' => 'yearly',
            'is_active' => true,
            'conversations_limit' => -1,
            'leads_limit' => -1,
            'tokens_limit' => -1,
            'knowledge_items_limit' => -1,
        ]);

        $before = now();
        $this->tenant->extendPlan($plan);
        $this->tenant->refresh();

        $expected = $before->copy()->addMonths(12);
        $this->assertEqualsWithDelta(
            $expected->getTimestamp(),
            $this->tenant->plan_expires_at->getTimestamp(),
            5,
            'Yearly plan should extend 12 months from now'
        );
    }

    public function test_monthly_plan_extends_by_one_month(): void
    {
        $this->createTenantWithUser();
        $this->tenant->update(['plan_id' => null, 'plan_expires_at' => null]);

        $plan = Plan::create([
            'name' => 'Monthly Starter',
            'slug' => 'monthly-starter',
            'price' => 100,
            'billing_period' => 'monthly',
            'is_active' => true,
            'conversations_limit' => 100,
            'leads_limit' => 50,
            'tokens_limit' => 10000,
            'knowledge_items_limit' => 5,
        ]);

        $before = now();
        $this->tenant->extendPlan($plan);
        $this->tenant->refresh();

        $expected = $before->copy()->addMonths(1);
        $this->assertEqualsWithDelta(
            $expected->getTimestamp(),
            $this->tenant->plan_expires_at->getTimestamp(),
            5
        );
    }

    public function test_active_yearly_plan_extension_preserves_remaining_time(): void
    {
        $this->createTenantWithUser();
        $existing = now()->addMonths(3);
        $this->tenant->update([
            'plan_id' => null,
            'plan_expires_at' => $existing,
        ]);

        $plan = Plan::create([
            'name' => 'Yearly Renewal',
            'slug' => 'yearly-renewal',
            'price' => 1200,
            'billing_period' => 'yearly',
            'is_active' => true,
            'conversations_limit' => -1,
            'leads_limit' => -1,
            'tokens_limit' => -1,
            'knowledge_items_limit' => -1,
        ]);

        $this->tenant->extendPlan($plan);
        $this->tenant->refresh();

        $expected = $existing->copy()->addMonths(12);
        $this->assertEqualsWithDelta(
            $expected->getTimestamp(),
            $this->tenant->plan_expires_at->getTimestamp(),
            5
        );
    }
}
