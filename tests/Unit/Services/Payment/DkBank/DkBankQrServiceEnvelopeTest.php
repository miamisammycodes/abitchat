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

class DkBankQrServiceEnvelopeTest extends TestCase
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
            'status' => 'awaiting_payment', 'dk_reference_no' => 'DKQR-EN-AAAAAA',
        ]);
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function mockClient(array $responseData): void
    {
        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->once()
            ->andReturn(['response_code' => '0000', 'response_data' => $responseData]);
        $this->app->instance(DkBankClient::class, $mock);
    }

    /**
     * @return array<string, mixed>
     */
    private function paidStatus(): array
    {
        return [
            'status' => '0', 'amount' => '1000.00',
            'credit_account' => '110158212197',
            'txn_ts' => now()->addMinute()->toDateTimeString(),
        ];
    }

    public function test_object_shaped_status_envelope_is_parsed(): void
    {
        // response_data.status (object shape)
        $this->mockClient(['status' => $this->paidStatus()]);

        $result = $this->app->make(DkBankQrService::class)->verifyByRrn($this->tx, 'RRN_OBJECT');

        $this->assertSame(DkStatusState::Paid, $result->state);
    }

    public function test_array_indexed_status_envelope_is_parsed(): void
    {
        // response_data[0].status (array-indexed shape) — previously yielded null silently
        $this->mockClient([0 => ['status' => $this->paidStatus()]]);

        $result = $this->app->make(DkBankQrService::class)->verifyByRrn($this->tx, 'RRN_INDEXED');

        $this->assertSame(DkStatusState::Paid, $result->state);
        $this->tx->refresh();
        $this->assertSame('approved', $this->tx->status);
    }

    public function test_neither_shape_present_is_treated_as_not_paid(): void
    {
        // No status block at all — must NOT approve.
        $this->mockClient(['something_else' => true]);

        $result = $this->app->make(DkBankQrService::class)->verifyByRrn($this->tx, 'RRN_NEITHER');

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
