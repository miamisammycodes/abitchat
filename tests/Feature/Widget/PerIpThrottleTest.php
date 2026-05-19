<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PerIpThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => ['example.com']],
        ]);
        config()->set('widget.ip_init_per_min', 3);
        config()->set('widget.ip_daily_cap', 10000);
        config()->set('widget.session_dual_accept', true);
    }

    public function test_init_blocked_after_per_ip_threshold(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $response = $this->withHeaders(['Origin' => 'https://example.com'])
                ->postJson('/api/v1/widget/init', ['api_key' => $this->tenant->api_key]);
            $response->assertOk();
        }

        $blocked = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', ['api_key' => $this->tenant->api_key]);

        $blocked->assertStatus(429)
            ->assertJsonStructure(['error', 'retry_after'])
            ->assertHeader('Retry-After');

        $body = $blocked->json();
        $this->assertSame('rate_limited', $body['error']);
        $this->assertIsInt($body['retry_after']);
        $this->assertGreaterThan(0, $body['retry_after']);
    }
}
