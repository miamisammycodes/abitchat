<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class InertiaTrialStatusTest extends TestCase
{
    public function test_active_free_tenant_shares_trial_status_with_days_remaining(): void
    {
        $free = $this->createFreePlan();
        $this->actingAsSetupTenant();
        $this->tenant->update(['plan_id' => $free->id, 'plan_expires_at' => now()->addDays(5), 'trial_activated_at' => now()]);

        $this->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('trialStatus.state', 'active')
                ->where('trialStatus.days_remaining', fn ($d) => $d >= 4 && $d <= 5));
    }
}
