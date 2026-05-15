<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\DkBank;

use App\Services\Payment\DkBank\DkBankClient;
use Tests\TestCase;

class DkBankClientRequestIdTest extends TestCase
{
    public function test_generate_request_id_is_32_chars(): void
    {
        $id = $this->app->make(DkBankClient::class)->generateRequestId();
        $this->assertSame(32, strlen($id));
    }

    public function test_generate_request_id_has_no_dashes(): void
    {
        $id = $this->app->make(DkBankClient::class)->generateRequestId();
        $this->assertStringNotContainsString('-', $id);
    }

    public function test_generate_request_id_is_within_dk_spec_length(): void
    {
        $id = $this->app->make(DkBankClient::class)->generateRequestId();
        $this->assertGreaterThanOrEqual(10, strlen($id));
        $this->assertLessThanOrEqual(32, strlen($id));
    }

    public function test_generate_request_id_returns_unique_values(): void
    {
        $client = $this->app->make(DkBankClient::class);
        $a = $client->generateRequestId();
        $b = $client->generateRequestId();
        $this->assertNotSame($a, $b);
    }
}
