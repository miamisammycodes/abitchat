<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WidgetApiKeyRotationTest extends TestCase
{
    public function test_old_api_key_cache_is_evicted_and_db_holds_new_key(): void
    {
        $this->actingAsTenantUser();
        $oldKey = $this->tenant->api_key;

        Cache::put("tenant:api_key:{$oldKey}", $this->tenant->fresh(), 300);

        $this->post(route('client.widget.regenerate-key'))->assertRedirect();

        $this->tenant->refresh();
        $this->assertNotSame($oldKey, $this->tenant->api_key);
        $this->assertNull(Cache::get("tenant:api_key:{$oldKey}"));
    }
}
