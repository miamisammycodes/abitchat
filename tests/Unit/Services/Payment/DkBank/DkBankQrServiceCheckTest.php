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

class DkBankQrServiceCheckTest extends TestCase
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
            'status' => 'awaiting_payment',
            'dk_reference_no' => 'DKQR-1-AAAAAA',
        ]);
    }

    public function test_returns_paid_on_status_zero_with_amount_match(): void
    {
        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->once()
            ->andReturn([
                'response_code' => '0000',
                'response_data' => [[
                    'status' => '0',
                    'amount' => '1000.00',
                    'credit_account' => '110158212197',
                    'txn_ts' => '2026-05-15 12:00:00',
                ]],
            ]);
        $this->app->instance(DkBankClient::class, $mock);

        $result = $this->app->make(DkBankQrService::class)->checkDkIntraStatus($this->tx);

        $this->assertSame(DkStatusState::Paid, $result->state);
        $this->tx->refresh();
        $this->assertSame('approved', $this->tx->status);
    }

    public function test_retries_with_next_day_when_first_returns_3001(): void
    {
        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')
            ->twice()
            ->andReturn(
                ['response_code' => '3001'],
                ['response_code' => '0000', 'response_data' => [[
                    'status' => '0', 'amount' => '1000.00',
                    'credit_account' => '110158212197', 'txn_ts' => '2026-05-15 12:00:00',
                ]]],
            );
        $this->app->instance(DkBankClient::class, $mock);

        $result = $this->app->make(DkBankQrService::class)->checkDkIntraStatus($this->tx);

        $this->assertSame(DkStatusState::Paid, $result->state);
    }

    public function test_returns_pending_when_both_dates_3001(): void
    {
        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->twice()->andReturn(['response_code' => '3001']);
        $this->app->instance(DkBankClient::class, $mock);

        $result = $this->app->make(DkBankQrService::class)->checkDkIntraStatus($this->tx);

        $this->assertSame(DkStatusState::Pending, $result->state);
        $this->tx->refresh();
        $this->assertSame('awaiting_payment', $this->tx->status);
    }

    public function test_returns_failed_on_amount_mismatch(): void
    {
        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->once()
            ->andReturn(['response_code' => '0000', 'response_data' => [[
                'status' => '0', 'amount' => '999.00',  // Mismatch
                'credit_account' => '110158212197', 'txn_ts' => '2026-05-15 12:00:00',
            ]]]);
        $this->app->instance(DkBankClient::class, $mock);

        $result = $this->app->make(DkBankQrService::class)->checkDkIntraStatus($this->tx);

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
