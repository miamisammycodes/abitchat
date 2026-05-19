<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\DkBank;

use App\Exceptions\Billing\DkQrGenerationException;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Payment\DkBank\DkBankClient;
use App\Services\Payment\DkBank\DkBankQrService;
use Mockery;
use Tests\TestCase;

class DkBankQrServiceStartTest extends TestCase
{
    private function makeTenantAndPlan(): array
    {
        $tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active', 'trial_ends_at' => now(),
        ]);
        $plan = Plan::create([
            'name' => 'Pro', 'slug' => 'pro', 'description' => 'd', 'price' => 1000,
            'billing_period' => 'yearly', 'conversations_limit' => 100, 'messages_per_conversation' => 10,
            'knowledge_items_limit' => 10, 'tokens_limit' => 1000, 'leads_limit' => 100,
            'is_active' => true, 'sort_order' => 1,
        ]);

        return [$tenant, $plan];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.dk_bank.beneficiary_account', '110158212197');
        config()->set('services.dk_bank.mcc_code', '5817');
    }

    public function test_creates_transaction_and_returns_session_dto(): void
    {
        [$tenant, $plan] = $this->makeTenantAndPlan();

        $mockClient = Mockery::mock(DkBankClient::class);
        $mockClient->shouldReceive('generateRequestId')->andReturn('req'.str_repeat('a', 29));
        $mockClient->shouldReceive('postSigned')->once()
            ->andReturn(['response_code' => '0000', 'response_data' => ['image' => 'BASE64IMG']]);
        $this->app->instance(DkBankClient::class, $mockClient);

        $service = $this->app->make(DkBankQrService::class);
        $session = $service->startQrSession($tenant, $plan);

        $this->assertSame('BASE64IMG', $session->qrImageBase64);
        $this->assertSame('BASE64IMG', $session->transaction->dk_qr_image_base64);
        $this->assertSame('awaiting_payment', $session->transaction->status);
        $this->assertSame('dk_qr', $session->transaction->payment_method);
        $this->assertSame($tenant->id, $session->transaction->tenant_id);
        $this->assertSame($plan->id, $session->transaction->plan_id);
        $this->assertNotNull($session->transaction->dk_reference_no);
        $this->assertStringStartsWith('DKQR-', $session->transaction->dk_reference_no);
        $this->assertEquals(1000, $session->transaction->amount);
    }

    public function test_does_not_create_transaction_on_dk_failure(): void
    {
        [$tenant, $plan] = $this->makeTenantAndPlan();

        $mockClient = Mockery::mock(DkBankClient::class);
        $mockClient->shouldReceive('generateRequestId')->andReturn('req'.str_repeat('a', 29));
        $mockClient->shouldReceive('postSigned')->once()
            ->andReturn(['response_code' => '3001', 'response_description' => 'Missing record']);
        $this->app->instance(DkBankClient::class, $mockClient);

        $this->expectException(DkQrGenerationException::class);

        $service = $this->app->make(DkBankQrService::class);
        try {
            $service->startQrSession($tenant, $plan);
        } finally {
            $this->assertDatabaseCount('transactions', 0);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
