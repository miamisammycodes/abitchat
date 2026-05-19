<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Widget;

use App\Exceptions\Widget\InvalidSessionTokenException;
use App\Models\Tenant;
use App\Services\Widget\SessionTokenService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTokenOctaneSafetyTest extends TestCase
{
    use RefreshDatabase;

    private SessionTokenService $service;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(SessionTokenService::class);
        $this->tenant = Tenant::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function test_carbon_travel_correctly_expires_token(): void
    {
        // Mint at current time
        $minted = $this->service->mint($this->tenant, 'https://example.com', '127.0.0.1');

        // Travel forward past the TTL (default 1800s = 30min)
        $this->travel(31)->minutes();

        $this->expectException(InvalidSessionTokenException::class);
        $this->expectExceptionMessageMatches('/expired/i');
        $this->service->verify($minted['token'], 'https://example.com', '127.0.0.1');
    }

    public function test_jwt_timestamp_is_null_after_successful_verify(): void
    {
        // JWT::$timestamp must remain null throughout — verify() must not mutate it
        $this->assertNull(JWT::$timestamp, 'JWT::$timestamp must start null');

        $minted = $this->service->mint($this->tenant, 'https://example.com', '127.0.0.1');
        $this->service->verify($minted['token'], 'https://example.com', '127.0.0.1');

        $this->assertNull(JWT::$timestamp,
            'JWT::$timestamp must remain null after a successful verify() — no static mutation');
    }

    public function test_jwt_timestamp_is_null_after_failed_verify(): void
    {
        // JWT::$timestamp must remain null even when verify() throws
        $this->assertNull(JWT::$timestamp, 'JWT::$timestamp must start null');

        $minted = $this->service->mint($this->tenant, 'https://example.com', '127.0.0.1');

        try {
            $this->service->verify($minted['token'], 'https://attacker.com', '127.0.0.1');
        } catch (InvalidSessionTokenException) {
            // expected
        }

        $this->assertNull(JWT::$timestamp,
            'JWT::$timestamp must remain null after a failed verify() — no static mutation');
    }

    public function test_verify_with_genuinely_expired_token_throws_with_expired_message(): void
    {
        // Mint in the past (travel back, mint, come back)
        $this->travel(-31)->minutes();
        $minted = $this->service->mint($this->tenant, 'https://example.com', '127.0.0.1');
        $this->travelBack();

        $this->expectException(InvalidSessionTokenException::class);
        $this->expectExceptionMessageMatches('/expired/i');
        $this->service->verify($minted['token'], 'https://example.com', '127.0.0.1');
    }

    public function test_verify_with_not_yet_valid_token_throws(): void
    {
        // Travel into the future to mint a token with a future iat/nbf
        $this->travel(60)->seconds();
        $minted = $this->service->mint($this->tenant, 'https://example.com', '127.0.0.1');

        // Travel back — token is not yet valid
        $this->travelBack();

        $this->expectException(InvalidSessionTokenException::class);
        $this->service->verify($minted['token'], 'https://example.com', '127.0.0.1');
    }
}
