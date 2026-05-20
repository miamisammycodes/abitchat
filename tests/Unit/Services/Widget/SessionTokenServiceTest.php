<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Widget;

use App\Exceptions\Widget\InvalidSessionTokenException;
use App\Models\Tenant;
use App\Services\Widget\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private SessionTokenService $service;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(SessionTokenService::class);
        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function test_mint_returns_token_and_expiry(): void
    {
        $result = $this->service->mint($this->tenant, 'https://example.com', '203.0.113.10');

        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertIsString($result['token']);
        $this->assertIsInt($result['expires_at']);
        $this->assertGreaterThan(time(), $result['expires_at']);
        $this->assertLessThanOrEqual(time() + 1800 + 5, $result['expires_at']);
    }

    public function test_verify_returns_tenant_on_valid_token(): void
    {
        $result = $this->service->mint($this->tenant, 'https://example.com', '203.0.113.10');

        $verified = $this->service->verify($result['token'], 'https://example.com', '203.0.113.10');

        $this->assertTrue($verified->is($this->tenant));
    }

    public function test_verify_rejects_origin_mismatch(): void
    {
        $result = $this->service->mint($this->tenant, 'https://example.com', '203.0.113.10');

        $this->expectException(InvalidSessionTokenException::class);
        $this->service->verify($result['token'], 'https://attacker.com', '203.0.113.10');
    }

    public function test_verify_rejects_ip_mismatch(): void
    {
        $result = $this->service->mint($this->tenant, 'https://example.com', '203.0.113.10');

        $this->expectException(InvalidSessionTokenException::class);
        $this->service->verify($result['token'], 'https://example.com', '198.51.100.20');
    }

    public function test_verify_rejects_tampered_signature(): void
    {
        $result = $this->service->mint($this->tenant, 'https://example.com', '203.0.113.10');
        $tampered = substr($result['token'], 0, -4).'XXXX';

        $this->expectException(InvalidSessionTokenException::class);
        $this->service->verify($tampered, 'https://example.com', '203.0.113.10');
    }

    public function test_verify_rejects_expired_token(): void
    {
        $this->travel(-31)->minutes();
        $result = $this->service->mint($this->tenant, 'https://example.com', '203.0.113.10');
        $this->travelBack();

        $this->expectException(InvalidSessionTokenException::class);
        $this->service->verify($result['token'], 'https://example.com', '203.0.113.10');
    }

    public function test_verify_rejects_token_for_rotated_api_key(): void
    {
        $result = $this->service->mint($this->tenant, 'https://example.com', '203.0.113.10');

        $this->tenant->update(['api_key' => 'rotated-key-'.now()->timestamp]);

        $this->expectException(InvalidSessionTokenException::class);
        $this->service->verify($result['token'], 'https://example.com', '203.0.113.10');
    }

    public function test_verify_rejects_malformed_token(): void
    {
        $this->expectException(InvalidSessionTokenException::class);
        $this->service->verify('not.a.jwt', 'https://example.com', '203.0.113.10');
    }

    public function test_verify_rejects_token_with_wrong_issuer(): void
    {
        config()->set('app.url', 'https://prod.example.com');
        $minted = $this->service->mint($this->tenant, 'https://example.com', '127.0.0.1');

        config()->set('app.url', 'https://different.example.com');

        $this->expectException(InvalidSessionTokenException::class);
        $this->service->verify($minted['token'], 'https://example.com', '127.0.0.1');
    }

    public function test_verify_picks_correct_tenant_among_multiple_active_tenants(): void
    {
        $tenantA = Tenant::create([
            'name' => 'A', 'slug' => 'a', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
        $tenantB = Tenant::create([
            'name' => 'B', 'slug' => 'b', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $minted = $this->service->mint($tenantB, 'https://example.com', '127.0.0.1');
        $verified = $this->service->verify($minted['token'], 'https://example.com', '127.0.0.1');

        $this->assertTrue($verified->is($tenantB),
            'verify must return tenant B specifically, not whichever active tenant ->first() yields');
    }

    public function test_verify_skips_inactive_tenants(): void
    {
        $minted = $this->service->mint($this->tenant, 'https://example.com', '127.0.0.1');
        $this->tenant->update(['status' => 'inactive']);

        $this->expectException(InvalidSessionTokenException::class);
        $this->service->verify($minted['token'], 'https://example.com', '127.0.0.1');
    }

    public function test_verify_rejects_not_yet_valid_token(): void
    {
        // Travel 60 seconds into the future so the minted token's iat is
        // 60 seconds ahead of "now".
        $this->travel(60)->seconds();
        $result = $this->service->mint($this->tenant, 'https://example.com', '203.0.113.10');

        // Travel back to the original time so verify() sees Carbon::now() as
        // 60 seconds BEFORE the token's iat — triggering BeforeValidException.
        $this->travelBack();

        $this->expectException(InvalidSessionTokenException::class);
        $this->expectExceptionMessageMatches('/not yet valid/i');
        $this->service->verify($result['token'], 'https://example.com', '203.0.113.10');
    }
}
