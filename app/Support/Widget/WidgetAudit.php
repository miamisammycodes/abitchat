<?php

declare(strict_types=1);

namespace App\Support\Widget;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class WidgetAudit
{
    public static function log(string $event, Tenant $tenant, ?string $origin, Request $request): void
    {
        Log::channel('widget_audit')->info($event, [
            'tenant_id' => $tenant->id,
            'origin' => $origin,
            'ip_hash' => self::ipHash($request->ip()),
            'endpoint' => $request->path(),
            'method' => $request->method(),
        ]);
    }

    public static function ipHash(?string $ip): string
    {
        return hash('sha256', ($ip ?? '').config('app.key'));
    }
}
