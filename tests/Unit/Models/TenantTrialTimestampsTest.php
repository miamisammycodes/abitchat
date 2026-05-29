<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TenantTrialTimestampsTest extends TestCase
{
    public function test_trial_notification_timestamps_are_castable(): void
    {
        $t = Tenant::create([
            'name' => 'T', 'slug' => 't', 'status' => 'active',
            'trial_expiring_notified_at' => now(),
            'trial_expired_notified_at' => now(),
        ]);

        $this->assertNotNull($t->fresh()->trial_expiring_notified_at);
        $this->assertInstanceOf(Carbon::class, $t->fresh()->trial_expiring_notified_at);
        $this->assertNotNull($t->fresh()->trial_expired_notified_at);
    }
}
