<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Enums\TenantLifecycle;
use Tests\TestCase;

class StartFreePlanTest extends TestCase
{
    public function test_start_free_plan_activates_14_day_window(): void
    {
        $this->actingAsSetupTenant();
        $free = $this->createFreePlan();

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
        $this->createFreePlan();
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
