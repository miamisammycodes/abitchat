<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\RequireWidgetSessionToken;
use App\Models\Tenant;
use App\Services\Widget\SessionTokenService;
use App\Support\Widget\WidgetErrors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class RequireWidgetSessionTokenTest extends TestCase
{
    use RefreshDatabase;

    private RequireWidgetSessionToken $middleware;

    private SessionTokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = $this->app->make(SessionTokenService::class);
        $this->middleware = $this->app->make(RequireWidgetSessionToken::class);
        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function test_passes_with_valid_bearer(): void
    {
        $minted = $this->tokens->mint($this->tenant, 'https://example.com', '127.0.0.1');
        $request = $this->makeRequest("Bearer {$minted['token']}", 'https://example.com');

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($request->attributes->get('widget_tenant')->is($this->tenant));
    }

    public function test_rejects_invalid_bearer_even_during_dual_accept(): void
    {
        config()->set('widget.session_dual_accept', true);
        $request = $this->makeRequest('Bearer not.a.real.jwt', 'https://example.com');

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(WidgetErrors::SESSION_EXPIRED, json_decode($response->getContent(), true)['error']);
    }

    public function test_falls_through_when_missing_bearer_during_dual_accept(): void
    {
        config()->set('widget.session_dual_accept', true);
        $request = $this->makeRequest(null, 'https://example.com');

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('true', $response->headers->get('Deprecation'));
    }

    public function test_requires_bearer_post_dual_accept(): void
    {
        config()->set('widget.session_dual_accept', false);
        $request = $this->makeRequest(null, 'https://example.com');

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(WidgetErrors::SESSION_TOKEN_REQUIRED, json_decode($response->getContent(), true)['error']);
    }

    public function test_treats_bearer_with_empty_token_as_missing(): void
    {
        config()->set('widget.session_dual_accept', false);
        $request = $this->makeRequest('Bearer ', 'https://example.com');

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(WidgetErrors::SESSION_TOKEN_REQUIRED, json_decode($response->getContent(), true)['error']);
    }

    public function test_ip_mismatch_returns_401(): void
    {
        // Mint with a non-127.0.0.1 IP; verify will see request's IP (127.0.0.1 in tests).
        $minted = $this->tokens->mint($this->tenant, 'https://example.com', '203.0.113.99');
        $request = $this->makeRequest("Bearer {$minted['token']}", 'https://example.com');

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_rejects_when_token_tenant_differs_from_body_api_key(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other', 'slug' => 'other', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
        $minted = $this->tokens->mint($this->tenant, 'https://example.com', '127.0.0.1');
        $request = $this->makeRequest("Bearer {$minted['token']}", 'https://example.com');
        $request->merge(['api_key' => $otherTenant->api_key]);  // cross-tenant body

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_rejects_when_origin_and_referer_both_missing(): void
    {
        $minted = $this->tokens->mint($this->tenant, 'https://example.com', '127.0.0.1');
        $request = Request::create('/api/v1/widget/conversation', 'POST');
        $request->headers->set('Authorization', "Bearer {$minted['token']}");
        // No Origin, no Referer

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
    }

    private function makeRequest(?string $authorization, string $origin): Request
    {
        $request = Request::create('/api/v1/widget/conversation', 'POST');
        if ($authorization !== null) {
            $request->headers->set('Authorization', $authorization);
        }
        $request->headers->set('Origin', $origin);

        return $request;
    }
}
