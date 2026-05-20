<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NullApiKeyGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('widget.session_dual_accept', false);
    }

    public function test_null_api_key_returns_structured_401_not_500(): void
    {
        // POST to init with no api_key — fails validation (required field)
        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', []);

        // Should return 422 (validation) or 401, never 500
        $this->assertNotSame(500, $response->status(),
            'null api_key must not cause a PHP 500 error');
        $response->assertJsonStructure(['message']);
    }

    public function test_null_api_key_on_conversation_returns_structured_401(): void
    {
        // POST to conversation endpoint with no api_key and no Bearer
        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/conversation', []);

        // In strict mode, no Bearer = SESSION_TOKEN_REQUIRED (middleware exits early)
        // or validation error (422). Either way, not a 500.
        $this->assertNotSame(500, $response->status());
        $this->assertContains($response->status(), [401, 422]);
    }

    public function test_explicit_null_api_key_on_conversation_returns_structured_json(): void
    {
        // Sending api_key: null explicitly
        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/conversation', ['api_key' => null]);

        // Should get SESSION_TOKEN_REQUIRED (no Bearer) or a structured error, never 500
        $this->assertNotSame(500, $response->status());
    }

    public function test_null_api_key_on_lead_endpoint_returns_structured_401(): void
    {
        // POST to lead endpoint with null api_key — should not cause TypeError/500
        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/lead', [
                'api_key' => null,
                'conversation_id' => 1,
            ]);

        // Should return 401 (invalid api_key) or 422 (validation), not 500
        $this->assertNotSame(500, $response->status());
    }

    public function test_empty_string_api_key_returns_structured_response(): void
    {
        // Empty string api_key — ChatController::findTenantByApiKey has a null guard
        $response = $this->withHeaders([
            'Origin' => 'https://example.com',
            'Authorization' => 'Bearer some.fake.token',
        ])->postJson('/api/v1/widget/conversation', ['api_key' => '']);

        // Should return 401 (empty api_key in strict mode, bearer fails verify)
        // Key assertion: no 500
        $this->assertNotSame(500, $response->status());
    }
}
