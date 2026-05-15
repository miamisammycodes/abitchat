<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use Tests\TestCase;

class CleanupAbandonedDkQrSessionsTest extends TestCase
{
    public function test_marks_awaiting_payment_older_than_24h_as_rejected(): void
    {
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active', 'trial_ends_at' => now()]);
        $plan = Plan::create([
            'name' => 'P', 'slug' => 'p', 'description' => 'd', 'price' => 1000,
            'billing_period' => 'yearly', 'conversations_limit' => 1, 'messages_per_conversation' => 1,
            'knowledge_items_limit' => 1, 'tokens_limit' => 1, 'leads_limit' => 1,
            'is_active' => true, 'sort_order' => 1,
        ]);

        $stale = Transaction::create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'amount' => 1000,
            'payment_method' => 'dk_qr', 'payment_date' => now(),
            'status' => 'awaiting_payment', 'dk_reference_no' => 'DKQR-STALE',
        ]);
        $stale->created_at = now()->subDays(2);
        $stale->save();

        $fresh = Transaction::create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'amount' => 1000,
            'payment_method' => 'dk_qr', 'payment_date' => now(),
            'status' => 'awaiting_payment', 'dk_reference_no' => 'DKQR-FRESH',
        ]);

        $this->artisan('dk:cleanup-abandoned-qr')
            ->expectsOutput('Cleaned up 1 abandoned DK QR session(s).')
            ->assertExitCode(0);

        $stale->refresh();
        $fresh->refresh();
        $this->assertSame('rejected', $stale->status);
        $this->assertSame('awaiting_payment', $fresh->status);
        $this->assertStringContainsString('auto-expired', $stale->admin_notes);
    }
}
