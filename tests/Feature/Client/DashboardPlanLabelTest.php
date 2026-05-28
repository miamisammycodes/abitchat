<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use Tests\TestCase;

class DashboardPlanLabelTest extends TestCase
{
    public function test_expired_tenant_shows_plan_name_with_expired_suffix(): void
    {
        $free = $this->createFreePlan();
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
