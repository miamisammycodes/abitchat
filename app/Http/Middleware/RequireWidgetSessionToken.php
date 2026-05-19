<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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

        try {
            $tenant = $this->tokens->verify($bearer, $origin ?? '', $request->ip() ?? '');
        } catch (InvalidSessionTokenException) {
            return response()->json(['error' => WidgetErrors::SESSION_EXPIRED], 401);
        }

        WidgetAudit::log(WidgetAudit::EVENT_REQUEST, $tenant, $origin, $request);

        $request->attributes->set('widget_tenant', $tenant);

        return $next($request);
    }
}
