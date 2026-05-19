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

    public function test_init_rejects_when_origin_header_missing(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => ['example.com']],
        ]);

        // No Origin header.
        // In production: ValidateWidgetDomain rejects with 403 before init runs.
        // In testing: ValidateWidgetDomain passes through (curl/Postman convenience),
        // so the new belt-and-suspenders guard in init() rejects with 400.
        $response = $this->postJson('/api/v1/widget/init', ['api_key' => $tenant->api_key]);

        $this->assertContains($response->getStatusCode(), [400, 403]);
    }
}
