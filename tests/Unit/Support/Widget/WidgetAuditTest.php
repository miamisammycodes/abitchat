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
    public function test_log_swallows_logging_failure_and_increments_counter(): void
    {
        Cache::forget('widget_audit_failures');

        Log::shouldReceive('channel')->with(WidgetAudit::CHANNEL)->andThrow(new \RuntimeException('boom'));
        Log::shouldReceive('warning')->andReturnNull();

        $tenant = new Tenant;
        $tenant->id = 1;
        $request = Request::create('/api/v1/widget/init', 'POST');

        // Must NOT throw despite the log channel failing.
        WidgetAudit::log(WidgetAuditEvent::Init, $tenant, 'https://example.com', $request);

        $this->assertGreaterThanOrEqual(1, Cache::get('widget_audit_failures', 0));
    }

    public function test_reject_swallows_logging_failure_and_increments_counter(): void
    {
        Cache::forget('widget_audit_failures');

        Log::shouldReceive('channel')->with(WidgetAudit::CHANNEL)->andThrow(new \RuntimeException('boom'));
        Log::shouldReceive('warning')->andReturnNull();

        $request = Request::create('/api/v1/widget/conversation', 'POST');

        // Must NOT throw, and must own the rejected-path log shape internally.
        WidgetAudit::reject('invalid token', 'https://example.com', $request);

        $this->assertGreaterThanOrEqual(1, Cache::get('widget_audit_failures', 0));
    }
}
