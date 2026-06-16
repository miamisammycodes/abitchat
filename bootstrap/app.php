<?php

use App\Http\Middleware\CheckUsageLimits;
use App\Http\Middleware\EnsureDkBankEnabled;
use App\Http\Middleware\EnsureNotExpired;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireSuperAdmin;
use App\Http\Middleware\RequireWidgetSessionToken;
use App\Http\Middleware\ThrottleWidgetPerIp;
use App\Http\Middleware\ValidateWidgetDomain;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust-none default (D-02): production topology is Forge single server
        // (nginx → PHP-FPM/FastCGI) with Cloudflare DNS-only (grey cloud, no proxy).
        // REMOTE_ADDR is already the real client IP. Set TRUSTED_PROXIES to a
        // comma-separated CIDR list only if an HTTP proxy is introduced in future.
        $middleware->trustProxies(
            at: array_filter(
                array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))),
                fn (string $s) => $s !== '',
            ),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        $middleware->alias([
            'block.expired' => EnsureNotExpired::class,
            'check.limits' => CheckUsageLimits::class,
            'dk.enabled' => EnsureDkBankEnabled::class,
            'require.super_admin' => RequireSuperAdmin::class,
            'validate.widget.domain' => ValidateWidgetDomain::class,
            'widget.session_token' => RequireWidgetSessionToken::class,
            'widget.throttle_ip' => ThrottleWidgetPerIp::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
