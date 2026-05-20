<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Tenant;
use App\Support\Widget\WidgetAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\AuthenticatesWidget;
use Tests\TestCase;

class WidgetAuditGuardTest extends TestCase
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

    public function test_audit_failure_is_swallowed_on_approve_path(): void
    {
        // Simulate APP_KEY-derived audit failure by using a mock that throws
        // We test via a request that goes through the approve path of RequireWidgetSessionToken
        $headers = $this->widgetHeaders($this->tenant);

        // Clear any cached counter
        Cache::forget('widget_audit_failures');

        // Mock the Log channel to throw on widget_audit channel
        Log::shouldReceive('channel')
            ->with(WidgetAudit::CHANNEL)
            ->andThrow(new \RuntimeException('Simulated audit log failure'));

        // Also allow other log calls to pass through
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();

        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        // The request should succeed despite the audit failure
        $response->assertOk();
    }

    public function test_audit_failure_increments_cache_counter(): void
    {
        Cache::forget('widget_audit_failures');

        $headers = $this->widgetHeaders($this->tenant);

        // Mock the Log to throw on the widget_audit channel
        Log::shouldReceive('channel')
            ->with(WidgetAudit::CHANNEL)
            ->andThrow(new \RuntimeException('APP_KEY must be set'));

        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();

        $this->withHeaders($headers)
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $this->assertGreaterThanOrEqual(1, Cache::get('widget_audit_failures', 0),
            'widget_audit_failures counter must be incremented on audit failure');
    }

    public function test_audit_failure_on_rejected_path_is_also_swallowed(): void
    {
        // Test the reject path (invalid token)
        Log::shouldReceive('channel')
            ->with(WidgetAudit::CHANNEL)
            ->andThrow(new \RuntimeException('APP_KEY must be set'));

        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();

        $response = $this->withHeaders([
            'Origin' => 'https://example.com',
            'Authorization' => 'Bearer not.a.real.jwt',
        ])->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        // Should return 401, not 500
        $response->assertStatus(401);
    }

    public function test_audit_failure_on_init_path_is_also_swallowed(): void
    {
        Cache::forget('widget_audit_failures');

        Log::shouldReceive('channel')
            ->with(WidgetAudit::CHANNEL)
            ->andThrow(new \RuntimeException('Simulated audit log failure on init path'));

        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('debug')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();
        Log::shouldReceive('info')->andReturnNull();

        $response = $this->withHeaders([
            'Origin' => 'https://example.com',
        ])->postJson('/api/v1/widget/init', ['api_key' => $this->tenant->api_key]);

        // The init request must succeed and return a session_token despite the audit failure.
        // If line 64 of ChatController::init() were unguarded, this would return 500.
        $response->assertOk()->assertJsonStructure(['success', 'config', 'session_token', 'expires_at']);

        $this->assertGreaterThanOrEqual(1, Cache::get('widget_audit_failures', 0),
            'widget_audit_failures counter must be incremented on init-path audit failure');
    }
}
