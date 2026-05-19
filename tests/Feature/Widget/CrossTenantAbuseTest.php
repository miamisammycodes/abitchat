<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Services\Widget\SessionTokenService;
use App\Support\Widget\WidgetErrors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesWidget;
use Tests\TestCase;

class CrossTenantAbuseTest extends TestCase
{
    use AuthenticatesWidget;
    use RefreshDatabase;

    public function test_token_minted_for_tenant_a_cannot_be_used_with_tenant_b_api_key(): void
    {
        $tenantA = $this->createWidgetTenant(['slug' => 'a']);
        $tenantB = $this->createWidgetTenant(['slug' => 'b']);
        $tokenForA = $this->app->make(SessionTokenService::class)
            ->mint($tenantA, 'https://example.com', '127.0.0.1');

        $response = $this->withHeaders([
            'Origin' => 'https://example.com',
            'Authorization' => "Bearer {$tokenForA['token']}",
        ])->postJson('/api/v1/widget/conversation', ['api_key' => $tenantB->api_key]);

        $response->assertStatus(401)
            ->assertJson(['error' => WidgetErrors::SESSION_EXPIRED]);
    }
}
