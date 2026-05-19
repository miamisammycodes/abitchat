<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Crawler\RobotsTxtPolicy;
use App\Services\Widget\SessionTokenService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped binding ensures SiteCrawler and SitemapDiscoverer share the same
        // RobotsTxtPolicy instance (and its per-instance cache) within a single
        // request / queue job, without persisting state across jobs in long-running workers.
        $this->app->scoped(RobotsTxtPolicy::class);

        $this->app->singleton(
            SessionTokenService::class,
            fn ($app) => new SessionTokenService(config('app.key'))
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('widget', function (Request $request) {
            // CORS preflight isn't user-initiated and must not consume the user's quota.
            if ($request->isMethod('OPTIONS')) {
                return Limit::none();
            }

            $apiKey = (string) $request->input('api_key', '');
            $ip = (string) $request->ip();
            $key = $apiKey !== '' ? "{$apiKey}:{$ip}" : "no_api_key:{$ip}";

            return Limit::perMinute(20)->by($key);
        });

        RateLimiter::for('dk-rrn-verify', function (Request $request) {
            $transactionId = $request->route('transaction')?->id ?? 'unknown';
            $tenantId = $request->user()?->tenant_id ?? 'unknown';

            return [
                Limit::perHour(5)->by("dk-rrn:tx:{$transactionId}"),
                Limit::perHour(20)->by("dk-rrn:tenant:{$tenantId}"),
            ];
        });
    }
}
