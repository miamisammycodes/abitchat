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
        $apiKey = $request->input('api_key');

        if (! $apiKey) {
            return $next($request); // Let other middleware handle missing key
        }

        // Get tenant (use cached version if available)
        $cached = Cache::get("tenant:api_key:{$apiKey}");
        $tenant = $cached instanceof Tenant ? $cached : Tenant::where('api_key', $apiKey)->first();

        if (! $tenant) {
            return $next($request); // Let other middleware handle invalid key
        }

        /** @var array<int, string> $allowedDomains */
        $allowedDomains = $tenant->settings['allowed_domains'] ?? [];

        // If no domains configured, allow all (open access)
        if (empty($allowedDomains)) {
            return $next($request);
        }

        // In production, reject requests without Origin when domains are configured
        // Browsers always send Origin on cross-origin requests, so missing Origin
        // means server-side script trying to bypass domain restriction
        if (! $origin) {
            if (app()->environment('production')) {
                return response()->json([
                    'error' => 'Origin header required',
                    'code' => 'DOMAIN_NOT_ALLOWED',
                ], 403);
            }

            // In dev/local, allow requests without Origin (curl, Postman)
            return $next($request);
        }

        // Parse the origin domain
        $originHost = parse_url($origin, PHP_URL_HOST);

        if (! $originHost) {
            return response()->json([
                'error' => 'Invalid request origin',
                'code' => 'DOMAIN_NOT_ALLOWED',
            ], 403);
        }

        // Check if origin matches any allowed domain
        $originHost = strtolower($originHost);

        foreach ($allowedDomains as $domain) {
            $domain = strtolower(trim($domain));

            // Exact match or subdomain match (e.g., "example.com" allows "www.example.com")
            if ($originHost === $domain || str_ends_with($originHost, '.' . $domain)) {
                return $next($request);
            }
        }

        return response()->json([
            'error' => 'This domain is not authorized to use this widget',
            'code' => 'DOMAIN_NOT_ALLOWED',
        ], 403);
    }
}
