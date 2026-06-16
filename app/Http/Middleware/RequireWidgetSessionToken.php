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
use Symfony\Component\HttpFoundation\Response;

class RequireWidgetSessionToken
{
    public function __construct(private readonly SessionTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken() ?: null;
        $dualAccept = (bool) config('widget.session_dual_accept', false);

        // Missing Bearer
        if ($bearer === null) {
            if ($dualAccept) {
                // Telemetry: ops watch this counter; once it sits at zero the
                // strict-mode live flip (dual_accept=false) is safe.
                $origin = CanonicalOrigin::from($request->header('Origin') ?? $request->header('Referer'));
                WidgetAudit::passthrough($origin, $request);

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
            WidgetAudit::reject($e->getMessage(), $origin, $request);

            return response()->json(['error' => WidgetErrors::SESSION_EXPIRED], 401);
        }

        $bodyApiKey = $request->input('api_key');
        if ($bodyApiKey !== null && ! hash_equals((string) $tenant->api_key, (string) $bodyApiKey)) {
            return response()->json(['error' => WidgetErrors::SESSION_EXPIRED], 401);
        }

        WidgetAudit::log(WidgetAuditEvent::Request, $tenant, $origin, $request);

        $request->attributes->set('widget_tenant', $tenant);

        return $next($request);
    }
}
