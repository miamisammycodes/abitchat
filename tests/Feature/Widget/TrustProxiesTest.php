<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Behavioral assertions that $request->ip() honors X-Forwarded-For when the
 * remote address is in the trusted-proxies list, and ignores it otherwise.
 *
 * Replaces a prior version that asserted source text of bootstrap/app.php
 * via file_get_contents — that pattern passed for any file containing the
 * literal "trustProxies", including commented-out code. These tests assert
 * the actual runtime behavior that production depends on (WR-05).
 *
 * The bootstrap callback (`bootstrap/app.php`) is invoked every time the
 * HTTP kernel resolves the middleware configuration, which means a
 * per-test `TrustProxies::at()` call is overwritten by the bootstrap's
 * `$middleware->trustProxies(at: env('TRUSTED_PROXIES'))`. We instead
 * drive bootstrap via the TRUSTED_PROXIES env var (the production config
 * surface) and reset the env between tests.
 */
class TrustProxiesTest extends TestCase
{
    use RefreshDatabase;

    private ?string $originalTrustedProxies = null;

    protected function setUp(): void
    {
        $this->originalTrustedProxies = env('TRUSTED_PROXIES');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        // Restore env state and flush static so unrelated tests aren't
        // affected by this test's TRUSTED_PROXIES override.
        if ($this->originalTrustedProxies === null) {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        } else {
            putenv('TRUSTED_PROXIES='.$this->originalTrustedProxies);
            $_ENV['TRUSTED_PROXIES'] = $this->originalTrustedProxies;
            $_SERVER['TRUSTED_PROXIES'] = $this->originalTrustedProxies;
        }
        TrustProxies::flushState();
        parent::tearDown();
    }

    public function test_x_forwarded_for_is_honored_when_remote_addr_is_trusted(): void
    {
        $this->setTrustedProxiesEnv('127.0.0.1');
        $this->refreshApplication();

        Route::get('/__test_ip', fn (Request $r) => ['ip' => $r->ip()]);

        $this->withHeaders(['X-Forwarded-For' => '1.2.3.4'])
            ->get('/__test_ip')
            ->assertOk()
            ->assertExactJson(['ip' => '1.2.3.4']);
    }

    public function test_x_forwarded_for_is_ignored_when_remote_addr_is_not_trusted(): void
    {
        // Empty trusted-proxies list means no proxy is trusted; X-Forwarded-For
        // must be ignored and $request->ip() returns the test runner's REMOTE_ADDR.
        $this->setTrustedProxiesEnv('');
        $this->refreshApplication();

        Route::get('/__test_ip', fn (Request $r) => ['ip' => $r->ip()]);

        $response = $this->withHeaders(['X-Forwarded-For' => '1.2.3.4'])
            ->get('/__test_ip')
            ->assertOk();

        $this->assertNotSame('1.2.3.4', $response->json('ip'));
        $this->assertSame('127.0.0.1', $response->json('ip'));
    }

    private function setTrustedProxiesEnv(string $value): void
    {
        putenv('TRUSTED_PROXIES='.$value);
        $_ENV['TRUSTED_PROXIES'] = $value;
        $_SERVER['TRUSTED_PROXIES'] = $value;
    }
}
