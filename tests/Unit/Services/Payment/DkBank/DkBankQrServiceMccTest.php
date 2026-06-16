<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\DkBank;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Payment\DkBank\DkBankClient;
use App\Services\Payment\DkBank\DkBankQrService;
use Mockery;
use Tests\TestCase;

class DkBankQrServiceMccTest extends TestCase
{
    /**
     * @return array{0: Tenant, 1: Plan}
     */
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

    public function test_start_qr_session_sends_configured_mcc_code(): void
    {
        config()->set('services.dk_bank.beneficiary_account', '110158212197');
        // Set a non-default MCC to prove the configured value (not a hardcode) is sent.
        config()->set('services.dk_bank.mcc_code', '5734');

        [$tenant, $plan] = $this->makeTenantAndPlan();

        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')
            ->once()
            ->with('/v1/generate_qr', Mockery::on(function (array $body): bool {
                return ($body['mcc_code'] ?? null) === '5734';
            }))
            ->andReturn(['response_code' => '0000', 'response_data' => ['image' => 'B64']]);
        $this->app->instance(DkBankClient::class, $mock);

        $session = $this->app->make(DkBankQrService::class)->startQrSession($tenant, $plan);

        // The Mockery::on() closure above already asserts mcc_code; this confirms
        // the session was built from the mocked response (full round-trip verified).
        $this->assertSame('B64', $session->qrImageBase64);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
