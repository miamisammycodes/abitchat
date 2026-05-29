<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Enums\TenantLifecycle;
use App\Notifications\Billing\TrialStartedNotification;
use Illuminate\Support\Facades\Notification;
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

    public function test_starting_free_plan_sends_trial_started_email(): void
    {
        Notification::fake();
        $this->actingAsSetupTenant();
        $this->createFreePlan();

        $this->post(route('client.billing.start-free-plan'));

        Notification::assertSentTimes(TrialStartedNotification::class, 1);
    }

    public function test_free_plan_activates_even_if_email_dispatch_throws(): void
    {
        $this->actingAsSetupTenant();
        $this->createFreePlan();

        // Simulate a queue/mail dispatch failure after the plan commits.
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('queue down'));

        $this->post(route('client.billing.start-free-plan'))
            ->assertRedirect(route('client.billing.index'))
            ->assertSessionHas('success');

        $this->assertSame(
            TenantLifecycle::Active,
            $this->tenant->fresh()->lifecycleState(),
        );
    }
}
