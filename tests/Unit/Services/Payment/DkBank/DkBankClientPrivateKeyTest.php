<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\DkBank;

use App\Services\Payment\DkBank\DkBankClient;
use ReflectionMethod;
use Tests\TestCase;

class DkBankClientPrivateKeyTest extends TestCase
{
    private function invokeGetPrivateKey(): mixed
    {
        $client = $this->app->make(DkBankClient::class);
        $method = new ReflectionMethod($client, 'getPrivateKey');
        $method->setAccessible(true);

        return $method->invoke($client);
    }

    public function test_get_private_key_throws_clear_error_when_file_missing(): void
    {
        config(['services.dk_bank.private_key_path' => '/tmp/dk-missing-'.uniqid().'.pem']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DK Bank private key file unreadable');

        $this->invokeGetPrivateKey();
    }

    public function test_get_private_key_returns_file_contents_when_readable(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'dkpem');
        file_put_contents($path, 'FAKE-PEM-CONTENTS');
        config(['services.dk_bank.private_key_path' => $path]);

        try {
            $result = $this->invokeGetPrivateKey();

            $this->assertSame('FAKE-PEM-CONTENTS', $result);
        } finally {
            unlink($path);
        }
    }
}
