<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Widget\InvalidSessionTokenException;
use App\Services\Widget\SessionTokenService;
use App\Support\Http\CanonicalOrigin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RequireWidgetSessionToken
{
    public function __construct(private readonly SessionTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $this->extractBearer($request);
        $dualAccept = (bool) config('widget.session_dual_accept', true);

        // Missing Bearer
        if ($bearer === null) {
            if ($dualAccept) {
                $response = $next($request);
                $response->headers->set('Deprecation', 'true');

                return $response;
            }

            return response()->json(['error' => 'session_token_required'], 401);
        }

        // Bearer present — must verify, regardless of dual-accept
        $origin = CanonicalOrigin::from($request->header('Origin') ?? $request->header('Referer'));

        try {
            $tenant = $this->tokens->verify($bearer, $origin ?? '', $request->ip() ?? '');
        } catch (InvalidSessionTokenException) {
            return response()->json(['error' => 'session_expired'], 401);
        }

        Log::channel('widget_audit')->info('widget_request', [
            'tenant_id' => $tenant->id,
            'origin' => $origin,
            'ip_hash' => hash('sha256', ($request->ip() ?? '').config('app.key')),
            'endpoint' => $request->path(),
            'method' => $request->method(),
        ]);

        $request->attributes->set('widget_tenant', $tenant);

        return $next($request);
    }

    private function extractBearer(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if ($header === null) {
            return null;
        }

        if (! str_starts_with(strtolower($header), 'bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }
}
