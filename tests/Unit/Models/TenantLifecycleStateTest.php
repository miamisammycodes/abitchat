<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\TenantLifecycle;
use App\Models\Plan;
use App\Models\Tenant;
use Tests\TestCase;

class TenantLifecycleStateTest extends TestCase
{
    private function aPlan(): Plan
    {
        // plan_id is a constrained FK (nullOnDelete) — a real Plan row is required.
        return Plan::create([
            'name' => 'Free', 'slug' => 'free', 'description' => null, 'price' => 0,
            'billing_period' => 'monthly', 'conversations_limit' => 100,
            'messages_per_conversation' => 50, 'knowledge_items_limit' => 10,
            'tokens_limit' => 50000, 'leads_limit' => 50, 'is_active' => true,
            'is_contact_sales' => false, 'features' => [], 'sort_order' => 0,
        ]);
    }

    public function test_setup_when_no_plan_and_no_trial(): void
    {
        $t = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        $this->assertSame(TenantLifecycle::Setup, $t->lifecycleState());
    }

    public function test_active_when_plan_not_expired(): void
    {
        $plan = $this->aPlan();
        $t = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active', 'plan_id' => $plan->id, 'plan_expires_at' => now()->addDay()]);
        $this->assertSame(TenantLifecycle::Active, $t->lifecycleState());
    }

    public function test_expired_when_plan_past(): void
    {
        $plan = $this->aPlan();
        $t = Tenant::create(['name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $plan->id, 'plan_expires_at' => now()->subDay()]);
        $this->assertSame(TenantLifecycle::Expired, $t->lifecycleState());
    }

    public function test_legacy_trial_when_trial_active(): void
    {
        $t = Tenant::create(['name' => 'D', 'slug' => 'd', 'status' => 'active', 'trial_ends_at' => now()->addDay()]);
        $this->assertSame(TenantLifecycle::LegacyTrial, $t->lifecycleState());
    }

    public function test_expired_when_legacy_trial_past(): void
    {
        $t = Tenant::create(['name' => 'E', 'slug' => 'e', 'status' => 'active', 'trial_ends_at' => now()->subDay()]);
        $this->assertSame(TenantLifecycle::Expired, $t->lifecycleState());
    }

    public function test_enum_permission_helpers(): void
    {
        $this->assertTrue(TenantLifecycle::Active->allowsWidget());
        $this->assertTrue(TenantLifecycle::LegacyTrial->allowsWidget());
        $this->assertFalse(TenantLifecycle::Setup->allowsWidget());
        $this->assertFalse(TenantLifecycle::Expired->allowsWidget());

        $this->assertTrue(TenantLifecycle::Setup->allowsKnowledgeWrites());
        $this->assertTrue(TenantLifecycle::Active->allowsKnowledgeWrites());
        $this->assertFalse(TenantLifecycle::Expired->allowsKnowledgeWrites());
    }
}
