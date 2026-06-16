<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Tenant;
use App\Support\Widget\WidgetAudit;
use App\Support\Widget\WidgetErrors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
        // Verify the never-throw guarantee by calling WidgetAudit::passthrough() directly
        // with a cache store that will fail on increment. This is the level at which the
        // "telemetry must not break the request" contract lives.
        Cache::forget(WidgetAudit::PASSTHROUGH_COUNTER_KEY);

        // Swap to a null/array store so increment doesn't exist — the try/catch must absorb it.
        // We do this by setting the cache driver to 'null' temporarily.
        config()->set('cache.default', 'array');

        // Build a fake request
        $request = Request::create('/api/v1/widget/conversation', 'POST');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        // Prove WidgetAudit::passthrough never throws even when Cache::increment throws.
        // Use Cache::shouldReceive scoped to just the passthrough call path —
        // since we call the method directly (no full HTTP stack), there are no other
        // cache calls to worry about.
        Cache::shouldReceive('increment')
            ->with(WidgetAudit::PASSTHROUGH_COUNTER_KEY)
            ->andThrow(new \RuntimeException('cache down'));

        // Must not throw
        WidgetAudit::passthrough('https://example.com', $request);

        // The only assertion needed is that we reached this line (no exception).
        $this->assertTrue(true);
    }
}
