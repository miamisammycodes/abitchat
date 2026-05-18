<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\DkBank;

use App\Services\Payment\DkBank\DkBankClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DkBankClientHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.dk_bank.base_url', 'https://example.test/api/dkpg');
        config()->set('services.dk_bank.api_key', 'TEST_API_KEY');
        config()->set('services.dk_bank.username', 'user');
        config()->set('services.dk_bank.password', 'pass');
        config()->set('services.dk_bank.client_id', 'cid');
        config()->set('services.dk_bank.client_secret', 'csecret');
        config()->set('services.dk_bank.source_app', 'SRC_APP_0201');
        Cache::flush();
    }

    public function test_fetches_token_and_caches_it(): void
    {
        Http::fake([
            'example.test/*/auth/token' => Http::response([
                'response_code' => '0000',
                'response_data' => [
                    'access_token' => 'TOKEN_FIRST',
                    'expires_in' => 1800,
                ],
            ]),
        ]);

        $client = $this->app->make(DkBankClient::class);
        $token1 = $client->accessToken();
        $token2 = $client->accessToken();

        $this->assertSame('TOKEN_FIRST', $token1);
        $this->assertSame('TOKEN_FIRST', $token2);
        Http::assertSentCount(1);
    }

    public function test_token_request_uses_form_urlencoded_and_api_key_header(): void
    {
        Http::fake([
            'example.test/*/auth/token' => Http::response([
                'response_code' => '0000',
                'response_data' => ['access_token' => 'T', 'expires_in' => 1800],
            ]),
        ]);

        $this->app->make(DkBankClient::class)->accessToken();

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-gravitee-api-key', 'TEST_API_KEY')
                && str_contains($request->header('Content-Type')[0], 'application/x-www-form-urlencoded')
                && $request['username'] === 'user'
                && $request['client_id'] === 'cid'
                && $request['grant_type'] === 'password';
        });
    }
}
