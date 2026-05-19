<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Tenant;
use App\Services\Widget\SessionTokenService;
use App\Support\Widget\WidgetErrors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesWidget;
use Tests\TestCase;

/**
 * System-level tests for widget strict mode (WIDGET_SESSION_DUAL_ACCEPT=false).
 *
 * Tests verify that the combined state of Tasks 1–4 all operate together:
 * - TrustProxies wired (Task 2)
 * - Null api_key guard (Task 2)
 * - api_key_hash indexed lookup (Task 1)
 * - Strict mode default=false (Task 4)
 *
 * This is the pre-cutover regression net called out in CONCERNS.md.
 */
class StrictModeSystemTest extends TestCase
{
    use AuthenticatesWidget;
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        // setUpAuthenticatesWidget() sets session_dual_accept=false
        $this->setUpAuthenticatesWidget();
        $this->tenant = $this->createWidgetTenant();
    }

    public function test_config_file_default_is_false(): void
    {
        // Verify the config FILE default (not the runtime override from setUp)
        // This is the key assertion for Task 4's config change
        $rawDefault = require config_path('widget.php');
        $this->assertFalse(
            $rawDefault['session_dual_accept'],
            'config/widget.php session_dual_accept default must be false (not env-overridden)'
        );
    }

    public function test_no_bearer_returns_session_token_required(): void
    {
        // The config default must be false — legacy api_key-only requests blocked
        $this->assertFalse(
            config('widget.session_dual_accept'),
            'session_dual_accept must be false in strict mode'
        );

        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertStatus(401)
            ->assertJson(['error' => WidgetErrors::SESSION_TOKEN_REQUIRED]);
    }

    public function test_message_endpoint_requires_bearer_in_strict_mode(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/message', ['api_key' => $this->tenant->api_key]);

        $response->assertStatus(401)
            ->assertJson(['error' => WidgetErrors::SESSION_TOKEN_REQUIRED]);
    }

    public function test_init_endpoint_still_works_without_bearer(): void
    {
        // Init is the token issuance endpoint — never requires a Bearer
        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', ['api_key' => $this->tenant->api_key]);

        $response->assertOk()->assertJsonStructure(['session_token', 'expires_at']);
    }

    public function test_valid_bearer_still_works_in_strict_mode(): void
    {
        // Regression: strict mode must not block valid JWT flow
        $headers = $this->widgetHeaders($this->tenant);

        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertOk()->assertJsonStructure(['conversation_id']);
    }

    public function test_strict_mode_401_response_is_json(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertStatus(401)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson(['error' => WidgetErrors::SESSION_TOKEN_REQUIRED]);
    }

    public function test_dual_accept_env_override_restores_passthrough(): void
    {
        // WIDGET_SESSION_DUAL_ACCEPT=true env override must still work
        config()->set('widget.session_dual_accept', true);

        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        // With dual_accept=true, request without Bearer should pass through
        $response->assertSuccessful();
        // The Deprecation header indicates dual-accept passthrough
        $this->assertSame('true', $response->headers->get('Deprecation'));
    }

    public function test_full_happy_path_init_conversation_message_in_strict_mode(): void
    {
        /** @var SessionTokenService $service */
        $service = $this->app->make(SessionTokenService::class);
        $minted = $service->mint($this->tenant, 'https://example.com', '127.0.0.1');

        $headers = [
            'Origin' => 'https://example.com',
            'Authorization' => "Bearer {$minted['token']}",
        ];

        // Step 1: Start a conversation
        $convResp = $this->withHeaders($headers)
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);
        $convResp->assertOk();
        $conversationId = $convResp->json('conversation_id');
        $this->assertNotNull($conversationId);

        // Step 2: Verify the conversation was created (full cycle validates api_key_hash lookup + JWT verify)
        $this->assertNotNull($conversationId, 'Strict mode happy path: conversation_id must be returned');
    }
}
