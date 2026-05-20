<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\AuthenticatesWidget;
use Tests\TestCase;

/**
 * Regression net for CR-01: raw api_key must never appear as a Redis/cache key.
 *
 * Every cache write site that warms a tenant lookup must key the entry on the
 * SHA-256 + APP_KEY hash, not the plaintext api_key. A Redis dump or KEYS scan
 * should never reveal active api_keys.
 */
class CacheKeyHardeningTest extends TestCase
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

    public function test_validate_widget_domain_cache_keys_on_api_key_hash_not_raw_api_key(): void
    {
        $apiKey = $this->tenant->api_key;
        Cache::forget("tenant:api_key:{$apiKey}");
        Cache::forget('tenant:api_key_hash:'.Tenant::hashApiKey($apiKey));

        // Hit a widget endpoint that runs through ValidateWidgetDomain.
        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', ['api_key' => $apiKey]);
        $response->assertOk();

        // Raw-keyed entry must NOT exist anywhere in cache.
        $this->assertFalse(
            Cache::has("tenant:api_key:{$apiKey}"),
            'CR-01: ValidateWidgetDomain wrote raw api_key as cache key — it must key on api_key_hash.'
        );

        // Hash-keyed entry must exist.
        $this->assertTrue(
            Cache::has('tenant:api_key_hash:'.Tenant::hashApiKey($apiKey)),
            'ValidateWidgetDomain failed to populate the hash-keyed cache entry.'
        );
    }

    public function test_chat_controller_cache_keys_on_api_key_hash_not_raw_api_key(): void
    {
        $apiKey = $this->tenant->api_key;
        Cache::forget("tenant:api_key:{$apiKey}");
        Cache::forget('tenant:api_key_hash:'.Tenant::hashApiKey($apiKey));

        $headers = $this->widgetHeaders($this->tenant);
        $response = $this->withHeaders($headers)
            ->postJson('/api/v1/widget/conversation', ['api_key' => $apiKey]);
        $response->assertOk();

        $this->assertFalse(
            Cache::has("tenant:api_key:{$apiKey}"),
            'CR-01: ChatController::findTenantByApiKey wrote raw api_key as cache key.'
        );

        $this->assertTrue(
            Cache::has('tenant:api_key_hash:'.Tenant::hashApiKey($apiKey)),
            'ChatController failed to populate the hash-keyed cache entry.'
        );
    }

    public function test_tenant_hash_api_key_helper_returns_canonical_recipe(): void
    {
        $apiKey = 'fixed-test-key';
        $expected = hash('sha256', $apiKey.config('app.key'));

        $this->assertSame($expected, Tenant::hashApiKey($apiKey));
    }
}
