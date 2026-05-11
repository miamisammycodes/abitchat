<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\Tenant;
use Tests\TestCase;

class UpdatePlanPreservesExpiryTest extends TestCase
{
    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AdminUser::create([
            'name' => 'Admin',
            'email' => 'admin@test.example',
            'password' => bcrypt('password'),
        ]);
    }

    private function makeTenant(?\Carbon\Carbon $expires = null): Tenant
    {
        return Tenant::create([
            'name' => 'Tenant',
            'slug' => 'tenant-' . uniqid(),
            'status' => 'active',
            'plan_expires_at' => $expires,
        ]);
    }

    private function makePlan(string $billingPeriod = 'monthly'): Plan
    {
        return Plan::create([
            'name' => 'Plan ' . $billingPeriod,
            'slug' => 'plan-' . $billingPeriod . '-' . uniqid(),
            'price' => 100,
            'billing_period' => $billingPeriod,
            'is_active' => true,
            'conversations_limit' => 100,
            'leads_limit' => 50,
            'tokens_limit' => 10000,
            'knowledge_items_limit' => 5,
        ]);
    }

    public function test_blank_expires_at_preserves_remaining_time_on_yearly_plan(): void
    {
        $existing = now()->addMonths(9);
        $tenant = $this->makeTenant($existing);
        $plan = $this->makePlan('yearly');

        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.clients.update-plan', $tenant), [
                'plan_id' => $plan->id,
            ])
            ->assertRedirect();

        $tenant->refresh();
        $expected = $existing->copy()->addMonths(12);
        $this->assertEqualsWithDelta(
            $expected->getTimestamp(),
            $tenant->plan_expires_at->getTimestamp(),
            5,
            'Yearly plan extension must add 12 months to the existing future expiry'
        );
    }

    public function test_blank_expires_at_on_expired_plan_starts_from_now(): void
    {
        $expired = now()->subMonths(2);
        $tenant = $this->makeTenant($expired);
        $plan = $this->makePlan('monthly');

        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.clients.update-plan', $tenant), [
                'plan_id' => $plan->id,
            ])
            ->assertRedirect();

        $tenant->refresh();
        $expected = now()->addMonths(1);
        $this->assertEqualsWithDelta(
            $expected->getTimestamp(),
            $tenant->plan_expires_at->getTimestamp(),
            5,
            'Expired-plan base resets to now(), not the past expiry'
        );
    }

    public function test_explicit_expires_at_is_honored(): void
    {
        $tenant = $this->makeTenant(now()->addMonths(2));
        $plan = $this->makePlan('monthly');
        $explicit = now()->addMonths(6)->startOfDay();

        $this->actingAs($this->admin, 'admin')
            ->put(route('admin.clients.update-plan', $tenant), [
                'plan_id' => $plan->id,
                'expires_at' => $explicit->toDateString(),
            ])
            ->assertRedirect();

        $tenant->refresh();
        $this->assertSame(
            $explicit->toDateString(),
            $tenant->plan_expires_at->toDateString(),
            'Explicit expires_at must override extension logic'
        );
    }
}
