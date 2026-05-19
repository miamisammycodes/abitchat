<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Widget\WidgetAuditEvent;
use App\Exceptions\Widget\InvalidSessionTokenException;
use App\Services\Widget\SessionTokenService;
use App\Support\Http\CanonicalOrigin;
use App\Support\Widget\WidgetAudit;
use App\Support\Widget\WidgetErrors;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequireWidgetSessionToken
{
    public function __construct(private readonly SessionTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken() ?: null;
        $dualAccept = (bool) config('widget.session_dual_accept', true);

        // Missing Bearer
        if ($bearer === null) {
            if ($dualAccept) {
                $response = $next($request);
                $response->headers->set('Deprecation', 'true');

                return $response;
            }

            return response()->json(['error' => WidgetErrors::SESSION_TOKEN_REQUIRED], 401);
        }

        // Bearer present — must verify, regardless of dual-accept
        $origin = CanonicalOrigin::from($request->header('Origin') ?? $request->header('Referer'));

        if ($origin === null || $origin === '' || $request->ip() === null || $request->ip() === '') {
            return response()->json(['error' => WidgetErrors::SESSION_EXPIRED], 401);
        }

        try {
            $tenant = $this->tokens->verify($bearer, $origin, $request->ip());
        } catch (InvalidSessionTokenException $e) {
            // Rejected path audit — wrapped so an APP_KEY-empty RuntimeException
            // from WidgetAudit::ipHash() never crashes the widget response (CONS-22-b).
            try {
                Log::channel(WidgetAudit::CHANNEL)->warning(WidgetAuditEvent::Rejected->value, [
                    'reason' => $e->getMessage(),
                    'origin' => $origin,
                    'ip_hash' => WidgetAudit::ipHash($request->ip()),
                    'endpoint' => $request->path(),
                ]);
            } catch (\Throwable $auditEx) {
                Cache::increment('widget_audit_failures');
                Log::warning('[Widget] Audit log failure (rejected path)', ['error' => $auditEx->getMessage()]);
            }

            return response()->json(['error' => WidgetErrors::SESSION_EXPIRED], 401);
        }

        $bodyApiKey = $request->input('api_key');
        if ($bodyApiKey !== null && $bodyApiKey !== $tenant->api_key) {
            return response()->json(['error' => WidgetErrors::SESSION_EXPIRED], 401);
        }

        // Approved path audit — wrapped so an APP_KEY-empty RuntimeException
        // from WidgetAudit::ipHash() never crashes an approved widget request (CONS-22-b).
        try {
            WidgetAudit::log(WidgetAuditEvent::Request, $tenant, $origin, $request);
        } catch (\Throwable $e) {
            Cache::increment('widget_audit_failures');
            Log::warning('[Widget] Audit log failure (approved path)', ['error' => $e->getMessage()]);
        }

        $request->attributes->set('widget_tenant', $tenant);

        return $next($request);
    }
}
