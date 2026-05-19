<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Widget\InvalidSessionTokenException;
use App\Services\Widget\SessionTokenService;
use Closure;
use Illuminate\Http\Request;
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
        $origin = $this->canonicalOrigin($request->header('Origin') ?? $request->header('Referer'));

        try {
            $tenant = $this->tokens->verify($bearer, $origin ?? '', $request->ip() ?? '');
        } catch (InvalidSessionTokenException) {
            return response()->json(['error' => 'session_expired'], 401);
        }

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

        return trim(substr($header, 7));
    }

    private function canonicalOrigin(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $parts = parse_url($raw);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
