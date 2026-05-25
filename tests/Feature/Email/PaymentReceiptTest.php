<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\Role;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\Billing\PaymentReceiptNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_is_sent_to_owners_after_successful_approval(): void
    {
        Notification::fake();

        [, $owner, $tx] = $this->makeAwaitingPaymentTx();

        $tx->approveAndActivate(allowedFromStatuses: ['awaiting_payment'], adminId: null);

        Notification::assertSentTo($owner, PaymentReceiptNotification::class, function (PaymentReceiptNotification $n) use ($tx): bool {
            return $n->transaction->is($tx);
        });
    }

    public function test_receipt_attaches_the_pdf(): void
    {
        Notification::fake();

        [, $owner, $tx] = $this->makeAwaitingPaymentTx();

        $tx->approveAndActivate(allowedFromStatuses: ['awaiting_payment'], adminId: null);

        Notification::assertSentTo($owner, PaymentReceiptNotification::class, function (PaymentReceiptNotification $n) use ($owner): bool {
            $mail = $n->toMail($owner);

            return ! empty($mail->rawAttachments);
        });
    }

    public function test_receipt_is_not_sent_when_outer_transaction_rolls_back(): void
    {
        Notification::fake();

        [, $owner, $tx] = $this->makeAwaitingPaymentTx();

        // Wrap in an outer transaction that rolls back. afterCommit must respect
        // the outer transaction and never fire.
        try {
            DB::transaction(function () use ($tx) {
                $tx->approveAndActivate(allowedFromStatuses: ['awaiting_payment'], adminId: null);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        Notification::assertNothingSentTo($owner);
    }

    public function test_receipt_is_not_sent_when_no_owners_exist(): void
    {
        Notification::fake();

        $tenant = Tenant::factory()->create();
        $plan = $this->makePlan();
        $tx = Transaction::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'amount' => 1000,
            'payment_method' => 'dk_qr',
            'payment_date' => now(),
            'status' => 'awaiting_payment',
            'dk_reference_no' => 'DKQR-TEST-NOOP',
        ]);

        $tx->approveAndActivate(allowedFromStatuses: ['awaiting_payment'], adminId: null);

        Notification::assertNothingSent();
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Transaction}
     */
    private function makeAwaitingPaymentTx(): array
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->create();
        UserRole::create(['user_id' => $owner->id, 'tenant_id' => $tenant->id, 'role' => Role::Owner]);

        $plan = $this->makePlan();
        $tx = Transaction::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'amount' => 1000,
            'payment_method' => 'dk_qr',
            'payment_date' => now(),
            'status' => 'awaiting_payment',
            'dk_reference_no' => 'DKQR-TEST-RCPT',
        ]);

        return [$tenant, $owner, $tx];
    }

    private function makePlan(): Plan
    {
        return Plan::create([
            'name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'description' => 'Pro plan',
            'price' => 1000, 'billing_period' => 'yearly',
            'conversations_limit' => 1000, 'messages_per_conversation' => 100,
            'knowledge_items_limit' => 50, 'tokens_limit' => 100000, 'leads_limit' => 1000,
            'is_active' => true, 'sort_order' => 1,
        ]);
    }
}
