<?php

declare(strict_types=1);

namespace App\Support\Widget;

use App\Enums\Widget\WidgetAuditEvent;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class WidgetAudit
{
    public const CHANNEL = 'widget_audit';

    public const FAILURE_COUNTER_KEY = 'widget_audit_failures';

    public const PASSTHROUGH_COUNTER_KEY = 'widget_dual_accept_passthrough';

    public static function log(WidgetAuditEvent $event, Tenant $tenant, ?string $origin, Request $request): void
    {
        try {
            Log::channel(self::CHANNEL)->info($event->value, [
                'tenant_id' => $tenant->id,
                'origin' => $origin,
                'ip_hash' => self::ipHash($request->ip()),
                'endpoint' => $request->path(),
                'method' => $request->method(),
            ]);
        } catch (\Throwable $e) {
            self::recordFailure($e);
        }
    }

    public static function reject(string $reason, ?string $origin, Request $request): void
    {
        try {
            Log::channel(self::CHANNEL)->warning(WidgetAuditEvent::Rejected->value, [
                'reason' => $reason,
                'origin' => $origin,
                'ip_hash' => self::ipHash($request->ip()),
                'endpoint' => $request->path(),
            ]);
        } catch (\Throwable $e) {
            self::recordFailure($e);
        }
    }

    /**
     * Records a dual-accept passthrough: a token-less widget write that was let
     * through because session_dual_accept is true. Increments a cache counter
     * and logs to the audit channel so ops can confirm zero passthroughs before
     * the strict-mode live flip. Never throws — telemetry must not break the request.
     */
    public static function passthrough(?string $origin, Request $request): void
    {
        try {
            Cache::increment(self::PASSTHROUGH_COUNTER_KEY);
        } catch (\Throwable) {
        }

        try {
            Log::channel(self::CHANNEL)->warning('widget_dual_accept_passthrough', [
                'origin' => $origin,
                'ip_hash' => self::ipHash($request->ip()),
                'endpoint' => $request->path(),
                'method' => $request->method(),
            ]);
        } catch (\Throwable $e) {
            self::recordFailure($e);
        }
    }

    public static function ipHash(?string $ip): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \RuntimeException('APP_KEY must be set for widget audit IP hashing');
        }

        return hash('sha256', ($ip ?? '').$key);
    }

    private static function recordFailure(\Throwable $e): void
    {
        // The failure recorder must never re-throw, or the "audit never crashes
        // the request" guarantee leaks back out to the callers.
        try {
            Cache::increment(self::FAILURE_COUNTER_KEY);
        } catch (\Throwable) {
        }

        try {
            // error level (not warning): a broken audit log is a lost security
            // signal, so it must surface at a severity prod alerting watches.
            Log::error('[Widget] Audit log failure', ['error' => $e->getMessage()]);
        } catch (\Throwable) {
        }
    }
}
