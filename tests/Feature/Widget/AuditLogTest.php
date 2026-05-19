<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Tenant;
use App\Services\Widget\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_init_emits_widget_init_audit_log(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => ['example.com']],
        ]);

        $captured = null;
        Log::shouldReceive('channel')->with('widget_audit')->andReturnSelf();
        Log::shouldReceive('info')->once()->andReturnUsing(function ($message, $context) use (&$captured) {
            $captured = ['message' => $message, 'context' => $context];

            return null;
        });
        // Be permissive about debug-level calls from existing code paths
        Log::shouldReceive('debug')->andReturnNull();

        $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', ['api_key' => $tenant->api_key])
            ->assertOk();

        $this->assertNotNull($captured);
        $this->assertSame('widget_init', $captured['message']);
        $this->assertSame($tenant->id, $captured['context']['tenant_id']);
        $this->assertSame('https://example.com', $captured['context']['origin']);
        $this->assertNotEmpty($captured['context']['ip_hash']);
        $this->assertNotSame('127.0.0.1', $captured['context']['ip_hash']);
        $this->assertSame(64, strlen($captured['context']['ip_hash'])); // sha256 hex
        $this->assertSame('api/v1/widget/init', $captured['context']['endpoint']);
        $this->assertSame('POST', $captured['context']['method']);
    }

    public function test_authenticated_request_emits_widget_request_audit_log(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => ['example.com']],
        ]);
        $minted = $this->app->make(SessionTokenService::class)
            ->mint($tenant, 'https://example.com', '127.0.0.1');

        $captured = null;
        Log::shouldReceive('channel')->with('widget_audit')->andReturnSelf();
        Log::shouldReceive('info')->andReturnUsing(function ($message, $context) use (&$captured) {
            // Only capture the widget_request event (skip the init event).
            if ($message === 'widget_request') {
                $captured = ['message' => $message, 'context' => $context];
            }

            return null;
        });
        // Be permissive about debug-level calls from existing code paths
        Log::shouldReceive('debug')->andReturnNull();

        $this->withHeaders([
            'Origin' => 'https://example.com',
            'Authorization' => "Bearer {$minted['token']}",
        ])->postJson('/api/v1/widget/conversation', ['api_key' => $tenant->api_key])
            ->assertSuccessful();

        $this->assertNotNull($captured);
        $this->assertSame('widget_request', $captured['message']);
        $this->assertSame($tenant->id, $captured['context']['tenant_id']);
        $this->assertSame(64, strlen($captured['context']['ip_hash']));
    }
}
