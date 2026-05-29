<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\Billing\TrialExpiredNotification;
use App\Notifications\Billing\TrialExpiringNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendTrialLifecycleEmailsTest extends TestCase
{
    private function tenantWithOwner(string $slug, array $attrs): Tenant
    {
        $tenant = Tenant::create(array_merge(['name' => $slug, 'slug' => $slug, 'status' => 'active'], $attrs));
        $user = User::create(['name' => 'O', 'email' => "$slug@x.test", 'password' => bcrypt('x'), 'tenant_id' => $tenant->id]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $tenant->id]);

        return $tenant;
    }

    public function test_sends_reminder_and_expired_once_each(): void
    {
        Notification::fake();
        $free = $this->createFreePlan();

        $expiring = $this->tenantWithOwner('expiring', ['plan_id' => $free->id, 'plan_expires_at' => now()->addDays(2)]);
        $expired = $this->tenantWithOwner('expired', ['plan_id' => $free->id, 'plan_expires_at' => now()->subDay()]);

        $this->artisan('trials:send-lifecycle-emails')->assertExitCode(0);

        Notification::assertSentTimes(TrialExpiringNotification::class, 1);
        Notification::assertSentTimes(TrialExpiredNotification::class, 1);

        // Idempotent: second run sends nothing more.
        $this->artisan('trials:send-lifecycle-emails')->assertExitCode(0);
        Notification::assertSentTimes(TrialExpiringNotification::class, 1);
        Notification::assertSentTimes(TrialExpiredNotification::class, 1);

        $this->assertNotNull($expiring->fresh()->trial_expiring_notified_at);
        $this->assertNotNull($expired->fresh()->trial_expired_notified_at);
    }
}
