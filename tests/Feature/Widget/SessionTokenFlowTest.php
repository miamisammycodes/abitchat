<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Tenant;
use App\Services\Widget\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTokenFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => ['example.com']],
        ]);
    }

    public function test_conversation_endpoint_accepts_valid_bearer(): void
    {
        $service = $this->app->make(SessionTokenService::class);
        $minted = $service->mint($this->tenant, 'https://example.com', '127.0.0.1');

        $response = $this->withHeaders([
            'Origin' => 'https://example.com',
            'Authorization' => "Bearer {$minted['token']}",
        ])->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertSuccessful();
    }

    public function test_conversation_endpoint_rejects_bad_bearer_during_dual_accept(): void
    {
        config()->set('widget.session_dual_accept', true);

        $response = $this->withHeaders([
            'Origin' => 'https://example.com',
            'Authorization' => 'Bearer not.a.real.jwt',
        ])->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertStatus(401)->assertJson(['error' => 'session_expired']);
    }

    public function test_conversation_endpoint_falls_through_without_bearer_during_dual_accept(): void
    {
        config()->set('widget.session_dual_accept', true);

        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertSuccessful();
        $this->assertSame('true', $response->headers->get('Deprecation'));
    }

    public function test_conversation_endpoint_requires_bearer_after_window(): void
    {
        config()->set('widget.session_dual_accept', false);

        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertStatus(401)->assertJson(['error' => 'session_token_required']);
    }

    public function test_init_endpoint_does_not_require_bearer(): void
    {
        config()->set('widget.session_dual_accept', false);

        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', ['api_key' => $this->tenant->api_key]);

        $response->assertOk()->assertJsonStructure(['session_token']);
    }
}
