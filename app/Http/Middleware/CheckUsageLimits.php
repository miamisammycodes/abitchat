<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\Usage\UsageTracker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CheckUsageLimits
{
    public function __construct(private readonly UsageTracker $tracker) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $type  The type of limit to check (conversations, knowledge_items, leads, tokens)
     */
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $isJson = $request->is('api/v1/widget/*') || $request->wantsJson();

        $tenant = $this->resolveTenant($request);
        if (! $tenant) {
            return response()->json(['error' => 'Unauthorized', 'code' => 'NO_TENANT'], 401);
        }

        if (! $tenant->isActive()) {
            return $this->reject($isJson, 'Account is not active', 'TENANT_INACTIVE', 403);
        }

        if (! $tenant->hasPlan() && ! $tenant->isOnTrial()) {
            return $this->reject(
                $isJson,
                'Your trial has expired. Please subscribe to a plan to continue.',
                'NO_SUBSCRIPTION',
                403,
            );
        }

        $remaining = $this->tracker->remaining($tenant, $type);
        if ($remaining === 0) {
            $messages = [
                'conversations' => 'You have reached your monthly conversation limit.',
                'knowledge_items' => 'You have reached your knowledge items limit.',
                'leads' => 'You have reached your monthly leads limit.',
                'tokens' => 'You have reached your monthly token limit.',
            ];
            $message = ($messages[$type] ?? 'You have reached your usage limit.')
                .' Please upgrade your plan.';

            return $this->reject($isJson, $message, 'LIMIT_REACHED', 403, ['limit_type' => $type]);
        }

        return $next($request);
    }

    private function resolveTenant(Request $request): ?Tenant
    {
        $tenant = $request->user()?->tenant;
        if ($tenant) {
            return $tenant;
        }

        $apiKey = $request->input('api_key');
        if (! $apiKey) {
            return null;
        }

        return Cache::remember(
            "tenant:api_key:{$apiKey}",
            300,
            fn () => Tenant::where('api_key', $apiKey)->first(),
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function reject(bool $isJson, string $message, string $code, int $status, array $extra = []): Response
    {
        if ($isJson) {
            return response()->json([
                'error' => $code === 'LIMIT_REACHED' ? 'Limit reached' : $message,
                'message' => $message,
                'code' => $code,
                ...$extra,
            ], $status);
        }

        return redirect()->route('client.billing.plans')->with('error', $message);
    }
}
