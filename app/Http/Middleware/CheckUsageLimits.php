<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CheckUsageLimits
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $type  The type of limit to check (conversations, knowledge_items, leads, tokens)
     */
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $user = $request->user();
        $tenant = $user?->tenant;

        // Fallback: look up tenant by API key for widget routes
        if (!$tenant && $request->has('api_key')) {
            $tenant = Cache::remember(
                "tenant:api_key:{$request->input('api_key')}",
                300,
                fn () => Tenant::where('api_key', $request->input('api_key'))->first()
            );
        }

        if (!$tenant) {
            return response()->json(['error' => 'Unauthorized', 'code' => 'NO_TENANT'], 401);
        }

        if (!$tenant->hasPlan() && !$tenant->isOnTrial()) {
            if ($request->wantsJson()) {
                return response()->json([
                    'error' => 'No active subscription',
                    'code' => 'NO_SUBSCRIPTION',
                ], 403);
            }
            return redirect()->route('client.billing.plans')
                ->with('error', 'Your trial has expired. Please subscribe to a plan to continue.');
        }

        if ($tenant->isOnTrial()) {
            return $next($request);
        }

        $usage = Cache::remember("tenant:{$tenant->id}:usage", 60, function () use ($tenant) {
            return [
                'conversations' => $tenant->conversations()->whereMonth('created_at', now()->month)->count(),
                'leads' => $tenant->leads()->whereMonth('created_at', now()->month)->count(),
                'tokens' => $tenant->usageRecords()->where('type', 'tokens')
                    ->whereMonth('recorded_date', now()->month)->sum('quantity'),
                'knowledge_items' => $tenant->knowledgeItems()->count(),
            ];
        });

        $plan = $tenant->currentPlan;
        if ($plan) {
            $limits = [
                'conversations' => $plan->conversations_limit,
                'leads' => $plan->leads_limit,
                'tokens' => $plan->tokens_limit,
                'knowledge_items' => $plan->knowledge_items_limit,
            ];

            $limit = $limits[$type] ?? null;
            if ($limit !== null && $limit > 0 && ($usage[$type] ?? 0) >= $limit) {
                $limitMessages = [
                    'conversations' => 'You have reached your monthly conversation limit.',
                    'knowledge_items' => 'You have reached your knowledge items limit.',
                    'leads' => 'You have reached your monthly leads limit.',
                    'tokens' => 'You have reached your monthly token limit.',
                ];
                $message = $limitMessages[$type] ?? 'You have reached your usage limit.';

                if ($request->wantsJson()) {
                    return response()->json([
                        'error' => 'Limit reached',
                        'message' => $message . ' Please upgrade your plan.',
                        'code' => 'LIMIT_REACHED',
                        'limit_type' => $type,
                    ], 403);
                }
                return redirect()->route('client.billing.plans')
                    ->with('error', $message . ' Please upgrade your plan.');
            }
        }

        return $next($request);
    }
}
