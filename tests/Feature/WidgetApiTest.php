<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\AuthenticatesWidget;
use Tests\TestCase;

class WidgetApiTest extends TestCase
{
    use AuthenticatesWidget;

    protected Tenant $widgetTenant;

    protected string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->widgetTenant = Tenant::create([
            'name' => 'Widget Test Company',
            'slug' => 'widget-test',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => ['example.com']],
        ]);

        // Tenant creates api_key automatically via boot method
        $this->apiKey = $this->widgetTenant->api_key;

        $this->setUpAuthenticatesWidget();
    }

    public function test_old_api_key_invalidated_immediately_after_regenerate(): void
    {
        $user = User::create([
            'name' => 'Owner',
            'email' => 'owner-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'tenant_id' => $this->widgetTenant->id,
        ]);

        $oldKey = $this->apiKey;
        Cache::put("tenant:api_key:{$oldKey}", $this->widgetTenant, 300);

        $this->actingAs($user)
            ->post('/widget-settings/regenerate-key')
            ->assertRedirect();

        $response = $this->postJson('/api/v1/widget/init', ['api_key' => $oldKey]);

        $response->assertStatus(401)
            ->assertJsonPath('code', 'TENANT_NOT_FOUND');
    }

    public function test_widget_init_requires_api_key(): void
    {
        $response = $this->postJson('/api/v1/widget/init', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('api_key');
    }

    public function test_foreign_origin_rejected_when_allowed_domains_empty(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://attacker.example'])
            ->postJson('/api/v1/widget/init', ['api_key' => $this->apiKey]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'DOMAIN_NOT_ALLOWED');
    }

    public function test_matching_origin_allowed_when_in_allowed_domains(): void
    {
        $this->widgetTenant->update(['settings' => ['allowed_domains' => ['shop.example']]]);

        $response = $this->withHeaders(['Origin' => 'https://shop.example'])
            ->postJson('/api/v1/widget/init', ['api_key' => $this->apiKey]);

        $response->assertStatus(200);
    }

    public function test_widget_validation_errors_return_json_even_without_accept_header(): void
    {
        $response = $this->call(
            'POST',
            '/api/v1/widget/init',
            [],
            [],
            [],
            ['HTTP_CONTENT_TYPE' => 'application/json']
        );

        $response->assertStatus(422);
        $this->assertJson($response->getContent());
    }

    public function test_widget_init_rejects_invalid_api_key(): void
    {
        $response = $this->postJson('/api/v1/widget/init', [
            'api_key' => 'invalid-api-key',
        ]);

        $response->assertStatus(401);
        $response->assertJson(['error' => 'Invalid API key']);
    }

    public function test_widget_init_returns_config_with_valid_api_key(): void
    {
        $response = $this->postJson('/api/v1/widget/init', [
            'api_key' => $this->apiKey,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'config' => [
                'name',
                'welcome_message',
                'primary_color',
            ],
        ]);
        $response->assertJson([
            'success' => true,
            'config' => [
                'name' => 'Widget Test Company',
            ],
        ]);
    }

    public function test_start_conversation_requires_api_key(): void
    {
        $response = $this->withHeaders($this->widgetHeaders($this->widgetTenant))
            ->postJson('/api/v1/widget/conversation', []);

        // Bearer passes session-token middleware; check.limits sees no api_key in body
        $response->assertStatus(401);
        $response->assertJson(['code' => 'NO_TENANT']);
    }

    public function test_start_conversation_creates_new_conversation(): void
    {
        $response = $this->withHeaders($this->widgetHeaders($this->widgetTenant))
            ->postJson('/api/v1/widget/conversation', [
                'api_key' => $this->apiKey,
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'conversation_id',
            'session_id',
        ]);

        $this->assertDatabaseHas('conversations', [
            'tenant_id' => $this->widgetTenant->id,
            'status' => 'active',
        ]);
    }

    public function test_start_conversation_uses_provided_session_id(): void
    {
        $customSessionId = 'custom-session-123';

        $response = $this->withHeaders($this->widgetHeaders($this->widgetTenant))
            ->postJson('/api/v1/widget/conversation', [
                'api_key' => $this->apiKey,
                'session_id' => $customSessionId,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'session_id' => $customSessionId,
        ]);
    }

    public function test_end_conversation_marks_conversation_as_closed(): void
    {
        $conversation = Conversation::create([
            'tenant_id' => $this->widgetTenant->id,
            'session_id' => 'test-session',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->widgetHeaders($this->widgetTenant))
            ->postJson('/api/v1/widget/conversation/end', [
                'api_key' => $this->apiKey,
                'conversation_id' => $conversation->id,
            ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'status' => 'closed',
        ]);
    }

    public function test_cannot_end_other_tenants_conversation(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Company',
            'slug' => 'other-company',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $otherTenant->id,
            'session_id' => 'other-session',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->widgetHeaders($this->widgetTenant))
            ->postJson('/api/v1/widget/conversation/end', [
                'api_key' => $this->apiKey, // Using first tenant's API key
                'conversation_id' => $conversation->id,
            ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Conversation not found']);
    }

    public function test_send_message_requires_valid_conversation(): void
    {
        $response = $this->withHeaders($this->widgetHeaders($this->widgetTenant))
            ->postJson('/api/v1/widget/message', [
                'api_key' => $this->apiKey,
                'conversation_id' => 99999,
                'message' => 'Hello',
            ]);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'Conversation not found']);
    }

    public function test_send_message_requires_message_content(): void
    {
        $conversation = Conversation::create([
            'tenant_id' => $this->widgetTenant->id,
            'session_id' => 'test-session',
            'status' => 'active',
        ]);

        $response = $this->withHeaders($this->widgetHeaders($this->widgetTenant))
            ->postJson('/api/v1/widget/message', [
                'api_key' => $this->apiKey,
                'conversation_id' => $conversation->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('message');
    }

    public function test_message_max_length_is_enforced(): void
    {
        $conversation = Conversation::create([
            'tenant_id' => $this->widgetTenant->id,
            'session_id' => 'test-session',
            'status' => 'active',
        ]);

        $longMessage = str_repeat('a', 2001);

        $response = $this->withHeaders($this->widgetHeaders($this->widgetTenant))
            ->postJson('/api/v1/widget/message', [
                'api_key' => $this->apiKey,
                'conversation_id' => $conversation->id,
                'message' => $longMessage,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('message');
    }
}
