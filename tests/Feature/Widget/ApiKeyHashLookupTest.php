<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Exceptions\Widget\InvalidSessionTokenException;
use App\Http\Middleware\CheckUsageLimits;
use App\Http\Middleware\ValidateWidgetDomain;
use App\Models\Tenant;
use App\Services\Widget\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\AuthenticatesWidget;
use Tests\TestCase;

class ApiKeyHashLookupTest extends TestCase
{
    use AuthenticatesWidget;
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuthenticatesWidget();
        $this->tenant = $this->createWidgetTenant();
    }

    public function test_session_token_service_verify_uses_indexed_hash_lookup(): void
    {
        /** @var SessionTokenService $service */
        $service = $this->app->make(SessionTokenService::class);
        $minted = $service->mint($this->tenant, 'https://example.com', '127.0.0.1');

        // Verify returns the correct tenant using the hash-based lookup
        $verified = $service->verify($minted['token'], 'https://example.com', '127.0.0.1');

        $this->assertTrue($verified->is($this->tenant));
    }

    public function test_verify_throws_when_api_key_is_rotated(): void
    {
        /** @var SessionTokenService $service */
        $service = $this->app->make(SessionTokenService::class);
        $minted = $service->mint($this->tenant, 'https://example.com', '127.0.0.1');

        // Rotate the api_key — the hash stored in the token sub will no longer match
        $this->tenant->update(['api_key' => 'rotated-key-'.now()->timestamp]);
        // Clear cache so lookup doesn't return stale tenant
        Cache::forget('tenant:api_key_hash:'.Tenant::hashApiKey($this->tenant->api_key));

        $this->expectException(InvalidSessionTokenException::class);
        $service->verify($minted['token'], 'https://example.com', '127.0.0.1');
    }

    public function test_validate_widget_domain_resolves_tenant_via_api_key_hash(): void
    {
        // Clear cache to ensure a fresh DB lookup
        Cache::forget('tenant:api_key_hash:'.Tenant::hashApiKey($this->tenant->api_key));

        // Make a request to init (which goes through ValidateWidgetDomain)
        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', ['api_key' => $this->tenant->api_key]);

        // Should succeed (tenant found via api_key_hash)
        $response->assertOk()->assertJsonStructure(['session_token']);
    }

    public function test_check_usage_limits_resolves_tenant_via_api_key_hash(): void
    {
        // The init endpoint goes through CheckUsageLimits; verify it resolves correctly
        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', ['api_key' => $this->tenant->api_key]);

        $response->assertOk();
    }

    public function test_chat_controller_finds_tenant_via_api_key_hash(): void
    {
        // Clear the api_key cache to force a fresh DB lookup
        Cache::forget('tenant:api_key_hash:'.Tenant::hashApiKey($this->tenant->api_key));

        $headers = $this->widgetHeaders($this->tenant);
        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertOk()->assertJsonStructure(['conversation_id']);
    }

    public function test_lead_controller_resolves_tenant_via_api_key_hash(): void
    {
        $headers = $this->widgetHeaders($this->tenant);

        // First create a conversation
        $convResp = $this->withHeaders($headers)
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);
        $convResp->assertOk();
        $conversationId = $convResp->json('conversation_id');

        // Now submit a lead capture — goes through LeadController which does a raw api_key_hash lookup
        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/widget/lead', [
                'api_key' => $this->tenant->api_key,
                'conversation_id' => $conversationId,
                'name' => 'Test Lead',
                'email' => 'test.lead@example.com',
            ]);

        $response->assertOk()->assertJsonStructure(['lead_id']);
    }

    public function test_multiple_active_tenants_hash_lookup_returns_correct_one(): void
    {
        /** @var SessionTokenService $service */
        $service = $this->app->make(SessionTokenService::class);

        $tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        $minted = $service->mint($tenantB, 'https://example.com', '127.0.0.1');
        $verified = $service->verify($minted['token'], 'https://example.com', '127.0.0.1');

        $this->assertTrue($verified->is($tenantB));
        $this->assertFalse($verified->is($this->tenant));
    }
}
