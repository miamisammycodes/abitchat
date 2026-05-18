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

class DkBankQrServiceVerifyRrnTest extends TestCase
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
            'status' => 'awaiting_payment', 'dk_reference_no' => 'DKQR-1-AAAAAA',
        ]);
    }

    public function test_approves_on_valid_rrn_with_recent_txn_ts(): void
    {
        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->once()
            ->andReturn(['response_code' => '0000', 'response_data' => [[
                'status' => '0', 'amount' => '1000.00',
                'credit_account' => '110158212197',
                'txn_ts' => now()->addMinute()->toDateTimeString(),
            ]]]);
        $this->app->instance(DkBankClient::class, $mock);

        $result = $this->app->make(DkBankQrService::class)
            ->verifyByRrn($this->tx, 'BANK_RRN_123456789012');

        $this->assertSame(DkStatusState::Paid, $result->state);
        $this->tx->refresh();
        $this->assertSame('approved', $this->tx->status);
        $this->assertSame('BANK_RRN_123456789012', $this->tx->dk_rrn);
    }

    public function test_rejects_replay_attack_when_txn_ts_predates_qr_generation(): void
    {
        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->once()
            ->andReturn(['response_code' => '0000', 'response_data' => [[
                'status' => '0', 'amount' => '1000.00',
                'credit_account' => '110158212197',
                // Predates QR generation by an hour — replay attempt
                'txn_ts' => $this->tx->created_at->copy()->subHour()->toDateTimeString(),
            ]]]);
        $this->app->instance(DkBankClient::class, $mock);

        $result = $this->app->make(DkBankQrService::class)
            ->verifyByRrn($this->tx, 'BANK_RRN_REPLAY_123');

        $this->assertSame(DkStatusState::Failed, $result->state);
        $this->tx->refresh();
        $this->assertSame('awaiting_payment', $this->tx->status);
        $this->assertNull($this->tx->dk_rrn);
    }

    public function test_returns_failed_on_3001_with_friendly_message(): void
    {
        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->once()->andReturn(['response_code' => '3001']);
        $this->app->instance(DkBankClient::class, $mock);

        $result = $this->app->make(DkBankQrService::class)
            ->verifyByRrn($this->tx, 'NOT_FOUND_RRN');

        $this->assertSame(DkStatusState::Failed, $result->state);
        $this->assertStringContainsString('not found', strtolower($result->errorMessage ?? ''));
    }

    public function test_unique_constraint_on_dk_rrn_blocks_cross_tenant_replay(): void
    {
        // First tenant uses this RRN successfully (simulate by direct write)
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active', 'trial_ends_at' => now()]);
        Transaction::create([
            'tenant_id' => $tenantB->id, 'plan_id' => $this->tx->plan_id,
            'amount' => 1000, 'payment_method' => 'dk_qr',
            'payment_date' => now(), 'status' => 'approved',
            'dk_reference_no' => 'DKQR-9-ZZZZZZ', 'dk_rrn' => 'DUPLICATE_RRN',
        ]);

        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->once()
            ->andReturn(['response_code' => '0000', 'response_data' => [[
                'status' => '0', 'amount' => '1000.00',
                'credit_account' => '110158212197',
                'txn_ts' => now()->toDateTimeString(),
            ]]]);
        $this->app->instance(DkBankClient::class, $mock);

        $result = $this->app->make(DkBankQrService::class)
            ->verifyByRrn($this->tx, 'DUPLICATE_RRN');

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
