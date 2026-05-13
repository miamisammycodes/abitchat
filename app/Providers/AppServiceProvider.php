<?php

declare(strict_types=1);

namespace App\Providers;

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
        //
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
    }
}
