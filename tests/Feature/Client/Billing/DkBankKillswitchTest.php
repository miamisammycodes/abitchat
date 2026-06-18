<?php

declare(strict_types=1);

namespace Tests\Feature\Client\Billing;

use App\Models\Plan;
use App\Models\Transaction;
use App\Services\Payment\DkBank\DkBankClient;
use Mockery;
use Tests\TestCase;

class DkBankKillswitchTest extends TestCase
{
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.dk_bank.beneficiary_account', '110158212197');

        $this->plan = Plan::create([
            'name' => 'Pro', 'slug' => 'pro', 'description' => 'd', 'price' => 1000,
            'billing_period' => 'yearly', 'conversations_limit' => 100,
            'messages_per_conversation' => 10, 'knowledge_items_limit' => 10,
            'tokens_limit' => 1000, 'leads_limit' => 100,
            'is_active' => true, 'sort_order' => 1,
        ]);
    }

    public function test_start_returns_404_when_dk_bank_disabled(): void
    {
        config()->set('services.dk_bank.enabled', false);
        $this->actingAsTenantUser();

        $this->post(route('client.billing.dk-qr.start', $this->plan))->assertNotFound();
        $this->assertDatabaseMissing('transactions', ['payment_method' => 'dk_qr']);
    }

    public function test_show_returns_404_when_dk_bank_disabled(): void
    {
        config()->set('services.dk_bank.enabled', false);
        $this->actingAsTenantUser();
        $tx = $this->makeTx(['dk_reference_no' => 'DKQR-KS-001', 'dk_qr_image_base64' => 'B64']);

        $this->get(route('client.billing.dk-qr.show', $tx))->assertNotFound();
    }

    public function test_status_returns_404_when_dk_bank_disabled(): void
    {
        config()->set('services.dk_bank.enabled', false);
        $this->actingAsTenantUser();
        $tx = $this->makeTx(['dk_reference_no' => 'DKQR-KS-002']);

        $this->get(route('client.billing.dk-qr.status', $tx))->assertNotFound();
    }

    public function test_verify_rrn_returns_404_when_dk_bank_disabled(): void
    {
        config()->set('services.dk_bank.enabled', false);
        $this->actingAsTenantUser();
        $tx = $this->makeTx(['dk_reference_no' => 'DKQR-KS-003']);

        $this->postJson(route('client.billing.dk-qr.verify-rrn', $tx), ['rrn' => 'ABCD1234'])
            ->assertNotFound();
    }

    public function test_start_reaches_controller_when_dk_bank_enabled(): void
    {
        config()->set('services.dk_bank.enabled', true);
        $this->actingAsTenantUser();

        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->once()
            ->andReturn(['response_code' => '0000', 'response_data' => ['image' => 'B64PNG']]);
        $this->app->instance(DkBankClient::class, $mock);

        $tx = null;
        $response = $this->post(route('client.billing.dk-qr.start', $this->plan));

        $tx = Transaction::where('tenant_id', $this->tenant->id)->latest('id')->firstOrFail();
        $response->assertRedirect(route('client.billing.dk-qr.show', $tx));
        $this->assertSame('dk_qr', $tx->payment_method);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTx(array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $this->plan->id,
            'amount' => 1000,
            'payment_method' => 'dk_qr',
            'payment_date' => now(),
            'status' => 'awaiting_payment',
        ], $overrides));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
