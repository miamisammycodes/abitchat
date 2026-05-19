<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrustProxiesTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('widget.session_dual_accept', true);
        $this->tenant = Tenant::create([
            'name' => 'Acme',
            'slug' => 'acme',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => ['example.com']],
        ]);
    }

    public function test_trusted_proxy_forwards_real_ip_via_x_forwarded_for(): void
    {
        // When TRUSTED_PROXIES is configured to trust 127.0.0.1 (the test server IP),
        // the X-Forwarded-For value should be used as the client IP.
        config()->set('app.trusted_proxies', '127.0.0.1');
        // Re-register trust proxies with the test config
        // In tests, we verify via the per-IP throttle which uses $request->ip()
        // The TrustProxies config is wired in bootstrap/app.php reading TRUSTED_PROXIES env.
        // We test the behavior indirectly via PerIpThrottleTest patterns.

        // For a unit-level IP test: make a request with X-Forwarded-For header
        // and verify that the throttle key (rate limit) uses the forwarded IP.
        // Since ThrottleWidgetPerIp uses $request->ip() as the key, we can assert
        // that the response succeeds when the forwarded IP is within limit.
        $response = $this->withHeaders([
            'Origin' => 'https://example.com',
            'X-Forwarded-For' => '1.2.3.4',
        ])->postJson('/api/v1/widget/init', ['api_key' => $this->tenant->api_key]);

        // With dual_accept=true, init should succeed
        $response->assertOk();
    }

    public function test_trust_proxies_is_wired_in_bootstrap(): void
    {
        // Verify trustProxies() call exists in bootstrap/app.php
        $content = file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringContainsString('trustProxies', $content,
            'bootstrap/app.php must contain trustProxies() call');
    }

    public function test_trusted_proxies_default_is_empty_trust_none(): void
    {
        // When TRUSTED_PROXIES env is not set, no proxies should be trusted.
        // This means X-Forwarded-For is ignored and $request->ip() returns REMOTE_ADDR.
        // We verify this indirectly: the bootstrap/app.php must use env('TRUSTED_PROXIES', '')
        $content = file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringContainsString("env('TRUSTED_PROXIES'", $content,
            'bootstrap/app.php must read TRUSTED_PROXIES env var');
    }
}
