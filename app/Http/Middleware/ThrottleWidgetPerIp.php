<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Widget\WidgetErrors;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class ThrottleWidgetPerIp
{
    public function handle(Request $request, Closure $next, string $bucket): Response
    {
        [$limit, $window] = $this->config($bucket);
        $ip = $request->ip();
        if ($ip === null) {
            Log::warning('[Widget] request->ip() null — TrustProxies likely misconfigured', [
                'bucket' => $bucket,
                'forwarded_for' => $request->header('X-Forwarded-For'),
            ]);
        }
        $key = "widget:{$bucket}:".sha1(($ip ?? 'unknown').'|'.$bucket);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'error' => WidgetErrors::RATE_LIMITED,
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        RateLimiter::hit($key, $window);

        return $next($request);
    }

    /**
     * @return array{0: int, 1: int} [maxAttempts, decaySeconds]
     */
    private function config(string $bucket): array
    {
        return match ($bucket) {
            'init' => [(int) config('widget.ip_init_per_min', 10), 60],
            'message' => [(int) config('widget.ip_message_per_min', 30), 60],
            'daily' => [(int) config('widget.ip_daily_cap', 5000), 86_400],
            default => throw new InvalidArgumentException("Unknown widget throttle bucket: {$bucket}"),
        };
    }
}
