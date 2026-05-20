<?php

declare(strict_types=1);

namespace App\Support\Widget;

use App\Enums\Widget\WidgetAuditEvent;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class WidgetAudit
{
    public const CHANNEL = 'widget_audit';

    public static function log(WidgetAuditEvent $event, Tenant $tenant, ?string $origin, Request $request): void
    {
        Log::channel(self::CHANNEL)->info($event->value, [
            'tenant_id' => $tenant->id,
            'origin' => $origin,
            'ip_hash' => self::ipHash($request->ip()),
            'endpoint' => $request->path(),
            'method' => $request->method(),
        ]);
    }

    public static function ipHash(?string $ip): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \RuntimeException('APP_KEY must be set for widget audit IP hashing');
        }

        return hash('sha256', ($ip ?? '').$key);
    }
}
