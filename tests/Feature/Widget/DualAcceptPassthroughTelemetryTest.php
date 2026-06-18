<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Tenant;
use App\Support\Widget\WidgetAudit;
use App\Support\Widget\WidgetErrors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\AuthenticatesWidget;
use Tests\TestCase;

class DualAcceptPassthroughTelemetryTest extends TestCase
{
    use AuthenticatesWidget;
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuthenticatesWidget(); // forces session_dual_accept=false
        $this->tenant = $this->createWidgetTenant();
    }

    public function test_dual_accept_passthrough_increments_counter(): void
    {
        config()->set('widget.session_dual_accept', true);
        Cache::forget(WidgetAudit::PASSTHROUGH_COUNTER_KEY);

        // Token-less request — dual-accept lets it through with a Deprecation header.
        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertSuccessful();
        $this->assertSame('true', $response->headers->get('Deprecation'));
        $this->assertSame(1, (int) Cache::get(WidgetAudit::PASSTHROUGH_COUNTER_KEY));
    }

    public function test_strict_mode_does_not_increment_passthrough_counter(): void
    {
        // session_dual_accept already false from setUp.
        Cache::forget(WidgetAudit::PASSTHROUGH_COUNTER_KEY);

        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/message', ['api_key' => $this->tenant->api_key]);

        $response->assertStatus(401)
            ->assertJson(['error' => WidgetErrors::SESSION_TOKEN_REQUIRED]);
        $this->assertNull(Cache::get(WidgetAudit::PASSTHROUGH_COUNTER_KEY));
    }

    public function test_bearer_request_does_not_increment_passthrough_counter(): void
    {
        Cache::forget(WidgetAudit::PASSTHROUGH_COUNTER_KEY);
        $headers = $this->widgetHeaders($this->tenant);

        $this->withHeaders($headers)
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key])
            ->assertOk();

        $this->assertNull(Cache::get(WidgetAudit::PASSTHROUGH_COUNTER_KEY));
    }

    public function test_passthrough_telemetry_never_throws_when_cache_fails(): void
    {
        config()->set('widget.session_dual_accept', true);

        // Even if the counter write blows up, the passthrough request must still succeed.
        Cache::shouldReceive('increment')
            ->once()
            ->with(WidgetAudit::PASSTHROUGH_COUNTER_KEY)
            ->andThrow(new \RuntimeException('cache down'));

        // Pass-through catch-all so every other cache call during the HTTP request
        // (tenant lookup in ValidateWidgetDomain, usage check in CheckUsageLimits,
        // tenant caching in ChatController) still works normally.
        Cache::shouldReceive('remember')
            ->zeroOrMoreTimes()
            ->withAnyArgs()
            ->andReturnUsing(fn (string $key, int $ttl, \Closure $callback) => $callback());

        Cache::shouldReceive('get')
            ->zeroOrMoreTimes()
            ->withAnyArgs()
            ->andReturnNull();

        Cache::shouldReceive('put')
            ->zeroOrMoreTimes()
            ->withAnyArgs()
            ->andReturn(true);

        Cache::shouldReceive('forget')
            ->zeroOrMoreTimes()
            ->withAnyArgs()
            ->andReturn(true);

        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertSuccessful();
        $this->assertSame('true', $response->headers->get('Deprecation'));
    }
}
