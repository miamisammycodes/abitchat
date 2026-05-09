<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WidgetApiKeyRotationTest extends TestCase
{
    public function test_cache_forget_runs_after_db_update(): void
    {
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

        // The Tenant::saved hook also forgets tenant:{id}:with_plan — allow it.
        Cache::shouldReceive('forget')
            ->zeroOrMoreTimes()
            ->withAnyArgs()
            ->andReturn(true);

        DB::listen(function ($query) use (&$events) {
            if (
                str_contains(strtolower($query->sql), 'update')
                && str_contains($query->sql, 'tenants')
            ) {
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
