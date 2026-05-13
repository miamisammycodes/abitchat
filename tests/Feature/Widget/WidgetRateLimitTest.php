<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WidgetRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_widget_endpoint_rate_limits_at_20_per_minute(): void
    {
        $tenant = Tenant::create([
            'name' => 'RL', 'slug' => 'rl-' . uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        for ($i = 0; $i < 20; $i++) {
            $response = $this->postJson('/api/v1/widget/init', ['api_key' => $tenant->api_key]);
            $this->assertNotSame(429, $response->status(), "Hit {$i} should not be 429");
        }

        $this->postJson('/api/v1/widget/init', ['api_key' => $tenant->api_key])
            ->assertStatus(429);
    }

    public function test_different_tenants_from_same_ip_have_independent_buckets(): void
    {
        $tenantA = Tenant::create(['name' => 'A', 'slug' => 'a-' . uniqid(), 'status' => 'active', 'trial_ends_at' => now()->addDays(14)]);
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b-' . uniqid(), 'status' => 'active', 'trial_ends_at' => now()->addDays(14)]);

        // Burn tenant A's bucket.
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/widget/init', ['api_key' => $tenantA->api_key]);
        }
        $this->postJson('/api/v1/widget/init', ['api_key' => $tenantA->api_key])->assertStatus(429);

        // Tenant B from the same IP should still have a full bucket.
        $responseB = $this->postJson('/api/v1/widget/init', ['api_key' => $tenantB->api_key]);
        $this->assertNotSame(429, $responseB->status(), 'Tenant B bucket must be independent of tenant A.');
    }
}
