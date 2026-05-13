<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Tenant;
use Tests\TestCase;

class WidgetCorsTest extends TestCase
{
    private function makeTenant(array $allowedDomains = ['merchant.example.com']): Tenant
    {
        return Tenant::create([
            'name' => 'WidgetCors',
            'slug' => 'wc-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => $allowedDomains],
        ]);
    }

    public function test_preflight_options_from_allowed_origin_returns_204_with_cors_headers(): void
    {
        $tenant = $this->makeTenant();

        $response = $this->call(
            'OPTIONS',
            '/api/v1/widget/init',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://merchant.example.com',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['api_key' => $tenant->api_key])
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('https://merchant.example.com', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('Origin', (string) $response->headers->get('Vary'));
    }

    public function test_post_from_allowed_origin_includes_access_control_allow_origin(): void
    {
        $tenant = $this->makeTenant();

        $response = $this->postJson(
            '/api/v1/widget/init',
            ['api_key' => $tenant->api_key],
            ['Origin' => 'https://merchant.example.com']
        );

        $this->assertNotSame(403, $response->status(), 'Tenant-allowed origin must not be rejected.');
        $this->assertSame('https://merchant.example.com', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_post_from_unallowed_origin_returns_403_and_no_cors_header(): void
    {
        $tenant = $this->makeTenant(['merchant.example.com']);

        $response = $this->postJson(
            '/api/v1/widget/init',
            ['api_key' => $tenant->api_key],
            ['Origin' => 'https://evil.example.com']
        );

        $this->assertSame(403, $response->status());
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_dashboard_api_path_is_not_cors_handled(): void
    {
        $this->assertNotContains('api/*', config('cors.paths'));
    }

    public function test_preflight_options_from_unallowed_origin_returns_403_and_no_cors_header(): void
    {
        $tenant = $this->makeTenant(['merchant.example.com']);

        $response = $this->call(
            'OPTIONS',
            '/api/v1/widget/init',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://evil.example.com',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['api_key' => $tenant->api_key])
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }
}
