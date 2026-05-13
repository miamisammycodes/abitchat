<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ValidateWidgetDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin') ?? $request->header('Referer');

        // Real CORS preflight is OPTIONS with Origin + Access-Control-Request-Method
        // and NO body — it can't carry an api_key. Echo the requested origin
        // unconditionally; the actual POST will enforce tenant + origin scoping.
        if ($request->getMethod() === 'OPTIONS' && $request->header('Access-Control-Request-Method')) {
            if ($origin === null) {
                return response('', 204);
            }
            $parts = parse_url($origin);
            $canonical = $parts ? $this->canonicalOrigin($parts) : null;

            return $this->preflightResponse($canonical ?? $origin, $request);
        }

        $apiKey = $request->input('api_key');

        if (! $apiKey) {
            return $next($request); // Let other middleware handle missing key
        }

        $tenant = Cache::remember(
            "tenant:api_key:{$apiKey}",
            300,
            fn () => Tenant::where('api_key', $apiKey)->first(),
        );

        if (! $tenant) {
            return $next($request); // Let other middleware handle invalid key
        }

        if (! $tenant->isActive()) {
            return response()->json([
                'error' => 'Account is not active',
                'code' => 'TENANT_INACTIVE',
            ], 403);
        }

        /** @var array<int, string> $allowedDomains */
        $allowedDomains = $tenant->settings['allowed_domains'] ?? [];

        // No origin: in production we always require it; in non-production we
        // allow it through for curl/Postman/server-side test convenience.
        if (! $origin) {
            if (app()->environment('production')) {
                return response()->json([
                    'error' => 'Origin header required',
                    'code' => 'DOMAIN_NOT_ALLOWED',
                ], 403);
            }

            return $this->withCors($next($request), null);
        }

        // Closed by default: an Origin was sent but the tenant hasn't configured
        // any allowed domains, so reject — they need to add their site explicitly.
        if (empty($allowedDomains)) {
            return response()->json([
                'error' => 'No allowed domains configured for this widget. Add your site domain in widget settings.',
                'code' => 'DOMAIN_NOT_ALLOWED',
            ], 403);
        }

        // Parse the origin domain once; reuse parts for canonical building on match.
        $parts = parse_url($origin);

        if (! $parts || ! isset($parts['host'])) {
            return response()->json([
                'error' => 'Invalid request origin',
                'code' => 'DOMAIN_NOT_ALLOWED',
            ], 403);
        }

        // Check if origin matches any allowed domain
        $originHost = strtolower($parts['host']);

        foreach ($allowedDomains as $domain) {
            $domain = strtolower(trim($domain));

            // Exact match or subdomain match (e.g., "example.com" allows "www.example.com")
            if ($originHost === $domain || str_ends_with($originHost, '.'.$domain)) {
                $canonicalOrigin = $this->canonicalOrigin($parts);

                return $this->withCors($next($request), $canonicalOrigin);
            }
        }

        return response()->json([
            'error' => 'This domain is not authorized to use this widget',
            'code' => 'DOMAIN_NOT_ALLOWED',
        ], 403);
    }

    /**
     * @param  array<string, mixed>  $parts  Result of parse_url() — already parsed, no re-parsing.
     */
    private function canonicalOrigin(array $parts): ?string
    {
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $canonical = "{$parts['scheme']}://{$parts['host']}";
        if (isset($parts['port'])) {
            $canonical .= ":{$parts['port']}";
        }

        return $canonical;
    }

    private function withCors(Response $response, ?string $origin): Response
    {
        if ($origin !== null) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }
        // Vary: Origin set unconditionally so intermediate caches don't serve
        // a no-Origin response to a later cross-origin request (or vice versa).
        $response->headers->set('Vary', 'Origin');

        return $response;
    }

    private function preflightResponse(string $origin, Request $request): Response
    {
        return response('', 204, [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => $request->header('Access-Control-Request-Headers', 'Content-Type, X-Requested-With'),
            'Access-Control-Max-Age' => '600',
            'Vary' => 'Origin',
        ]);
    }
}
