<?php

declare(strict_types=1);

namespace Tests\Feature\Client\Billing;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Payment\DkBank\DkBankClient;
use Mockery;
use Tests\TestCase;

class DkBankQrControllerTest extends TestCase
{
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.dk_bank.enabled', true);
        config()->set('services.dk_bank.beneficiary_account', '110158212197');

        $this->plan = Plan::create([
            'name' => 'Pro', 'slug' => 'pro', 'description' => 'd', 'price' => 1000,
            'billing_period' => 'yearly', 'conversations_limit' => 100,
            'messages_per_conversation' => 10, 'knowledge_items_limit' => 10,
            'tokens_limit' => 1000, 'leads_limit' => 100,
            'is_active' => true, 'sort_order' => 1,
        ]);
    }

    public function test_start_creates_transaction_and_renders_qr_page(): void
    {
        // The Vue page Client/Billing/DkQrSession.vue is built in a later task;
        // disable Inertia's file-existence assertion so we can verify the
        // controller wiring (component name + props) here.
        config()->set('inertia.testing.ensure_pages_exist', false);

        $this->actingAsTenantUser();

        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->once()
            ->andReturn(['response_code' => '0000', 'response_data' => ['image' => 'B64PNG']]);
        $this->app->instance(DkBankClient::class, $mock);

        $response = $this->post(route('client.billing.dk-qr.start', $this->plan));

        $response->assertInertia(fn ($page) => $page
            ->component('Client/Billing/DkQrSession')
            ->where('qrImageBase64', 'B64PNG')
        );
        $this->assertDatabaseHas('transactions', [
            'tenant_id' => $this->tenant->id, 'plan_id' => $this->plan->id,
            'status' => 'awaiting_payment', 'payment_method' => 'dk_qr',
        ]);
    }

    public function test_start_redirects_back_to_subscribe_on_dk_failure(): void
    {
        $this->actingAsTenantUser();

        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->once()
            ->andReturn(['response_code' => '5000', 'response_description' => 'Internal']);
        $this->app->instance(DkBankClient::class, $mock);

        $response = $this->post(route('client.billing.dk-qr.start', $this->plan));

        $response->assertRedirect(route('client.billing.subscribe', $this->plan));
        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('transactions', ['payment_method' => 'dk_qr']);
    }

    public function test_status_returns_paid_when_service_confirms(): void
    {
        $this->actingAsTenantUser();
        $tx = Transaction::create([
            'tenant_id' => $this->tenant->id, 'plan_id' => $this->plan->id,
            'amount' => 1000, 'payment_method' => 'dk_qr',
            'payment_date' => now(), 'status' => 'awaiting_payment',
            'dk_reference_no' => 'DKQR-X-ABCDEF',
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

        $response = $this->get(route('client.billing.dk-qr.status', $tx));

        $response->assertOk()->assertJson(['state' => 'paid']);
    }

    public function test_status_forbidden_for_other_tenants_transaction(): void
    {
        $this->actingAsTenantUser();
        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active', 'trial_ends_at' => now()]);
        $tx = Transaction::create([
            'tenant_id' => $otherTenant->id, 'plan_id' => $this->plan->id,
            'amount' => 1000, 'payment_method' => 'dk_qr',
            'payment_date' => now(), 'status' => 'awaiting_payment',
            'dk_reference_no' => 'DKQR-Z-ZZZZZZ',
        ]);

        $this->get(route('client.billing.dk-qr.status', $tx))->assertForbidden();
    }

    public function test_verify_rrn_returns_paid_on_success(): void
    {
        $this->actingAsTenantUser();
        $tx = Transaction::create([
            'tenant_id' => $this->tenant->id, 'plan_id' => $this->plan->id,
            'amount' => 1000, 'payment_method' => 'dk_qr',
            'payment_date' => now(), 'status' => 'awaiting_payment',
            'dk_reference_no' => 'DKQR-Y-YYYYYY',
        ]);

        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->once()
            ->andReturn(['response_code' => '0000', 'response_data' => [[
                'status' => '0', 'amount' => '1000.00',
                'credit_account' => '110158212197',
                'txn_ts' => now()->addMinute()->toDateTimeString(),
            ]]]);
        $this->app->instance(DkBankClient::class, $mock);

        $response = $this->postJson(route('client.billing.dk-qr.verify-rrn', $tx), [
            'rrn' => 'BANKRRN1234567890',
        ]);

        $response->assertOk()->assertJson(['state' => 'paid']);
        $tx->refresh();
        $this->assertSame('approved', $tx->status);
        $this->assertSame('BANKRRN1234567890', $tx->dk_rrn);
    }

    public function test_verify_rrn_validates_input(): void
    {
        $this->actingAsTenantUser();
        $tx = Transaction::create([
            'tenant_id' => $this->tenant->id, 'plan_id' => $this->plan->id,
            'amount' => 1000, 'payment_method' => 'dk_qr',
            'payment_date' => now(), 'status' => 'awaiting_payment',
            'dk_reference_no' => 'DKQR-V-VVVVVV',
        ]);

        $this->postJson(route('client.billing.dk-qr.verify-rrn', $tx), ['rrn' => 'a'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rrn');
    }

    public function test_verify_rrn_rate_limited_after_5_attempts(): void
    {
        $this->actingAsTenantUser();
        $tx = Transaction::create([
            'tenant_id' => $this->tenant->id, 'plan_id' => $this->plan->id,
            'amount' => 1000, 'payment_method' => 'dk_qr',
            'payment_date' => now(), 'status' => 'awaiting_payment',
            'dk_reference_no' => 'DKQR-RL-LIMITED',
        ]);

        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->andReturn(['response_code' => '3001']);
        $this->app->instance(DkBankClient::class, $mock);

        // 5 attempts succeed (return 200 with failed state since RRN is wrong)
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('client.billing.dk-qr.verify-rrn', $tx), ['rrn' => "ATTEMPT{$i}"])
                ->assertOk()
                ->assertJsonPath('state', 'failed');
        }

        // 6th attempt blocked by the dk-rrn-verify limiter keyed per Transaction
        $this->postJson(route('client.billing.dk-qr.verify-rrn', $tx), ['rrn' => 'OVERLIMIT'])
            ->assertStatus(429);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
