<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function test_db_update_runs_before_cache_forget(): void
    {
        // Guards against the C2 regression where Cache::forget(old) ran before
        // $tenant->update(new). The end-state test above can't catch that —
        // single-threaded execution always produces a clean end state regardless
        // of order, but in production a concurrent widget request between the
        // two steps would re-cache the old key.
        $this->actingAsTenantUser();
        $oldKey = $this->tenant->api_key;

        $events = [];

        Cache::shouldReceive('forget')
            ->once()
            ->with("tenant:api_key:{$oldKey}")
            ->andReturnUsing(function () use (&$events) {
                $events[] = 'forget';
                return true;
            });

        Cache::shouldReceive('forget')
            ->zeroOrMoreTimes()
            ->withAnyArgs()
            ->andReturn(true);

        DB::listen(function ($query) use (&$events) {
            // Match the UPDATE-tenants statement at the start of the SQL.
            // A loose substring search would falsely match SELECTs that
            // include 'updated_at' against the tenants table.
            if (preg_match('/^\s*update\s+["`]?tenants["`]?/i', $query->sql) === 1) {
                $events[] = 'update';
            }
        });

        $this->post(route('client.widget.regenerate-key'))->assertRedirect();

        $this->assertSame(
            ['update', 'forget'],
            $events,
            'DB update must complete before Cache::forget is called'
        );
    }
}
