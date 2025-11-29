<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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

        if (!$user || !$user->tenant) {
            return $next($request);
        }

        $tenant = $user->tenant;

        // Check if tenant has an active plan
        if (!$tenant->hasPlan()) {
            if ($request->wantsJson()) {
                return response()->json([
                    'error' => 'No active subscription',
                    'message' => 'Please subscribe to a plan to continue.',
                ], 403);
            }

            return redirect()
                ->route('client.billing.plans')
                ->with('error', 'Please subscribe to a plan to continue.');
        }

        // Check if the specific limit has been reached
        if ($tenant->hasReachedLimit($type)) {
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
                    'limit_type' => $type,
                ], 403);
            }

            return redirect()
                ->route('client.billing.plans')
                ->with('error', $message . ' Please upgrade your plan.');
        }

        return $next($request);
    }
}
