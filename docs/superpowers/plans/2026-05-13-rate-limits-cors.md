# Abuse / Rate Limiting + CORS — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close four medium-severity public-surface security findings — wildcard CORS allowing any origin to hit the non-widget API (M10), over-generous widget rate limit with a key that lets one IP drain multiple tenants (M11), missing IP rate limit on `/register` and `/forgot-password` (M-NEW-2), and an unbounded `days` parameter on the analytics endpoint that drives full-table scans (M4).

**Architecture:** Restrict Laravel's CORS middleware to non-widget paths only (dashboard is same-origin, so `cors.paths` drops `api/*` and keeps just `sanctum/csrf-cookie`), and anchor `allowed_origins` on `APP_URL`. Move CORS header emission for widget routes into `ValidateWidgetDomain` itself: after the tenant's `allowed_domains` check approves the request Origin, the middleware emits `Access-Control-Allow-Origin: <that origin>` and handles the preflight `OPTIONS` short-circuit. This is exactly the spec's "widget API stays public via its origin-check path." Tighten the widget `RateLimiter::for` config to **20/min** keyed by `api_key:ip` composite — both required, neither falls back, so abuse from one IP across tenants is bounded per (tenant, IP). Add `throttle:5,1` to register/forgot-password POST routes. Validate `days` as `integer|min:1|max:90` on analytics.

**Tech Stack:** Laravel 13+, PHP 8.3+, Pest/PHPUnit, `fruitcake/laravel-cors` (vendored as `config/cors.php`), Laravel's built-in `RateLimiter` and `throttle` middleware. `Tests\TestCase` uses `RefreshDatabase`; the cache driver defaults to `array` in `phpunit.xml`.

**Spec reference:** `docs/superpowers/specs/2026-05-12-medium-backlog-design.md` — Cluster 3.

**Locked targets (from the spec):**
- Widget rate limit: **20/min**, keyed by `api_key . ':' . ip` composite (no fallback to ip alone)
- `POST /register`: **5/min/IP**
- `POST /forgot-password`: **5/min/IP**
- Analytics `days`: **max 90**
- CORS: drop `'*'`; populate with `config('app.url')`

---

## Task 0: Verification pass against current `main`

- [ ] **Step 1: Verify M10 — CORS allows wildcard**

Run:
```bash
grep -n "allowed_origins\|paths" config/cors.php
```
Expected: `'allowed_origins' => ['*']`. Paths cover `'api/*'` and `'sanctum/csrf-cookie'`.

- [ ] **Step 2: Verify M11 — widget rate limit is 60/min with fallback to ip**

Run:
```bash
grep -n "RateLimiter::for\|perMinute\|api_key" app/Providers/AppServiceProvider.php
```
Expected: `Limit::perMinute(60)->by($request->input('api_key', $request->ip()))` — a fallback to ip alone when api_key is missing.

- [ ] **Step 3: Verify M-NEW-2 — register/forgot-password have no IP throttle**

Run:
```bash
grep -nB1 -A1 "register\|forgot-password" routes/web.php | head -20
```
Expected: routes use `middleware('guest')` only; no `throttle:` middleware. The broker's per-email throttle on `forgot-password` is in the password broker, not at the route level.

- [ ] **Step 4: Verify M4 — analytics days is unbounded**

Run:
```bash
grep -n "days\|validate" app/Http/Controllers/Client/AnalyticsController.php
```
Expected: `$days = (int) $request->input('days', 30)` with no validation; any integer accepted.

- [ ] **Step 5: Proceed to Task 1**

No commit. All four findings live.

---

## Task 1: M10 — Drop CORS wildcard; move widget CORS into the origin-check middleware

**Goal:** Laravel's bundled CORS middleware (`fruitcake/laravel-cors`) supports one allowlist for all matched paths. To keep the widget reachable cross-origin from approved tenant domains *and* restrict every other API path to `APP_URL`, we split the two concerns:

1. `cors.paths` drops `'api/*'`. The dashboard runs on `APP_URL` and calls `/api/v1/*` same-origin (no CORS needed). Sanctum's CSRF endpoint stays in the list for future cross-origin SPA scenarios.
2. `cors.allowed_origins` drops the wildcard and anchors on `APP_URL` (defensive: in case a future `api/*` re-entry slips in).
3. `ValidateWidgetDomain` middleware gains two responsibilities post-validation: emit `Access-Control-Allow-Origin: <request origin>` plus `Vary: Origin` on successful responses, and short-circuit `OPTIONS` preflight with a 204 carrying those headers. This means widget routes never pass through Laravel's CORS middleware — the tenant's `allowed_domains` is the single source of truth for which origins can talk to that tenant's widget.

**Files:**
- Modify: `config/cors.php`
- Modify: `app/Http/Middleware/ValidateWidgetDomain.php`
- Modify: `routes/api.php` (add explicit OPTIONS route in the widget group)
- Test: `tests/Feature/CorsConfigTest.php` (new file)
- Test: `tests/Feature/Widget/WidgetCorsTest.php` (new file)

- [ ] **Step 1: Write the failing config test**

Create `tests/Feature/CorsConfigTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class CorsConfigTest extends TestCase
{
    public function test_cors_does_not_allow_wildcard_origin(): void
    {
        $origins = config('cors.allowed_origins');
        $this->assertIsArray($origins);
        $this->assertNotContains('*', $origins);
    }

    public function test_cors_includes_app_url(): void
    {
        $origins = config('cors.allowed_origins');
        $appUrl = rtrim((string) config('app.url'), '/');
        $this->assertContains($appUrl, $origins);
    }

    public function test_cors_paths_excludes_api_globally(): void
    {
        $paths = config('cors.paths');
        $this->assertNotContains('api/*', $paths, 'Widget CORS is owned by ValidateWidgetDomain; api/* must not be CORS-handled globally.');
    }

    public function test_cors_paths_still_cover_sanctum(): void
    {
        $this->assertContains('sanctum/csrf-cookie', config('cors.paths'));
    }
}
```

- [ ] **Step 2: Write the failing widget-CORS test**

Create `tests/Feature/Widget/WidgetCorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Tenant;
use Tests\TestCase;

class WidgetCorsTest extends TestCase
{
    private function makeTenant(array $allowedDomains = ['merchant.example.com']): Tenant
    {
        return Tenant::create([
            'name' => 'WidgetCors',
            'slug' => 'wc-' . uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => $allowedDomains],
        ]);
    }

    public function test_preflight_options_from_allowed_origin_returns_204_with_cors_headers(): void
    {
        $tenant = $this->makeTenant();

        $response = $this->call(
            'OPTIONS',
            '/api/v1/widget/init',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://merchant.example.com',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['api_key' => $tenant->api_key])
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('https://merchant.example.com', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('Origin', (string) $response->headers->get('Vary'));
    }

    public function test_post_from_allowed_origin_includes_access_control_allow_origin(): void
    {
        $tenant = $this->makeTenant();

        $response = $this->postJson(
            '/api/v1/widget/init',
            ['api_key' => $tenant->api_key],
            ['Origin' => 'https://merchant.example.com']
        );

        $this->assertNotSame(403, $response->status(), 'Tenant-allowed origin must not be rejected.');
        $this->assertSame('https://merchant.example.com', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_post_from_unallowed_origin_returns_403_and_no_cors_header(): void
    {
        $tenant = $this->makeTenant(['merchant.example.com']);

        $response = $this->postJson(
            '/api/v1/widget/init',
            ['api_key' => $tenant->api_key],
            ['Origin' => 'https://evil.example.com']
        );

        $this->assertSame(403, $response->status());
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_dashboard_api_path_is_not_cors_handled(): void
    {
        // /api/v1/* (non-widget) should not be in cors.paths. We verify by
        // hitting a public widget-adjacent endpoint and confirming the CORS
        // headers come from ValidateWidgetDomain, not the global middleware.
        $this->assertNotContains('api/*', config('cors.paths'));
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --filter="CorsConfigTest|WidgetCorsTest"`
Expected: most FAIL — wildcard still present; `api/*` still in paths; `ValidateWidgetDomain` doesn't emit CORS headers; OPTIONS preflight is handled by Laravel's CORS middleware and would either 204 (with current wildcard) or 403 (after step 4) — neither matches the new expected shape.

- [ ] **Step 4: Update `config/cors.php`**

Replace `config/cors.php`:

```php
<?php

return [

    // Widget routes own their own CORS via ValidateWidgetDomain.
    // Dashboard XHRs are same-origin and need no CORS handling.
    'paths' => ['sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        rtrim((string) env('APP_URL', 'http://127.0.0.1:8001'), '/'),
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
```

- [ ] **Step 4b: Register an explicit OPTIONS route inside the widget group**

The widget routes are all `POST`; Laravel's router will reject `OPTIONS` with a 405 before any middleware mounts. Without this step, preflight requests never reach `ValidateWidgetDomain` and the middleware's preflight branch is dead code.

In `routes/api.php`, inside the existing `Route::prefix('v1/widget')->middleware(['throttle:widget', 'validate.widget.domain'])->group(...)` block, add at the bottom of the group:

```php
    Route::options('{any}', fn () => response('', 204))->where('any', '.*');
```

The middleware fires before this closure, detects `isPreflight`, and returns `preflightResponse()` with the right CORS headers. The closure's 204 is a no-op fallback that should never execute under normal flow.

- [ ] **Step 5: Update `ValidateWidgetDomain` to emit CORS headers and handle preflight**

In `app/Http/Middleware/ValidateWidgetDomain.php`, replace the class body. The structure: pull the shared origin-resolution into a helper, then at every "success" exit emit `Access-Control-Allow-Origin: <origin>` + `Vary: Origin`, and handle `OPTIONS` short-circuit at the top.

```php
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

        $isPreflight = $request->getMethod() === 'OPTIONS' && $request->header('Access-Control-Request-Method');

        if (! $apiKey) {
            return $next($request);
        }

        $tenant = Cache::remember(
            "tenant:api_key:{$apiKey}",
            300,
            fn () => Tenant::where('api_key', $apiKey)->first(),
        );

        if (! $tenant) {
            return $next($request);
        }

        if (! $tenant->isActive()) {
            return response()->json([
                'error' => 'Account is not active',
                'code' => 'TENANT_INACTIVE',
            ], 403);
        }

        /** @var array<int, string> $allowedDomains */
        $allowedDomains = $tenant->settings['allowed_domains'] ?? [];

        if (! $origin) {
            if (app()->environment('production')) {
                return response()->json([
                    'error' => 'Origin header required',
                    'code' => 'DOMAIN_NOT_ALLOWED',
                ], 403);
            }

            return $this->withCors($next($request), null);
        }

        if (empty($allowedDomains)) {
            return response()->json([
                'error' => 'No allowed domains configured for this widget. Add your site domain in widget settings.',
                'code' => 'DOMAIN_NOT_ALLOWED',
            ], 403);
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        if (! $originHost) {
            return response()->json([
                'error' => 'Invalid request origin',
                'code' => 'DOMAIN_NOT_ALLOWED',
            ], 403);
        }

        $originHost = strtolower($originHost);

        foreach ($allowedDomains as $domain) {
            $domain = strtolower(trim($domain));
            if ($originHost === $domain || str_ends_with($originHost, '.'.$domain)) {
                if ($isPreflight) {
                    return $this->preflightResponse($origin, $request);
                }
                return $this->withCors($next($request), $origin);
            }
        }

        return response()->json([
            'error' => 'This domain is not authorized to use this widget',
            'code' => 'DOMAIN_NOT_ALLOWED',
        ], 403);
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
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter="CorsConfigTest|WidgetCorsTest"`
Expected: all PASS. Confirm OPTIONS is now registered for widget paths:

```bash
php artisan route:list | grep "v1/widget" | head -5
```
Expected: OPTIONS listed alongside POST entries (added in Step 4b).

- [ ] **Step 7: Browser-confirm cross-origin embedding**

Skip if no third-party-origin staging is available; the Pest tests cover the contract. If feasible, host a minimal HTML page on a port other than 8001 (e.g., `python -m http.server 9000`) that does `fetch('http://127.0.0.1:8001/api/v1/widget/init', {method:'POST', body:JSON.stringify({api_key:'...'}), headers:{'Content-Type':'application/json'}})`, confirm the network panel shows preflight 204 + POST 200 with `Access-Control-Allow-Origin: http://localhost:9000`.

- [ ] **Step 8: Commit**

```bash
git add config/cors.php app/Http/Middleware/ValidateWidgetDomain.php routes/api.php \
        tests/Feature/CorsConfigTest.php tests/Feature/Widget/WidgetCorsTest.php
git commit -m "$(cat <<'EOF'
fix(cors): drop wildcard, route widget CORS through origin-check middleware (M10)

config/cors.php no longer covers api/* paths or wildcards origins.
Dashboard runs same-origin and needs no CORS handling. Widget routes
own their own CORS via ValidateWidgetDomain, which now emits
Access-Control-Allow-Origin (echoing the request origin) on responses
to tenant-approved origins and short-circuits OPTIONS preflight with
the same headers. Single source of truth for which origins can talk
to a widget: the tenant's allowed_domains setting.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 9: Run the full suite**

Run: `php artisan test`
Expected: all green.

---

## Task 2: M-NEW-2 — IP rate limit on register & forgot-password

**Goal:** Five attempts per minute per IP on `POST /register` and `POST /forgot-password`. Stops trial-farming and tenant-row spam from a single egress IP.

**Files:**
- Modify: `routes/web.php` (the two route declarations inside the `guest` group)
- Test: `tests/Feature/Auth/RegisterRateLimitTest.php` (new file)
- Test: `tests/Feature/Auth/ForgotPasswordRateLimitTest.php` (new file)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Auth/RegisterRateLimitTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RegisterRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('register:127.0.0.1');
    }

    public function test_sixth_register_attempt_from_same_ip_is_rate_limited(): void
    {
        // Five blank submissions hit validation (422) but each consumes a slot.
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('register.store'), [])->assertStatus(302);
        }

        $this->post(route('register.store'), [])->assertStatus(429);
    }
}
```

Create `tests/Feature/Auth/ForgotPasswordRateLimitTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ForgotPasswordRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('forgot:127.0.0.1');
    }

    public function test_sixth_forgot_password_attempt_from_same_ip_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('password.email'), ['email' => "user{$i}@example.com"])
                ->assertStatus(302);
        }

        $this->post(route('password.email'), ['email' => 'user6@example.com'])
            ->assertStatus(429);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="RegisterRateLimitTest|ForgotPasswordRateLimitTest"`
Expected: BOTH FAIL — no throttle middleware, all 6 submissions return 302/422, never 429.

- [ ] **Step 3: Add `throttle:5,1` middleware to both routes**

In `routes/web.php`, change the two route declarations inside the `guest` group:

```php
    Route::post('register', [RegisterController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('register.store');

    Route::post('forgot-password', [ForgotPasswordController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.email');
```

(`throttle:5,1` = 5 hits per 1 minute. Default key under the `throttle` alias is the route's domain+method+path+IP, which gives per-IP throttling without further config.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter="RegisterRateLimitTest|ForgotPasswordRateLimitTest"`
Expected: both PASS.

If `RateLimiter::clear` complains about an unknown key in `setUp`, the actual key the throttle middleware uses is derived from the route signature, not the literal `register:127.0.0.1`. Replace the `setUp` body with `RateLimiter::clear(\Illuminate\Routing\Middleware\ThrottleRequests::class)` or simply `\Illuminate\Support\Facades\Cache::flush()` — the array cache driver makes a full flush cheap and unambiguous.

- [ ] **Step 5: Run the full Auth feature suite**

Run: `php artisan test tests/Feature/Auth`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php tests/Feature/Auth/RegisterRateLimitTest.php tests/Feature/Auth/ForgotPasswordRateLimitTest.php
git commit -m "$(cat <<'EOF'
fix(auth): rate-limit register & forgot-password at 5/min/IP (M-NEW-2)

Adds throttle:5,1 to POST /register and POST /forgot-password. Stops
trial-farming and tenant-row spam from a single egress IP. The
forgot-password per-email broker throttle stays in place; this adds
the missing per-IP layer.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: all green.

---

## Task 3: M11 — Tighten widget rate limit to 20/min with composite key

**Goal:** Replace the current `60/min` keyed by `api_key ?? ip` with `20/min` keyed by `api_key . ':' . ip` (composite). Effects:
- Caps abuse from one (tenant, IP) pair at 20/min instead of 60.
- A single IP across multiple tenants gets one bucket per tenant — abuse from a corporate NAT against one tenant can't drain another tenant's quota.
- When api_key is missing the request will fail at `validate.widget.domain` anyway; for those requests, bucket separately under `no_api_key:{ip}` so authenticated and unauthenticated traffic don't share a bucket.

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Widget/WidgetRateLimitTest.php` (new file)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Widget/WidgetRateLimitTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WidgetRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_widget_endpoint_rate_limits_at_20_per_minute(): void
    {
        $tenant = Tenant::create([
            'name' => 'RL', 'slug' => 'rl-' . uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        for ($i = 0; $i < 20; $i++) {
            $response = $this->postJson('/api/v1/widget/init', ['api_key' => $tenant->api_key]);
            $this->assertNotSame(429, $response->status(), "Hit {$i} should not be 429");
        }

        $this->postJson('/api/v1/widget/init', ['api_key' => $tenant->api_key])
            ->assertStatus(429);
    }

    public function test_different_tenants_from_same_ip_have_independent_buckets(): void
    {
        $tenantA = Tenant::create(['name' => 'A', 'slug' => 'a-' . uniqid(), 'status' => 'active', 'trial_ends_at' => now()->addDays(14)]);
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b-' . uniqid(), 'status' => 'active', 'trial_ends_at' => now()->addDays(14)]);

        // Burn tenant A's bucket.
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/widget/init', ['api_key' => $tenantA->api_key]);
        }
        $this->postJson('/api/v1/widget/init', ['api_key' => $tenantA->api_key])->assertStatus(429);

        // Tenant B from the same IP should still have a full bucket.
        $responseB = $this->postJson('/api/v1/widget/init', ['api_key' => $tenantB->api_key]);
        $this->assertNotSame(429, $responseB->status(), 'Tenant B bucket must be independent of tenant A.');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=WidgetRateLimitTest`
Expected: `test_widget_endpoint_rate_limits_at_20_per_minute` may PASS at the 21st-hit boundary because the current ceiling is 60 (so 21 hits don't trigger 429). It will fail at `$this->postJson(...)->assertStatus(429)` — the bucket isn't drained yet. After reducing to 20, the 21st hit returns 429.

`test_different_tenants_from_same_ip_have_independent_buckets` FAILS today because the current fallback-to-ip behavior makes tenants share the IP bucket when both api_keys are present and... actually with both api_keys present, the current code keys by `$request->input('api_key', ...)`, which IS the api_key — so the two tenants today already get independent buckets. The test still fails because at the 60/min ceiling, draining tenant A takes 60 hits, not 20.

To make both tests deterministic regardless of the current ceiling, the first test must run **21 hits then assert 429** under the new code, which is what it does. The second test relies on the **new key composition** to confirm cross-tenant isolation per (tenant, IP).

- [ ] **Step 3: Rewrite the widget `RateLimiter::for` config**

In `app/Providers/AppServiceProvider.php`, replace the `boot` method:

```php
    public function boot(): void
    {
        RateLimiter::for('widget', function (Request $request) {
            $apiKey = (string) $request->input('api_key', '');
            $ip = (string) $request->ip();
            $key = $apiKey !== '' ? "{$apiKey}:{$ip}" : "no_api_key:{$ip}";

            return Limit::perMinute(20)->by($key);
        });
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=WidgetRateLimitTest`
Expected: both PASS.

- [ ] **Step 5: Run the widget feature suite**

Run: `php artisan test tests/Feature/Widget tests/Feature/WidgetApiTest.php tests/Feature/WidgetLeadCaptureTest.php`
Expected: all green. If any pre-existing widget test sent more than 20 requests in a tight loop without flushing the cache, it will hit 429. Fix the test (it's now exercising bug behavior) by calling `Cache::flush()` in its setUp or by lowering the request count.

- [ ] **Step 6: Commit**

```bash
git add app/Providers/AppServiceProvider.php tests/Feature/Widget/WidgetRateLimitTest.php
git commit -m "$(cat <<'EOF'
fix(widget): tighten rate limit to 20/min with composite (api_key, ip) key (M11)

Widget RateLimiter::for config drops the 60/min ceiling to 20/min and
keys by 'api_key:ip' composite. Missing api_key falls into a separate
'no_api_key:ip' bucket so authenticated and unauthenticated traffic
don't share quota. A corporate-NAT IP can no longer drain one
tenant's bucket from many connections, nor can it spread one tenant's
api_key across many IPs to amplify.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: all green.

---

## Task 4: M4 — Cap analytics `days` parameter at 90

**Goal:** Validate `days` as `integer|min:1|max:90`. Today an attacker (or a sleepy customer) can pass `days=10000000` and drive a multi-minute table scan across `messages`, `leads`, and `conversations`.

**Files:**
- Modify: `app/Http/Controllers/Client/AnalyticsController.php`
- Test: `tests/Feature/Client/AnalyticsDaysCapTest.php` (new file)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Client/AnalyticsDaysCapTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use Tests\TestCase;

class AnalyticsDaysCapTest extends TestCase
{
    public function test_days_above_90_returns_validation_error(): void
    {
        $this->actingAsTenantUser();

        $response = $this->from('/analytics')->get('/analytics?days=91');
        $response->assertSessionHasErrors('days');
    }

    public function test_days_at_90_is_accepted(): void
    {
        $this->actingAsTenantUser();

        $response = $this->from('/analytics')->get('/analytics?days=90');
        $response->assertStatus(200);
        $response->assertSessionHasNoErrors();
    }

    public function test_default_days_is_used_when_omitted(): void
    {
        $this->actingAsTenantUser();

        $response = $this->get('/analytics');
        $response->assertStatus(200);
        $response->assertSessionHasNoErrors();
    }

    public function test_days_below_1_returns_validation_error(): void
    {
        $this->actingAsTenantUser();

        $response = $this->from('/analytics')->get('/analytics?days=0');
        $response->assertSessionHasErrors('days');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AnalyticsDaysCapTest`
Expected: `test_days_above_90_returns_validation_error` and `test_days_below_1_returns_validation_error` FAIL — no validation; both requests succeed with 200.

- [ ] **Step 3: Add validation to `AnalyticsController::index`**

In `app/Http/Controllers/Client/AnalyticsController.php`, replace the existing `index` method:

```php
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:90',
        ]);

        $tenant = $this->getTenant($request);
        $days = (int) ($validated['days'] ?? 30);

        return Inertia::render('Client/Analytics/Index', [
            'stats' => $this->analyticsService->getOverviewStats($tenant, $days),
            'conversationsOverTime' => $this->analyticsService->getConversationsOverTime($tenant, $days),
            'leadsOverTime' => $this->analyticsService->getLeadsOverTime($tenant, $days),
            'tokenUsageOverTime' => $this->analyticsService->getTokenUsageOverTime($tenant, $days),
            'leadScoreDistribution' => $this->analyticsService->getLeadScoreDistribution($tenant),
            'leadStatusDistribution' => $this->analyticsService->getLeadStatusDistribution($tenant),
            'conversationsByHour' => $this->analyticsService->getConversationsByHour($tenant, $days),
            'topQuestions' => $this->analyticsService->getTopQuestions($tenant),
            'recentActivity' => $this->analyticsService->getRecentActivity($tenant),
            'selectedDays' => $days,
        ]);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=AnalyticsDaysCapTest`
Expected: all four PASS.

- [ ] **Step 5: Run the wider analytics suite**

Run: `php artisan test --filter=Analytics`
Expected: all green. If a pre-existing analytics test used `days=365` to span a year, it will now fail with a validation error — update it to use `days=90`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Client/AnalyticsController.php tests/Feature/Client/AnalyticsDaysCapTest.php
git commit -m "$(cat <<'EOF'
fix(analytics): cap days parameter at 90 (M4)

Adds 'nullable|integer|min:1|max:90' validation on the analytics
days param. Unbounded values were driving multi-minute table scans
across messages/leads/conversations from a single GET.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: all green.

---

## Task 5: Browser smoke, simplify, PR

- [ ] **Step 1: Boot the dev environment**

```bash
php artisan serve --port=8001
npm run dev
```

- [ ] **Step 2: Browser smoke — CORS allowlist**

Browser-based CORS verification is awkward without a second-origin staging host. The unit tests in `CorsConfigTest` cover the config; for in-browser, open `/dashboard` and confirm it loads and its XHR calls (e.g., `/api/v1/conversations` via the dashboard pages) succeed — the dashboard runs on `APP_URL` so it stays inside the allowlist.

- [ ] **Step 3: Browser smoke — register & forgot-password rate limits**

1. Log out. Visit `/register`. Submit a blank form 5 times — each returns to the form with validation errors.
2. Submit a 6th time. Expect a 429 page (Laravel's default `Too Many Attempts.` view, or `Illuminate\Http\Exceptions\ThrottleRequestsException`).
3. Visit `/forgot-password`. Submit `user1@example.com`, `user2@example.com`, ... 5 times — each returns to the form with the generic flash status.
4. Submit a 6th time. Expect 429.

Wait one minute and confirm the limit clears.

- [ ] **Step 4: Browser smoke — widget rate limit**

```bash
API_KEY=$(php artisan tinker --execute="echo \App\Models\Tenant::where('slug','test-company')->value('api_key');")

for i in $(seq 1 21); do
  curl -s -o /dev/null -w "%{http_code}\n" -X POST http://127.0.0.1:8001/api/v1/widget/init \
    -H 'Content-Type: application/json' \
    -d "{\"api_key\":\"$API_KEY\"}"
done
```
Expected output: 20 successful status codes (200/422/403 depending on payload completeness) followed by `429` on the 21st.

- [ ] **Step 5: Browser smoke — analytics days cap**

1. Log in as the client. Visit `/analytics`.
2. Confirm the page renders. Note the current `selectedDays`.
3. Visit `/analytics?days=91`. Expect a redirect-back with a `days` validation error (Inertia surfaces it in the props `errors` bag).
4. Visit `/analytics?days=90`. Expect a 200 render.

- [ ] **Step 6: `/simplify` pass 1**

Run `/simplify`. Apply substantive fixes. Skip stylistic noise with a one-line reason.

- [ ] **Step 7: `/simplify` pass 2**

Run `/simplify` again. Address any newly-introduced issues. Run:
```bash
php artisan test
```
Expected: all green.

- [ ] **Step 8: Open the PR**

```bash
git push -u origin HEAD
gh pr create --title "fix(security): close cluster-3 rate limit & CORS findings" --body "$(cat <<'EOF'
## Summary

Cluster 3 of the medium-backlog spec — abuse / rate limiting + CORS.

- **M10** — CORS `allowed_origins` no longer contains `*`. Anchored on `APP_URL`. Widget API stays public via its `validate.widget.domain` middleware, not via CORS wildcard.
- **M-NEW-2** — `POST /register` and `POST /forgot-password` gain `throttle:5,1` (5/min/IP). Stops trial-farming and tenant-row spam from one egress IP. Per-email broker throttle on forgot-password stays in place.
- **M11** — Widget rate limit drops 60/min → 20/min, keyed by `api_key:ip` composite (no fallback to ip alone). Missing api_key falls into a separate `no_api_key:ip` bucket.
- **M4** — `?days=` on `/analytics` validated as `integer|min:1|max:90`.

## Deploy steps

1. Merge.
2. No migrations.
3. **Comms to send before deploy** (or in the same window):
   - **Merchants integrating the dashboard API from a third-party origin** — they need to either (a) move to the widget API path if their use case is widget-style, or (b) raise a ticket to add their origin to `cors.allowed_origins`. The dashboard itself is unaffected (same-origin).
   - **Tenants with chatty visitors on shared corporate NAT** — the 60→20/min change may surface as occasional 429s; the widget shows the existing "too many messages" UX. No change required.

## ⚠️ Behavior changes

| Change | Who's affected | Mitigation |
|---|---|---|
| CORS wildcard → APP_URL allowlist | Tenants calling the non-widget API from a third-party origin | One-time origin-add via config (currently env-driven; needs a follow-up for multi-origin scenarios) |
| Widget rate limit 60→20/min keyed by `api_key:ip` | Chatty visitors *and* shared-IP scenarios (corporate NAT) | Existing "too many messages" UX on 429; conservative target |
| Register/forgot-password 5/min/IP | Sign-up storms from a single egress IP | Legitimate users retry-after-1min |
| Analytics `days` capped at 90 | Anyone calling `/analytics?days=>90` | 422 with explicit validation error |

## Test plan

- [ ] `php artisan test` — full suite green
- [ ] `CorsConfigTest` — wildcard absent, APP_URL present, paths preserved
- [ ] `RegisterRateLimitTest` / `ForgotPasswordRateLimitTest` — 6th attempt returns 429
- [ ] `WidgetRateLimitTest` — 21st request returns 429; different tenants from same IP have independent buckets
- [ ] `AnalyticsDaysCapTest` — `days>90` and `days<1` return validation error; default still works
- [ ] Browser smoke per the plan

## Architecture notes

- CORS and widget reachability are now two separate concerns: CORS is the dashboard's same-origin policy, widget-tenant scoping is the `validate.widget.domain` middleware. They don't share configuration.
- Widget rate limit uses an in-application key, not the IP-only default — keep this in mind if migrating to an edge-rate-limit (Cloudflare, etc.) in the future.

## Links

- Spec: `docs/superpowers/specs/2026-05-12-medium-backlog-design.md` (Cluster 3)
- Plan: `docs/superpowers/plans/2026-05-13-rate-limits-cors.md`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 9: Update memory after merge**

Save a memory entry capturing:
- Cluster 3 of medium-backlog closed (M10, M11, M-NEW-2, M4).
- CORS no longer wildcards — adding a new third-party integration origin is a config change, not a code change.
- Widget rate limit is 20/min per (api_key, ip) — keep in mind when load-testing.
- Plans 4–6 to be written when cluster 3's PR merges (per spec cadence).

---

## Out of scope

- Per-conversation rate cap (the spec called this out as "deferred unless `RateLimiter::for` permits it cleanly"). Skip; the 20/min per (api_key, IP) bucket is enough for v1.
- Configurable multi-origin CORS allowlist (e.g., `CORS_ALLOWED_ORIGINS=https://a.com,https://b.com`). Defer — current single-origin covers the dashboard and the spec's stated mitigation path is per-origin ticket-driven.
- Edge rate limiting (Cloudflare WAF, AWS WAF). Out of scope for code; an ops follow-up.
- Penetrating-test verification with adversarial traffic. Pest + browser smoke + production telemetry suffices.
