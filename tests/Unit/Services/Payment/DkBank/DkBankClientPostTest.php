<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\DkBank;

use App\Services\Payment\DkBank\DkBankClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DkBankClientPostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.dk_bank.base_url', 'https://example.test/api/dkpg');
        config()->set('services.dk_bank.api_key', 'TEST');
        config()->set('services.dk_bank.source_app', 'SRC_APP_0201');

        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privateKey);

        $keyPath = storage_path('app/dk_pg_test.pem');
        if (! is_dir(dirname($keyPath))) {
            mkdir(dirname($keyPath), 0700, true);
        }
        file_put_contents($keyPath, $privateKey);
        chmod($keyPath, 0600);
        config()->set('services.dk_bank.private_key_path', $keyPath);

        Cache::flush();
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/dk_pg_test.pem'));
        parent::tearDown();
    }

    public function test_post_signed_sends_all_required_headers(): void
    {
        Http::fake([
            'example.test/*/auth/token' => Http::response(['response_code' => '0000', 'response_data' => ['access_token' => 'TKN', 'expires_in' => 1800]]),
            'example.test/*/generate_qr' => Http::response(['response_code' => '0000', 'response_data' => ['image' => 'BASE64IMG']]),
        ]);

        $client = $this->app->make(DkBankClient::class);
        $client->postSigned('/v1/generate_qr', ['currency' => 'BTN']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'generate_qr')) {
                return true;
            }

            return $request->hasHeader('X-gravitee-api-key', 'TEST')
                && $request->hasHeader('Authorization', 'bearer TKN')
                && $request->hasHeader('source_app', 'SRC_APP_0201')
                && $request->hasHeader('DK-Timestamp')
                && $request->hasHeader('DK-Nonce')
                && str_starts_with($request->header('DK-Signature')[0], 'DKSignature ');
        });
    }

    public function test_post_signed_retries_once_on_5001(): void
    {
        Http::fakeSequence()
            ->push(['response_code' => '0000', 'response_data' => ['access_token' => 'T1', 'expires_in' => 1800]])
            ->push(['response_code' => '5001'])
            ->push(['response_code' => '0000', 'response_data' => ['access_token' => 'T2', 'expires_in' => 1800]])
            ->push(['response_code' => '0000', 'response_data' => ['image' => 'OK']]);

        $client = $this->app->make(DkBankClient::class);
        $result = $client->postSigned('/v1/generate_qr', []);

        $this->assertSame('OK', $result['response_data']['image']);
        Http::assertSentCount(4);
    }

    public function test_post_signed_does_not_retry_twice(): void
    {
        Http::fakeSequence()
            ->push(['response_code' => '0000', 'response_data' => ['access_token' => 'T1', 'expires_in' => 1800]])
            ->push(['response_code' => '5001'])
            ->push(['response_code' => '0000', 'response_data' => ['access_token' => 'T2', 'expires_in' => 1800]])
            ->push(['response_code' => '5001']);

        $this->expectException(\RuntimeException::class);

        $client = $this->app->make(DkBankClient::class);
        $client->postSigned('/v1/generate_qr', []);
    }
}
