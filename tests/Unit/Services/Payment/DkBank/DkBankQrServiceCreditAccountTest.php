<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\DkBank;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Payment\DkBank\DkBankClient;
use App\Services\Payment\DkBank\DkBankQrService;
use App\Services\Payment\DkBank\DTO\DkStatusState;
use Mockery;
use Tests\TestCase;

class DkBankQrServiceCreditAccountTest extends TestCase
{
    private Transaction $tx;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.dk_bank.beneficiary_account', '110158212197');

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active', 'trial_ends_at' => now()]);
        $plan = Plan::create([
            'name' => 'P', 'slug' => 'p', 'description' => 'd', 'price' => 1000,
            'billing_period' => 'yearly', 'conversations_limit' => 1, 'messages_per_conversation' => 1,
            'knowledge_items_limit' => 1, 'tokens_limit' => 1, 'leads_limit' => 1,
            'is_active' => true, 'sort_order' => 1,
        ]);
        $this->tx = Transaction::create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'amount' => 1000,
            'payment_method' => 'dk_qr', 'payment_date' => now(),
            'status' => 'awaiting_payment', 'dk_reference_no' => 'DKQR-CA-AAAAAA',
        ]);
    }

    /**
     * @param  array<string, mixed>  $statusOverrides
     */
    private function mockPaid(array $statusOverrides = []): void
    {
        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->once()
            ->andReturn(['response_code' => '0000', 'response_data' => ['status' => array_merge([
                'status' => '0', 'amount' => '1000.00',
                'credit_account' => '110158212197',
                'txn_ts' => now()->addMinute()->toDateTimeString(),
            ], $statusOverrides)]]);
        $this->app->instance(DkBankClient::class, $mock);
    }

    public function test_exact_mode_matches_identical_account(): void
    {
        config()->set('services.dk_bank.account_match', 'exact');
        $this->mockPaid(['credit_account' => '110158212197']);

        $result = $this->app->make(DkBankQrService::class)->verifyByRrn($this->tx, 'RRN_EXACT_OK');

        $this->assertSame(DkStatusState::Paid, $result->state);
    }

    public function test_exact_mode_normalizes_spaces_and_case(): void
    {
        // Same digits, DK returns with spacing/case noise — normalization must still match.
        config()->set('services.dk_bank.account_match', 'exact');
        $this->mockPaid(['credit_account' => ' 110158212197 ']);

        $result = $this->app->make(DkBankQrService::class)->verifyByRrn($this->tx, 'RRN_EXACT_NORM');

        $this->assertSame(DkStatusState::Paid, $result->state);
    }

    public function test_exact_mode_rejects_suffix_only_match(): void
    {
        // A masked/last-4 reported account must NOT pass in exact mode.
        config()->set('services.dk_bank.account_match', 'exact');
        $this->mockPaid(['credit_account' => '2197']);

        $result = $this->app->make(DkBankQrService::class)->verifyByRrn($this->tx, 'RRN_EXACT_FAIL');

        $this->assertSame(DkStatusState::Failed, $result->state);
        $this->tx->refresh();
        $this->assertSame('awaiting_payment', $this->tx->status);
    }

    public function test_suffix_mode_matches_last_four_digits(): void
    {
        config()->set('services.dk_bank.account_match', 'suffix');
        config()->set('services.dk_bank.account_match_digits', 4);
        $this->mockPaid(['credit_account' => 'XXXXXXXX2197']);

        $result = $this->app->make(DkBankQrService::class)->verifyByRrn($this->tx, 'RRN_SUFFIX_OK');

        $this->assertSame(DkStatusState::Paid, $result->state);
    }

    public function test_suffix_mode_rejects_different_last_four(): void
    {
        config()->set('services.dk_bank.account_match', 'suffix');
        config()->set('services.dk_bank.account_match_digits', 4);
        $this->mockPaid(['credit_account' => 'XXXXXXXX9999']);

        $result = $this->app->make(DkBankQrService::class)->verifyByRrn($this->tx, 'RRN_SUFFIX_FAIL');

        $this->assertSame(DkStatusState::Failed, $result->state);
        $this->tx->refresh();
        $this->assertSame('awaiting_payment', $this->tx->status);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
