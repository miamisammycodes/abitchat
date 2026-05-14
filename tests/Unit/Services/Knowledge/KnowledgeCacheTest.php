<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Models\Tenant;
use App\Services\Knowledge\KnowledgeCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class KnowledgeCacheTest extends TestCase
{
    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'KC Co',
            'slug' => 'kc-co-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function test_get_returns_null_on_miss(): void
    {
        Cache::flush();
        $cache = new KnowledgeCache;
        $tenant = $this->makeTenant();

        $this->assertNull($cache->get($tenant, 'some query'));
    }

    public function test_put_then_get_returns_stored_chunks(): void
    {
        Cache::flush();
        $cache = new KnowledgeCache;
        $tenant = $this->makeTenant();

        $cache->put($tenant, 'pricing', ['chunk one', 'chunk two']);

        $this->assertSame(['chunk one', 'chunk two'], $cache->get($tenant, 'pricing'));
    }

    public function test_get_for_other_tenant_returns_null(): void
    {
        Cache::flush();
        $cache = new KnowledgeCache;
        $a = $this->makeTenant();
        $b = $this->makeTenant();

        $cache->put($a, 'shared query', ['answer for A']);

        $this->assertNull($cache->get($b, 'shared query'));
    }

    public function test_invalidate_busts_existing_entry(): void
    {
        Cache::flush();
        $cache = new KnowledgeCache;
        $tenant = $this->makeTenant();

        $cache->put($tenant, 'pricing', ['cached chunk']);
        $this->assertSame(['cached chunk'], $cache->get($tenant, 'pricing'));

        $cache->invalidate($tenant);

        $this->assertNull($cache->get($tenant, 'pricing'));
    }

    public function test_invalidate_does_not_affect_other_tenants(): void
    {
        Cache::flush();
        $cache = new KnowledgeCache;
        $a = $this->makeTenant();
        $b = $this->makeTenant();

        $cache->put($a, 'q', ['A chunks']);
        $cache->put($b, 'q', ['B chunks']);

        $cache->invalidate($a);

        $this->assertNull($cache->get($a, 'q'));
        $this->assertSame(['B chunks'], $cache->get($b, 'q'));
    }

    public function test_cache_key_uses_version_so_invalidate_does_not_overwrite_pre_existing_keys(): void
    {
        Cache::flush();
        $cache = new KnowledgeCache;
        $tenant = $this->makeTenant();

        $cache->put($tenant, 'q', ['v0 chunks']);
        $cache->invalidate($tenant);
        $cache->put($tenant, 'q', ['v1 chunks']);

        $this->assertSame(['v1 chunks'], $cache->get($tenant, 'q'));
    }
}
