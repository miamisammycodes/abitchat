<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Concerns\AuthenticatesWidget;
use Tests\TestCase;

/**
 * Regression net for CR-02: Tenant::saved hook must invalidate the
 * api_key-keyed cache slot for the PREVIOUS api_key on rotation, so any
 * rotation path (controller, console, factory, job, direct model save)
 * makes the old api_key unusable within the cache TTL window.
 *
 * Also covers WR-02: saving hook must clear api_key_hash when api_key
 * is set to null (no orphaned hash that continues to resolve to the tenant).
 */
class TenantSavedHookRotationTest extends TestCase
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

    public function test_direct_model_rotation_invalidates_old_api_key_cache(): void
    {
        $oldApiKey = $this->tenant->api_key;
        $oldCacheKey = 'tenant:api_key_hash:'.Tenant::hashApiKey($oldApiKey);

        // Populate the cache via a real widget request — this exercises the
        // ValidateWidgetDomain Cache::remember path with the OLD api_key.
        $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', ['api_key' => $oldApiKey])
            ->assertOk();

        $this->assertTrue(
            Cache::has($oldCacheKey),
            'Precondition: old api_key must be cached before rotation.'
        );

        // Rotate via direct model update — NOT through the controller.
        // The saved hook must still invalidate the old-key cache slot.
        $this->tenant->update(['api_key' => 'rotated-key-'.Str::random(32)]);

        $this->assertFalse(
            Cache::has($oldCacheKey),
            'CR-02: Tenant::saved hook failed to invalidate the cache slot for the old api_key on direct-model rotation.'
        );
    }

    public function test_rotated_old_api_key_no_longer_authenticates(): void
    {
        $oldApiKey = $this->tenant->api_key;

        // Warm the cache with the old key.
        $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', ['api_key' => $oldApiKey])
            ->assertOk();

        // Rotate directly on the model.
        $this->tenant->update(['api_key' => 'rotated-key-'.Str::random(32)]);

        // Old api_key must fail to authenticate — the cache slot for the
        // old key is gone, so the DB lookup against api_key_hash returns null.
        $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', ['api_key' => $oldApiKey])
            ->assertStatus(401);
    }

    public function test_settings_update_invalidates_current_api_key_cache(): void
    {
        $apiKey = $this->tenant->api_key;
        $cacheKey = 'tenant:api_key_hash:'.Tenant::hashApiKey($apiKey);

        // Warm the cache via a real widget request — example.com is allowed
        // because createWidgetTenant() seeds it in the allowed_domains list.
        $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', ['api_key' => $apiKey])
            ->assertOk();

        $this->assertTrue(
            Cache::has($cacheKey),
            'Precondition: api_key cache slot must be warm before settings update.'
        );

        // Update settings — add a NEW origin to the allowed list. The api_key
        // is unchanged, but the cached tenant carries the OLD settings, so
        // the next request from the new origin would 403 until the cache TTL
        // expires unless saved() invalidates the current-key slot too.
        $this->tenant->update([
            'settings' => [
                'allowed_domains' => ['example.com', 'newdomain.test'],
            ],
        ]);

        $this->assertFalse(
            Cache::has($cacheKey),
            'Tenant::saved hook must invalidate the current api_key cache slot when non-api_key fields change.'
        );

        // Behavior check: a request from the newly-allowed origin must
        // succeed immediately, not wait out the 300s TTL.
        $this->withHeaders(['Origin' => 'https://newdomain.test'])
            ->postJson('/api/v1/widget/init', ['api_key' => $apiKey])
            ->assertOk();
    }

    public function test_creation_does_not_seed_api_key_cache_slot(): void
    {
        // Fresh tenant via factory — the saved hook fires after create, but
        // there's no cache slot to evict yet. Confirm none gets seeded.
        $fresh = Tenant::factory()->create();
        $cacheKey = 'tenant:api_key_hash:'.Tenant::hashApiKey($fresh->api_key);

        $this->assertFalse(
            Cache::has($cacheKey),
            'Tenant creation must not seed the api_key cache slot.'
        );
    }

    public function test_saving_hook_clears_api_key_hash_when_api_key_set_to_null(): void
    {
        // Sanity check: hash starts non-null after creation.
        $this->assertNotNull($this->tenant->api_key_hash);

        // The DB column for api_key is NOT NULL, so a full save() with
        // api_key=null can't be persisted. But the saving hook's invariant
        // still has to hold at the in-memory model layer: when api_key
        // becomes dirty AND its new value is null, api_key_hash must be
        // nulled too — no orphaned hash should ever pair with a null key
        // in any in-memory code path (e.g., admin tooling that constructs
        // a tenant in memory, validation that fires saving, etc.).
        //
        // We attempt the save() and catch the expected DB constraint
        // violation. The saving hook fires BEFORE the DB write, so by the
        // time the exception bubbles up, api_key_hash should already be
        // null on the model.
        $this->tenant->api_key = null;
        try {
            $this->tenant->save();
        } catch (QueryException $e) {
            // Expected — NOT NULL constraint on api_key column.
        }

        $this->assertNull(
            $this->tenant->api_key_hash,
            'WR-02: saving hook left a stale api_key_hash when api_key was nulled.'
        );
    }
}
