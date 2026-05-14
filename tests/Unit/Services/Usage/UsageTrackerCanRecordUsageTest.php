<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Usage;

use App\Models\Conversation;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Usage\UsageTracker;
use Tests\TestCase;

class UsageTrackerCanRecordUsageTest extends TestCase
{
    private function makeTenantWithPlan(array $limits): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Quota Co',
            'slug' => 'quota-co-'.uniqid(),
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

    private function consumeConversations(Tenant $tenant, int $count): void
    {
        // BustsTenantUsageCache (on Conversation) fires forgetCacheForTenant
        // on each created event — no explicit cache-bust needed here.
        for ($i = 0; $i < $count; $i++) {
            Conversation::create([
                'tenant_id' => $tenant->id,
                'session_id' => 'sess-'.uniqid(),
                'status' => 'active',
            ]);
        }
    }

    public function test_returns_true_when_limit_is_unlimited(): void
    {
        $tenant = $this->makeTenantWithPlan(['conversations_limit' => -1]);

        $this->assertTrue(
            app(UsageTracker::class)->canRecordUsage($tenant, UsageTracker::TYPE_CONVERSATIONS),
            'Unlimited (-1) must allow recording'
        );
    }

    public function test_returns_false_when_limit_is_zero(): void
    {
        $tenant = $this->makeTenantWithPlan(['conversations_limit' => 0]);

        $this->assertFalse(
            app(UsageTracker::class)->canRecordUsage($tenant, UsageTracker::TYPE_CONVERSATIONS),
            'A hard limit of 0 must block all recording'
        );
    }

    public function test_returns_true_when_under_limit(): void
    {
        $tenant = $this->makeTenantWithPlan(['conversations_limit' => 10]);
        $this->consumeConversations($tenant, 5);

        $this->assertTrue(
            app(UsageTracker::class)->canRecordUsage($tenant, UsageTracker::TYPE_CONVERSATIONS),
            'remaining = 5 must allow recording'
        );
    }

    public function test_returns_false_at_exactly_the_limit(): void
    {
        $tenant = $this->makeTenantWithPlan(['conversations_limit' => 3]);
        $this->consumeConversations($tenant, 3);

        $this->assertFalse(
            app(UsageTracker::class)->canRecordUsage($tenant, UsageTracker::TYPE_CONVERSATIONS),
            'remaining = 0 (used == limit) must block recording'
        );
    }

    public function test_returns_false_when_over_consumed(): void
    {
        // Edge case: even though remaining() clamps to >= 0 today, the
        // canRecordUsage semantic is "<= 0 blocks" — if the clamp is ever
        // removed and remaining returns a negative number, the gate must
        // still block. With current clamping, used > limit yields remaining=0
        // which is also blocked, so this test asserts the practical outcome.
        $tenant = $this->makeTenantWithPlan(['conversations_limit' => 2]);
        $this->consumeConversations($tenant, 5);

        $this->assertFalse(
            app(UsageTracker::class)->canRecordUsage($tenant, UsageTracker::TYPE_CONVERSATIONS),
            'Over-consumed tenants must be blocked'
        );
    }

    public function test_returns_true_for_unknown_type(): void
    {
        $tenant = $this->makeTenantWithPlan([]);

        $this->assertTrue(
            app(UsageTracker::class)->canRecordUsage($tenant, 'unknown_type'),
            'Unknown types treat as unlimited (true)'
        );
    }
}
