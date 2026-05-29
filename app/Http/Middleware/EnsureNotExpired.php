<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\TenantLifecycle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNotExpired
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->user()?->tenant;

        if ($tenant && $tenant->lifecycleState() === TenantLifecycle::Expired) {
            return redirect()
                ->route('client.billing.plans')
                ->with('error', 'Your free plan has ended. Subscribe to a paid plan to make changes.');
        }

        return $next($request);
    }
}
