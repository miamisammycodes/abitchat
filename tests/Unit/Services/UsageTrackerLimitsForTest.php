<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Usage\UsageTracker;
use Tests\TestCase;

class UsageTrackerLimitsForTest extends TestCase
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

    public function test_setup_tenant_previews_free_plan_limits(): void
    {
        $this->freePlan();
        $tenant = Tenant::create(['name' => 'S', 'slug' => 's', 'status' => 'active']);

        $limits = app(UsageTracker::class)->limitsFor($tenant);

        $this->assertEquals(100, $limits['conversations']);
        $this->assertEquals(10, $limits['knowledge_items']);
        $this->assertEquals(50, $limits['leads']);
        $this->assertEquals(50000, $limits['tokens']);
    }

    public function test_expired_plan_tenant_still_shows_plan_limits(): void
    {
        $free = $this->freePlan();
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x', 'status' => 'active',
            'plan_id' => $free->id, 'plan_expires_at' => now()->subDay(),
        ]);

        $limits = app(UsageTracker::class)->limitsFor($tenant);
        $this->assertEquals(100, $limits['conversations']); // plan limits, NOT trial_limits 50
    }
}
