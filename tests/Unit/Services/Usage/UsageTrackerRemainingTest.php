<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Usage;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Usage\UsageTracker;
use Tests\TestCase;

class UsageTrackerRemainingTest extends TestCase
{
    private function makeTenantWithPlan(array $limits): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Quota Co',
            'slug' => 'quota-co',
            'status' => 'active',
            'trial_ends_at' => null,
        ]);

        $plan = Plan::create([
            'name' => 'Test Plan',
            'slug' => 'test-plan-'.uniqid(),
            'price' => 100,
            'billing_period' => 'monthly',
            'is_active' => true,
            'conversations_limit' => $limits['conversations_limit'] ?? 100,
            'leads_limit' => $limits['leads_limit'] ?? 50,
            'tokens_limit' => $limits['tokens_limit'] ?? 10000,
            'knowledge_items_limit' => $limits['knowledge_items_limit'] ?? 5,
        ]);

        $tenant->update([
            'plan_id' => $plan->id,
            'plan_expires_at' => now()->addMonth(),
        ]);

        return $tenant->fresh();
    }

    public function test_negative_one_means_unlimited(): void
    {
        $tenant = $this->makeTenantWithPlan(['conversations_limit' => -1]);

        $this->assertNull(
            app(UsageTracker::class)->remaining($tenant, UsageTracker::TYPE_CONVERSATIONS),
            '-1 must signal unlimited (returns null)'
        );
    }

    public function test_zero_means_block_all_not_unlimited(): void
    {
        $tenant = $this->makeTenantWithPlan(['conversations_limit' => 0]);

        $this->assertSame(
            0,
            app(UsageTracker::class)->remaining($tenant, UsageTracker::TYPE_CONVERSATIONS),
            'A limit of 0 must yield 0 remaining (blocked), not null (unlimited)'
        );
    }

    public function test_positive_limit_returns_remainder(): void
    {
        $tenant = $this->makeTenantWithPlan(['conversations_limit' => 100]);

        $this->assertSame(
            100,
            app(UsageTracker::class)->remaining($tenant, UsageTracker::TYPE_CONVERSATIONS),
            'With no usage and a limit of 100, remaining must be 100'
        );
    }

    public function test_missing_type_returns_null(): void
    {
        $tenant = $this->makeTenantWithPlan([]);

        $this->assertNull(
            app(UsageTracker::class)->remaining($tenant, 'unknown_type'),
            'Unknown types are treated as no-limit (null)'
        );
    }
}
