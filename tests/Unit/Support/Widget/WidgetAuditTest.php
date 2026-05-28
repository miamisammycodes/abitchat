<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Widget;

use App\Enums\Widget\WidgetAuditEvent;
use App\Models\Tenant;
use App\Support\Widget\WidgetAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WidgetAuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(WidgetAudit::FAILURE_COUNTER_KEY);
    }

    public function test_log_swallows_logging_failure_and_increments_counter(): void
    {
        Log::shouldReceive('channel')->with(WidgetAudit::CHANNEL)->andThrow(new \RuntimeException('boom'));
        Log::shouldReceive('error')->andReturnNull();

        $tenant = new Tenant;
        $tenant->id = 1;
        $request = Request::create('/api/v1/widget/init', 'POST');

        WidgetAudit::log(WidgetAuditEvent::Init, $tenant, 'https://example.com', $request);

        $this->assertSame(1, Cache::get(WidgetAudit::FAILURE_COUNTER_KEY, 0));
    }

    public function test_reject_swallows_logging_failure_and_increments_counter(): void
    {
        Log::shouldReceive('channel')->with(WidgetAudit::CHANNEL)->andThrow(new \RuntimeException('boom'));
        Log::shouldReceive('error')->andReturnNull();

        $request = Request::create('/api/v1/widget/conversation', 'POST');

        WidgetAudit::reject('invalid token', 'https://example.com', $request);

        $this->assertSame(1, Cache::get(WidgetAudit::FAILURE_COUNTER_KEY, 0));
    }

    public function test_ip_hash_throws_when_app_key_empty(): void
    {
        config(['app.key' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_KEY must be set');

        WidgetAudit::ipHash('127.0.0.1');
    }

    public function test_log_swallows_the_real_empty_app_key_failure(): void
    {
        // No log mock: let ipHash() throw for real (the production trigger this
        // guard exists for) and prove the chokepoint absorbs it.
        config(['app.key' => '']);

        $tenant = new Tenant;
        $tenant->id = 1;
        $request = Request::create('/api/v1/widget/init', 'POST');

        WidgetAudit::log(WidgetAuditEvent::Init, $tenant, 'https://example.com', $request);

        $this->assertSame(1, Cache::get(WidgetAudit::FAILURE_COUNTER_KEY, 0));
    }

    public function test_reject_swallows_the_real_empty_app_key_failure(): void
    {
        config(['app.key' => '']);

        $request = Request::create('/api/v1/widget/conversation', 'POST');

        WidgetAudit::reject('invalid token', 'https://example.com', $request);

        $this->assertSame(1, Cache::get(WidgetAudit::FAILURE_COUNTER_KEY, 0));
    }
}
