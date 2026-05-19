<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Tenant;
use App\Services\Widget\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_init_response_includes_verifiable_session_token(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => ['example.com']],
        ]);

        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', ['api_key' => $tenant->api_key]);

        $response->assertOk()->assertJsonStructure(['session_token', 'expires_at']);

        $token = $response->json('session_token');
        $expiresAt = $response->json('expires_at');
        $this->assertIsInt($expiresAt);
        $this->assertGreaterThan(time(), $expiresAt);

        // Token must verify under same Origin + IP
        $service = $this->app->make(SessionTokenService::class);
        $verified = $service->verify($token, 'https://example.com', '127.0.0.1');
        $this->assertTrue($verified->is($tenant));
    }
}
