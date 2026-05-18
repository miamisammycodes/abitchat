<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\DkBank;

use App\Services\Payment\DkBank\DkBankClient;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use ReflectionClass;
use Tests\TestCase;

class DkBankClientSignBodyTest extends TestCase
{
    private string $privateKey;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privateKey);
        $this->privateKey = $privateKey;
        $details = openssl_pkey_get_details($res);
        $this->publicKey = $details['key'];
    }

    private function invokeSignBody(array $body, string $timestamp, string $nonce): string
    {
        $client = $this->app->make(DkBankClient::class);
        $reflection = new ReflectionClass($client);

        $method = $reflection->getMethod('signBody');
        $method->setAccessible(true);

        $privateKeyProp = $reflection->getProperty('cachedPrivateKey');
        $privateKeyProp->setAccessible(true);
        $privateKeyProp->setValue($client, $this->privateKey);

        return $method->invoke($client, $body, $timestamp, $nonce);
    }

    public function test_sign_body_returns_decodable_jwt(): void
    {
        $body = ['a' => 1, 'b' => 'hello'];
        $timestamp = '2026-05-15T10:00:00Z';
        $nonce = 'abc123def456';

        $jwt = $this->invokeSignBody($body, $timestamp, $nonce);

        $decoded = JWT::decode($jwt, new Key($this->publicKey, 'RS256'));

        $this->assertSame($timestamp, $decoded->timestamp);
        $this->assertSame($nonce, $decoded->nonce);
        $this->assertSame(base64_encode('{"a":1,"b":"hello"}'), $decoded->data);
    }
}
