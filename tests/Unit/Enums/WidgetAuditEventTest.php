<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\Widget\WidgetAuditEvent;
use App\Support\Widget\WidgetAudit;
use Tests\TestCase;

class WidgetAuditEventTest extends TestCase
{
    public function test_widget_audit_event_enum_has_init_case(): void
    {
        $this->assertSame('widget_init', WidgetAuditEvent::Init->value);
    }

    public function test_widget_audit_event_enum_has_request_case(): void
    {
        $this->assertSame('widget_request', WidgetAuditEvent::Request->value);
    }

    public function test_widget_audit_event_enum_has_rejected_case(): void
    {
        $this->assertSame('widget_token_rejected', WidgetAuditEvent::Rejected->value);
    }

    public function test_widget_audit_channel_constant_still_exists(): void
    {
        // CHANNEL constant is still needed for Log::channel() calls
        $this->assertSame('widget_audit', WidgetAudit::CHANNEL);
    }

    public function test_widget_audit_event_constants_are_removed(): void
    {
        // The old string constants must no longer exist on WidgetAudit
        $this->assertFalse(
            defined(WidgetAudit::class.'::EVENT_INIT'),
            'WidgetAudit::EVENT_INIT must be removed — use WidgetAuditEvent::Init'
        );
        $this->assertFalse(
            defined(WidgetAudit::class.'::EVENT_REQUEST'),
            'WidgetAudit::EVENT_REQUEST must be removed — use WidgetAuditEvent::Request'
        );
        $this->assertFalse(
            defined(WidgetAudit::class.'::EVENT_REJECTED'),
            'WidgetAudit::EVENT_REJECTED must be removed — use WidgetAuditEvent::Rejected'
        );
    }

    public function test_widget_audit_log_accepts_widget_audit_event_parameter(): void
    {
        // Verify the log() signature accepts WidgetAuditEvent not string
        $reflection = new \ReflectionMethod(WidgetAudit::class, 'log');
        $params = $reflection->getParameters();
        $this->assertCount(4, $params);

        $firstParam = $params[0];
        $type = $firstParam->getType();
        $this->assertNotNull($type);
        $this->assertSame(WidgetAuditEvent::class, (string) $type);
    }
}
