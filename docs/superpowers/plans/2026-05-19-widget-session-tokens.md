# Widget Session Tokens + Per-IP Rate Limits — Implementation Plan

**Spec**: `docs/superpowers/specs/2026-05-19-widget-session-tokens-design.md`
**Status**: Plan (pre-execution)
**Branch**: `feat/widget-session-tokens` (off `main`)

---

## Pre-context for executor subagents

You are implementing widget API hardening. The spec is at `docs/superpowers/specs/2026-05-19-widget-session-tokens-design.md` — read it before starting. Key constraints:

- TDD: every task = write failing test → run RED → implement → run GREEN → commit. Never skip RED.
- `firebase/php-jwt ^7.0` is already in `vendor/`. The DK probe script (`scripts/dk-probe.php`) imports `use Firebase\JWT\JWT;` — confirm imports follow the same shape.
- `tenants.settings` is a JSON-cast array. Tenant-tunable thresholds live there as `settings.widget_ip_daily_cap`.
- `bootstrap/app.php` has **no** `trustProxies()` call today. We do **not** add one in this PR — it's a deployment-time gate. Tests run in dev where `request()->ip()` is `127.0.0.1`.
- Existing `ValidateWidgetDomain` middleware runs first; new middleware runs after it on the protected widget group. The `/init` endpoint is the only widget route that does NOT require Bearer token.
- All tests live in `tests/Feature/Api/V1/Widget/` (feature) or `tests/Unit/Services/Widget/` (unit).
- Use Pint conventions throughout: `declare(strict_types=1);`, PSR-12 layout, typed properties.
- Per CLAUDE.md: run `php artisan test` (full suite, not filtered) between tasks. Pint after each implementation.

---

## Task 0 — Verify environment + deployment gate

**Goal**: confirm no hidden assumptions before Task 1 commits anything.

**Steps**:

1. Confirm `firebase/php-jwt` API surface:
   ```bash
   cd /Users/sam/Dev/laravel/chatbot
   php -r "require 'vendor/autoload.php'; var_dump(class_exists(\Firebase\JWT\JWT::class), class_exists(\Firebase\JWT\Key::class), class_exists(\Firebase\JWT\ExpiredException::class));"
   ```
   Expect three `bool(true)`. If any are `false`, stop and report.

2. Confirm `tenants.settings` is JSON cast and contains arbitrary keys today:
   ```bash
   php artisan tinker --execute="\$t = \App\Models\Tenant::first(); var_dump(\$t->settings); var_dump(\$t->getCasts()['settings'] ?? null);"
   ```
   Expect `settings` casts to `'array'`. Existing entries may include `allowed_domains`.

3. Confirm `request()->ip()` is `'127.0.0.1'` in test context (so the IP-binding tests can mint with one IP and assert mismatch with another):
   ```bash
   php artisan tinker --execute="echo request()->ip();"
   ```
   Expect `127.0.0.1`. (CLI/HTTP context differs but Pest test ip defaults to this.)

4. Confirm there is no existing config file `config/widget.php`:
   ```bash
   test -f config/widget.php && echo "EXISTS — read it before adding" || echo "absent — OK"
   ```
   Expect `absent — OK`. If it exists, read it and reconcile before Task 5.

5. Confirm `trustProxies()` is NOT called in `bootstrap/app.php` — this is documentation-only:
   ```bash
   grep -n "trustProxies" bootstrap/app.php || echo "absent (expected — prod cutover gate)"
   ```

6. Output a one-paragraph "Task 0 done" report covering the above. **Do not modify any files.** If any check fails, stop the plan and surface the discrepancy.

**No commit.**

---

## Task 1 — `SessionTokenService` (mint + verify) with unit tests

**Goal**: a service that mints HS256 JWTs bound to (api_key_hash, origin, ip) and verifies them with strict claim checks.

**Files to create**:
- `app/Services/Widget/SessionTokenService.php`
- `app/Exceptions/Widget/InvalidSessionTokenException.php`
- `tests/Unit/Services/Widget/SessionTokenServiceTest.php`

**RED**: write the test file first.

`tests/Unit/Services/Widget/SessionTokenServiceTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Widget;

use App\Exceptions\Widget\InvalidSessionTokenException;
use App\Models\Tenant;
use App\Services\Widget\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    private SessionTokenService $service;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(SessionTokenService::class);
        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function test_mint_returns_token_and_expiry(): void
    {
        $result = $this->service->mint($this->tenant, 'https://example.com', '203.0.113.10');

        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertIsString($result['token']);
        $this->assertIsInt($result['expires_at']);
        $this->assertGreaterThan(time(), $result['expires_at']);
        $this->assertLessThanOrEqual(time() + 1800 + 5, $result['expires_at']);
    }

    public function test_verify_returns_tenant_on_valid_token(): void
    {
        $result = $this->service->mint($this->tenant, 'https://example.com', '203.0.113.10');

        $verified = $this->service->verify($result['token'], 'https://example.com', '203.0.113.10');

        $this->assertTrue($verified->is($this->tenant));
    }

    public function test_verify_rejects_origin_mismatch(): void
    {
        $result = $this->service->mint($this->tenant, 'https://example.com', '203.0.113.10');

        $this->expectException(InvalidSessionTokenException::class);
        $this->service->verify($result['token'], 'https://attacker.com', '203.0.113.10');
    }

    public function test_verify_rejects_ip_mismatch(): void
    {
        $result = $this->service->mint($this->tenant, 'https://example.com', '203.0.113.10');

        $this->expectException(InvalidSessionTokenException::class);
        $this->service->verify($result['token'], 'https://example.com', '198.51.100.20');
    }

    public function test_verify_rejects_tampered_signature(): void
    {
        $result = $this->service->mint($this->tenant, 'https://example.com', '203.0.113.10');
        $tampered = substr($result['token'], 0, -4).'XXXX';

        $this->expectException(InvalidSessionTokenException::class);
        $this->service->verify($tampered, 'https://example.com', '203.0.113.10');
    }

    public function test_verify_rejects_expired_token(): void
    {
        $this->travel(-31)->minutes();
        $result = $this->service->mint($this->tenant, 'https://example.com', '203.0.113.10');
        $this->travelBack();

        $this->expectException(InvalidSessionTokenException::class);
        $this->service->verify($result['token'], 'https://example.com', '203.0.113.10');
    }

    public function test_verify_rejects_token_for_rotated_api_key(): void
    {
        $result = $this->service->mint($this->tenant, 'https://example.com', '203.0.113.10');

        $this->tenant->update(['api_key' => 'rotated-key-'.now()->timestamp]);

        $this->expectException(InvalidSessionTokenException::class);
        $this->service->verify($result['token'], 'https://example.com', '203.0.113.10');
    }

    public function test_verify_rejects_malformed_token(): void
    {
        $this->expectException(InvalidSessionTokenException::class);
        $this->service->verify('not.a.jwt', 'https://example.com', '203.0.113.10');
    }
}
```

Run:
```bash
php artisan test --filter=SessionTokenServiceTest
```
Expect all tests to fail with "class not found" (RED confirmed).

**Implementation**:

`app/Exceptions/Widget/InvalidSessionTokenException.php`:
```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Widget;

use RuntimeException;

class InvalidSessionTokenException extends RuntimeException
{
}
```

`app/Services/Widget/SessionTokenService.php`:
```php
<?php

declare(strict_types=1);

namespace App\Services\Widget;

use App\Exceptions\Widget\InvalidSessionTokenException;
use App\Models\Tenant;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Throwable;

final class SessionTokenService
{
    private const ALGORITHM = 'HS256';

    public function __construct(private readonly string $secret) {}

    /**
     * @return array{token: string, expires_at: int}
     */
    public function mint(Tenant $tenant, string $origin, string $ip): array
    {
        $ttl = (int) config('widget.session_ttl', 1800);
        $expiresAt = time() + $ttl;

        $payload = [
            'iss' => config('app.url'),
            // Hashing api_key (not tenant_id) is load-bearing: api_key rotation
            // invalidates all outstanding tokens on next verify. See spec.
            'sub' => hash('sha256', $tenant->api_key.$this->secret),
            'aud' => $origin,
            'ip' => $ip,
            'iat' => time(),
            'exp' => $expiresAt,
        ];

        return [
            'token' => JWT::encode($payload, $this->secret, self::ALGORITHM),
            'expires_at' => $expiresAt,
        ];
    }

    public function verify(string $token, string $origin, string $ip): Tenant
    {
        try {
            $payload = JWT::decode($token, new Key($this->secret, self::ALGORITHM));
        } catch (ExpiredException | SignatureInvalidException $e) {
            throw new InvalidSessionTokenException($e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new InvalidSessionTokenException('Malformed token', 0, $e);
        }

        if (($payload->aud ?? null) !== $origin) {
            throw new InvalidSessionTokenException('Origin mismatch');
        }

        if (($payload->ip ?? null) !== $ip) {
            throw new InvalidSessionTokenException('IP mismatch');
        }

        $tenant = Tenant::whereRaw('SHA2(CONCAT(api_key, ?), 256) = ?', [$this->secret, $payload->sub ?? ''])
            ->first();

        if ($tenant === null) {
            // Fallback for SQLite (testing) or non-MySQL: in-PHP comparison
            $tenant = Tenant::all()->first(fn ($t) => hash('sha256', $t->api_key.$this->secret) === ($payload->sub ?? ''));
        }

        if ($tenant === null) {
            throw new InvalidSessionTokenException('Tenant not found or api_key rotated');
        }

        return $tenant;
    }
}
```

Register binding in a new service provider OR `AppServiceProvider::register`:

`app/Providers/AppServiceProvider.php` — add to `register()`:
```php
$this->app->singleton(\App\Services\Widget\SessionTokenService::class, fn ($app) =>
    new \App\Services\Widget\SessionTokenService(config('app.key'))
);
```

**GREEN**:
```bash
php artisan test --filter=SessionTokenServiceTest
```
Expect 8/8 passing.

**Full suite**:
```bash
php artisan test
```
Expect no regressions.

**Pint**:
```bash
./vendor/bin/pint app/Services/Widget app/Exceptions/Widget tests/Unit/Services/Widget app/Providers/AppServiceProvider.php
```

**Commit**:
```
feat(widget): SessionTokenService — JWT mint/verify bound to api_key+origin+ip
```

---

## Task 2 — `RequireWidgetSessionToken` middleware + tests

**Goal**: middleware that enforces Bearer token verification, with dual-accept fallthrough when token is missing (not when invalid).

**Files to create**:
- `app/Http/Middleware/RequireWidgetSessionToken.php`
- `tests/Feature/Api/V1/Widget/SessionTokenTest.php`

**RED**: test file first.

`tests/Feature/Api/V1/Widget/SessionTokenTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Widget;

use App\Models\Tenant;
use App\Services\Widget\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTokenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private SessionTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => ['example.com']],
        ]);
        $this->service = $this->app->make(SessionTokenService::class);
    }

    public function test_init_returns_session_token(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', ['api_key' => $this->tenant->api_key]);

        $response->assertOk()
            ->assertJsonStructure(['session_token', 'expires_at']);
        $this->assertIsString($response->json('session_token'));
    }

    public function test_protected_endpoint_accepts_valid_bearer(): void
    {
        $minted = $this->service->mint($this->tenant, 'https://example.com', '127.0.0.1');

        $response = $this->withHeaders([
            'Origin' => 'https://example.com',
            'Authorization' => "Bearer {$minted['token']}",
        ])->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertOk();
    }

    public function test_protected_endpoint_rejects_invalid_bearer_even_during_dual_accept(): void
    {
        config()->set('widget.session_dual_accept', true);

        $response = $this->withHeaders([
            'Origin' => 'https://example.com',
            'Authorization' => 'Bearer this.is.invalid',
        ])->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertStatus(401)
            ->assertJson(['error' => 'session_expired']);
    }

    public function test_protected_endpoint_falls_through_when_bearer_missing_during_dual_accept(): void
    {
        config()->set('widget.session_dual_accept', true);

        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertOk();
        $this->assertSame('true', $response->headers->get('Deprecation'));
    }

    public function test_protected_endpoint_requires_bearer_post_dual_accept_window(): void
    {
        config()->set('widget.session_dual_accept', false);

        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertStatus(401)
            ->assertJson(['error' => 'session_token_required']);
    }

    public function test_ip_mismatch_rejects_token(): void
    {
        $minted = $this->service->mint($this->tenant, 'https://example.com', '203.0.113.99');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders([
                'Origin' => 'https://example.com',
                'Authorization' => "Bearer {$minted['token']}",
            ])->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertStatus(401);
    }
}
```

Run:
```bash
php artisan test --filter=SessionTokenTest
```
Expect all tests to fail (RED).

**Implementation**:

`app/Http/Middleware/RequireWidgetSessionToken.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Widget\InvalidSessionTokenException;
use App\Services\Widget\SessionTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireWidgetSessionToken
{
    public function __construct(private readonly SessionTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $this->extractBearer($request);
        $dualAccept = (bool) config('widget.session_dual_accept', true);

        // Missing Bearer
        if ($bearer === null) {
            if ($dualAccept) {
                $response = $next($request);
                $response->headers->set('Deprecation', 'true');

                return $response;
            }

            return response()->json(['error' => 'session_token_required'], 401);
        }

        // Bearer present — must verify, regardless of dual-accept
        $origin = $this->canonicalOrigin($request->header('Origin') ?? $request->header('Referer'));

        try {
            $tenant = $this->tokens->verify($bearer, $origin ?? '', $request->ip() ?? '');
        } catch (InvalidSessionTokenException) {
            return response()->json(['error' => 'session_expired'], 401);
        }

        $request->attributes->set('widget_tenant', $tenant);

        return $next($request);
    }

    private function extractBearer(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if ($header === null) {
            return null;
        }

        if (! str_starts_with(strtolower($header), 'bearer ')) {
            return null;
        }

        return trim(substr($header, 7));
    }

    private function canonicalOrigin(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $parts = parse_url($raw);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
```

Register middleware alias in `bootstrap/app.php`:
```php
$middleware->alias([
    'check.limits' => CheckUsageLimits::class,
    'validate.widget.domain' => ValidateWidgetDomain::class,
    'widget.session_token' => \App\Http\Middleware\RequireWidgetSessionToken::class,
]);
```

(Don't wire it into routes yet — Task 4 does that.)

**GREEN** (after Task 4 wires routes — for now run unit-level test that exercises middleware directly):

The Task 2 tests above include feature-level assertions that depend on Task 4's route wiring. To keep tasks strictly TDD, restructure Task 2 to use middleware-direct testing:

```php
// Alternative: instantiate the middleware directly with a fake request/response.
// Pest pattern:
public function test_middleware_passes_with_valid_bearer(): void
{
    $minted = $this->service->mint($this->tenant, 'https://example.com', '127.0.0.1');
    $request = Request::create('/api/v1/widget/conversation', 'POST');
    $request->headers->set('Authorization', "Bearer {$minted['token']}");
    $request->headers->set('Origin', 'https://example.com');

    $middleware = $this->app->make(RequireWidgetSessionToken::class);
    $next = fn () => response()->json(['ok' => true]);

    $response = $middleware->handle($request, $next);
    $this->assertSame(200, $response->getStatusCode());
}
```

**Decision**: keep the Task 2 tests feature-level. Run them in RED after Task 2 completes (they fail because routes aren't wired yet), implement middleware, then Task 4 wires routes and the same tests turn GREEN.

Alternative cleaner sequence:
- Task 2a: middleware implementation + middleware-direct unit tests (no route wiring)
- Task 2b: feature-level tests deferred to Task 4

For executor simplicity, use Task 2a here. Move feature tests to Task 4 below.

**Task 2 final test (middleware-direct, unit-level)**:

Replace `tests/Feature/Api/V1/Widget/SessionTokenTest.php` with `tests/Unit/Http/Middleware/RequireWidgetSessionTokenTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\RequireWidgetSessionToken;
use App\Models\Tenant;
use App\Services\Widget\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class RequireWidgetSessionTokenTest extends TestCase
{
    use RefreshDatabase;

    private RequireWidgetSessionToken $middleware;

    private SessionTokenService $tokens;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = $this->app->make(SessionTokenService::class);
        $this->middleware = $this->app->make(RequireWidgetSessionToken::class);
        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function test_passes_with_valid_bearer(): void
    {
        $minted = $this->tokens->mint($this->tenant, 'https://example.com', '127.0.0.1');
        $request = $this->makeRequest("Bearer {$minted['token']}", 'https://example.com');

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($request->attributes->get('widget_tenant')->is($this->tenant));
    }

    public function test_rejects_invalid_bearer_even_during_dual_accept(): void
    {
        config()->set('widget.session_dual_accept', true);
        $request = $this->makeRequest('Bearer not.a.real.jwt', 'https://example.com');

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('session_expired', json_decode($response->getContent(), true)['error']);
    }

    public function test_falls_through_when_missing_bearer_during_dual_accept(): void
    {
        config()->set('widget.session_dual_accept', true);
        $request = $this->makeRequest(null, 'https://example.com');

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('true', $response->headers->get('Deprecation'));
    }

    public function test_requires_bearer_post_dual_accept(): void
    {
        config()->set('widget.session_dual_accept', false);
        $request = $this->makeRequest(null, 'https://example.com');

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('session_token_required', json_decode($response->getContent(), true)['error']);
    }

    public function test_ip_mismatch_returns_401(): void
    {
        $minted = $this->tokens->mint($this->tenant, 'https://example.com', '203.0.113.99');
        $request = $this->makeRequest("Bearer {$minted['token']}", 'https://example.com');
        // Default request IP differs from issue-time IP

        $response = $this->middleware->handle($request, fn () => response()->json(['ok' => true]));

        $this->assertSame(401, $response->getStatusCode());
    }

    private function makeRequest(?string $authorization, string $origin): Request
    {
        $request = Request::create('/api/v1/widget/conversation', 'POST');
        if ($authorization !== null) {
            $request->headers->set('Authorization', $authorization);
        }
        $request->headers->set('Origin', $origin);

        return $request;
    }
}
```

**GREEN**:
```bash
php artisan test --filter=RequireWidgetSessionTokenTest
```
Expect 5/5 passing.

**Pint**:
```bash
./vendor/bin/pint app/Http/Middleware/RequireWidgetSessionToken.php tests/Unit/Http/Middleware bootstrap/app.php
```

**Commit**:
```
feat(widget): RequireWidgetSessionToken middleware + dual-accept fallthrough
```

---

## Task 3 — `ChatController::init` returns `session_token` + `expires_at`

**Goal**: emit session token from `/init` so the widget can start using Bearer flow.

**Files to modify**:
- `app/Http/Controllers/Api/V1/Widget/ChatController.php` — `init()` method only
- `tests/Feature/Api/V1/Widget/InitTokenTest.php` (new)

**RED**: test file.

`tests/Feature/Api/V1/Widget/InitTokenTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Widget;

use App\Models\Tenant;
use App\Services\Widget\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_init_response_includes_verifiable_session_token(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => ['example.com']],
        ]);

        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', ['api_key' => $tenant->api_key]);

        $response->assertOk()->assertJsonStructure(['session_token', 'expires_at']);

        $token = $response->json('session_token');
        $expiresAt = $response->json('expires_at');
        $this->assertIsInt($expiresAt);
        $this->assertGreaterThan(time(), $expiresAt);

        // Token must verify under same Origin + IP
        $service = $this->app->make(SessionTokenService::class);
        $verified = $service->verify($token, 'https://example.com', '127.0.0.1');
        $this->assertTrue($verified->is($tenant));
    }
}
```

Run:
```bash
php artisan test --filter=InitTokenTest
```
Expect failure (RED — `session_token` missing from response).

**Implementation**:

Read `app/Http/Controllers/Api/V1/Widget/ChatController.php`, locate `init()`, add token minting before the return.

```php
// At top of class:
use App\Services\Widget\SessionTokenService;

// Inject in constructor or method-resolve:
public function __construct(private readonly SessionTokenService $tokens) {}

// In init() — before the existing return response()->json([...]) call:
$origin = $this->canonicalOrigin($request->header('Origin') ?? $request->header('Referer'));
$ip = $request->ip() ?? '';
$minted = $this->tokens->mint($tenant, $origin ?? '', $ip);

// Then merge into the response:
return response()->json([
    // ...existing fields (config props, etc.)...
    'session_token' => $minted['token'],
    'expires_at' => $minted['expires_at'],
]);
```

Add the `canonicalOrigin` helper inline OR import from `ValidateWidgetDomain` (it's a `private` method there — extract to a trait if needed, or duplicate the 6-line method).

Recommendation: extract to a shared support class to avoid duplication. Create `app/Support/Http/CanonicalOrigin.php`:
```php
<?php

declare(strict_types=1);

namespace App\Support\Http;

final class CanonicalOrigin
{
    public static function from(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $parts = parse_url($raw);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
```

Update `ValidateWidgetDomain` and `RequireWidgetSessionToken` (Task 2) to call `CanonicalOrigin::from(...)` instead of their inline copies.

**GREEN**:
```bash
php artisan test --filter=InitTokenTest
php artisan test --filter='Widget|SessionToken'
```
Expect new test pass + existing widget tests still green.

**Pint**:
```bash
./vendor/bin/pint app/Support/Http app/Http/Controllers/Api/V1/Widget/ChatController.php app/Http/Middleware/ValidateWidgetDomain.php app/Http/Middleware/RequireWidgetSessionToken.php tests/Feature/Api/V1/Widget/InitTokenTest.php
```

**Commit**:
```
feat(widget): /init returns session_token + expires_at
```

---

## Task 4 — Wire `widget.session_token` middleware to routes

**Goal**: every widget endpoint except `/init` requires the middleware. Feature-level tests verify end-to-end.

**Files to modify**:
- `routes/api.php`
- `config/widget.php` (new — minimal, just env-backed values)
- `.env.example`
- `tests/Feature/Api/V1/Widget/SessionTokenTest.php` (new — feature-level)

**RED**: feature test.

`config/widget.php`:
```php
<?php

declare(strict_types=1);

return [
    'session_dual_accept' => env('WIDGET_SESSION_DUAL_ACCEPT', true),
    'session_ttl' => env('WIDGET_SESSION_TTL', 1800),

    'ip_init_per_min' => env('WIDGET_IP_INIT_PER_MIN', 10),
    'ip_message_per_min' => env('WIDGET_IP_MESSAGE_PER_MIN', 30),
    'ip_daily_cap' => env('WIDGET_IP_DAILY_CAP', 5000),
];
```

`.env.example` — append:
```
WIDGET_SESSION_DUAL_ACCEPT=true
WIDGET_SESSION_TTL=1800
WIDGET_IP_INIT_PER_MIN=10
WIDGET_IP_MESSAGE_PER_MIN=30
WIDGET_IP_DAILY_CAP=5000
```

`tests/Feature/Api/V1/Widget/SessionTokenTest.php` (feature-level, hits real routes):
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Widget;

use App\Models\Tenant;
use App\Services\Widget\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTokenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => ['example.com']],
        ]);
    }

    public function test_conversation_endpoint_accepts_valid_bearer(): void
    {
        $service = $this->app->make(SessionTokenService::class);
        $minted = $service->mint($this->tenant, 'https://example.com', '127.0.0.1');

        $response = $this->withHeaders([
            'Origin' => 'https://example.com',
            'Authorization' => "Bearer {$minted['token']}",
        ])->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertSuccessful();
    }

    public function test_conversation_endpoint_rejects_bad_bearer_during_dual_accept(): void
    {
        config()->set('widget.session_dual_accept', true);

        $response = $this->withHeaders([
            'Origin' => 'https://example.com',
            'Authorization' => 'Bearer not.a.real.jwt',
        ])->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertStatus(401)->assertJson(['error' => 'session_expired']);
    }

    public function test_conversation_endpoint_falls_through_without_bearer_during_dual_accept(): void
    {
        config()->set('widget.session_dual_accept', true);

        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertSuccessful();
        $this->assertSame('true', $response->headers->get('Deprecation'));
    }

    public function test_conversation_endpoint_requires_bearer_after_window(): void
    {
        config()->set('widget.session_dual_accept', false);

        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertStatus(401)->assertJson(['error' => 'session_token_required']);
    }

    public function test_init_endpoint_does_not_require_bearer(): void
    {
        config()->set('widget.session_dual_accept', false);

        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', ['api_key' => $this->tenant->api_key]);

        $response->assertOk()->assertJsonStructure(['session_token']);
    }
}
```

Run:
```bash
php artisan test --filter=Feature.*SessionTokenTest
```
Expect failure (RED — middleware not wired).

**Implementation**:

`routes/api.php` — modify the widget prefix group. **Apply `widget.session_token` to every route except `/init`**:

```php
Route::prefix('v1/widget')->middleware(['throttle:widget', 'validate.widget.domain'])->group(function () {
    Route::post('/init', [ChatController::class, 'init']);  // no widget.session_token

    Route::middleware('widget.session_token')->group(function () {
        Route::post('/conversation', [ChatController::class, 'startConversation'])->middleware('check.limits:conversations');
        Route::post('/message', [ChatController::class, 'sendMessage'])->middleware('check.limits:tokens');
        Route::post('/message/stream', [ChatController::class, 'streamMessage'])->middleware('check.limits:tokens');
        Route::post('/conversation/end', [ChatController::class, 'endConversation']);
        Route::post('/lead', [LeadController::class, 'capture'])->middleware('check.limits:leads');
    });

    Route::options('{any}', fn () => response('', 204))->where('any', '.*');
});
```

**GREEN**:
```bash
php artisan test --filter='Feature.*Widget'
php artisan test  # full suite
```
Expect all green.

**Pint**:
```bash
./vendor/bin/pint config/widget.php routes/api.php tests/Feature/Api/V1/Widget/SessionTokenTest.php
```

**Commit**:
```
feat(widget): require Bearer token on all widget routes except /init
```

---

## Task 5 — `ThrottleWidgetPerIp` middleware + tests

**Goal**: per-IP rate limits additive to existing per-key throttle.

**Files to create**:
- `app/Http/Middleware/ThrottleWidgetPerIp.php`
- `tests/Feature/Api/V1/Widget/PerIpThrottleTest.php`

**RED**:

`tests/Feature/Api/V1/Widget/PerIpThrottleTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Widget;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PerIpThrottleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => ['example.com']],
        ]);
        config()->set('widget.ip_init_per_min', 3);
        config()->set('widget.session_dual_accept', true);
    }

    public function test_init_blocked_after_per_ip_threshold(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $response = $this->withHeaders(['Origin' => 'https://example.com'])
                ->postJson('/api/v1/widget/init', ['api_key' => $this->tenant->api_key]);
            $response->assertOk();
        }

        $blocked = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', ['api_key' => $this->tenant->api_key]);

        $blocked->assertStatus(429)
            ->assertJsonStructure(['error', 'retry_after'])
            ->assertHeader('Retry-After');
    }
}
```

Run:
```bash
php artisan test --filter=PerIpThrottleTest
```
Expect failure (RED).

**Implementation**:

`app/Http/Middleware/ThrottleWidgetPerIp.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottleWidgetPerIp
{
    public function handle(Request $request, Closure $next, string $bucket): Response
    {
        [$limit, $window] = $this->config($bucket);
        $key = "widget:{$bucket}:".sha1(($request->ip() ?? 'unknown').'|'.$bucket);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            $retryAfter = RateLimiter::availableIn($key);

            return response()->json([
                'error' => 'rate_limited',
                'retry_after' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        RateLimiter::hit($key, $window);

        return $next($request);
    }

    /**
     * @return array{0: int, 1: int}  [maxAttempts, decaySeconds]
     */
    private function config(string $bucket): array
    {
        return match ($bucket) {
            'init' => [(int) config('widget.ip_init_per_min', 10), 60],
            'message' => [(int) config('widget.ip_message_per_min', 30), 60],
            'daily' => [(int) config('widget.ip_daily_cap', 5000), 86_400],
            default => throw new \InvalidArgumentException("Unknown widget throttle bucket: {$bucket}"),
        };
    }
}
```

Register alias in `bootstrap/app.php`:
```php
'widget.throttle_ip' => \App\Http\Middleware\ThrottleWidgetPerIp::class,
```

Wire in `routes/api.php`:
```php
Route::post('/init', [ChatController::class, 'init'])
    ->middleware('widget.throttle_ip:init');

Route::middleware(['widget.session_token', 'widget.throttle_ip:message'])->group(function () {
    Route::post('/conversation', ...);
    Route::post('/message', ...);
    // etc.
});
```

(For daily cap, apply on all routes — wire as separate middleware call or fold into the same bucket.)

**GREEN**:
```bash
php artisan test --filter=PerIpThrottleTest
php artisan test
```

**Pint**:
```bash
./vendor/bin/pint app/Http/Middleware/ThrottleWidgetPerIp.php tests/Feature/Api/V1/Widget/PerIpThrottleTest.php bootstrap/app.php routes/api.php
```

**Commit**:
```
feat(widget): ThrottleWidgetPerIp middleware (init / message / daily buckets)
```

---

## Task 6 — Update existing widget tests to send Bearer where applicable

**Goal**: keep the legacy test suite green by passing Bearer tokens where they're now required (and verify the dual-accept fallthrough doesn't mask regressions).

**Steps**:

1. Find every existing test under `tests/Feature/Api/V1/Widget/` (or other feature tests that hit widget routes).
2. For each test that asserts a 200/201 on a protected endpoint:
   - Either add `config()->set('widget.session_dual_accept', true);` in setUp (relies on legacy fallthrough — preserves the existing assertion semantics) OR add a Bearer header by minting via `SessionTokenService` in the test.
   - Prefer the Bearer route — it's the post-cutover behavior.
3. Run the full suite and resolve any failures introduced by Task 4's routing.

```bash
php artisan test
```
Expect all green.

**Pint**:
```bash
./vendor/bin/pint tests/Feature/Api/V1/Widget
```

**Commit**:
```
test(widget): pass Bearer tokens in existing widget feature tests
```

---

## Task 7 — Update `public/widget/chatbot.js` for Bearer flow

**Goal**: widget mints + sends Bearer token, refreshes on 401, retries the original request once.

**File to modify**:
- `public/widget/chatbot.js`

**Behavior** (no Pest tests for this file — browser smoke covers it):

1. After `/init` succeeds, store `session_token` in a module-scope variable.
2. Wrap every API call (`fetch` or whatever the file uses today — read first):
   - Set header `Authorization: Bearer ${sessionToken}` on every call EXCEPT `/init`
   - On any 401 response, call `/init` to mint a fresh token, retry the original request once
   - If the retry also fails, surface "Service unavailable" to the user
3. Tokens never written to localStorage or sessionStorage.

**Implementation guidance**: read `public/widget/chatbot.js`, locate the helper function that makes API calls (likely a wrapper around `fetch`). Edit it. Do NOT rewrite the whole file.

**Smoke** (no automated test — manual via dev server):

```bash
php artisan serve --port=8001 &
npm run dev &
php artisan queue:work --queue=crawls,default &
open http://127.0.0.1:8001/widget/test.html
# Click the chat launcher, send a message, confirm response
# Open browser DevTools → Network: verify every /api/v1/widget/* request has Authorization: Bearer header
```

**Pint**: N/A (JS file)

**Commit**:
```
feat(widget): chatbot.js mints + sends Bearer token, refreshes on 401
```

---

## Task 8 — Audit logging via `widget_audit` channel

**Goal**: every widget request emits a structured log line for anomaly detection.

**Files to modify**:
- `config/logging.php` — add `widget_audit` channel
- `app/Http/Middleware/RequireWidgetSessionToken.php` — log on every verify (success + fail)
- `app/Http/Controllers/Api/V1/Widget/ChatController.php::init()` — log on token mint

**Test**:

`tests/Feature/Api/V1/Widget/AuditLogTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Widget;

use App\Models\Tenant;
use App\Services\Widget\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_message_emits_audit_log_with_ip_hash(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => ['example.com']],
        ]);
        $minted = $this->app->make(SessionTokenService::class)
            ->mint($tenant, 'https://example.com', '127.0.0.1');

        Log::shouldReceive('channel')->with('widget_audit')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function ($message, $context) use ($tenant) {
            return $context['tenant_id'] === $tenant->id
                && isset($context['ip_hash'])
                && $context['ip_hash'] !== '127.0.0.1'  // hashed
                && strlen($context['ip_hash']) === 64;  // sha256 hex
        });

        $this->withHeaders([
            'Origin' => 'https://example.com',
            'Authorization' => "Bearer {$minted['token']}",
        ])->postJson('/api/v1/widget/conversation', ['api_key' => $tenant->api_key]);
    }
}
```

`config/logging.php` — add channel:
```php
'widget_audit' => [
    'driver' => 'daily',
    'path' => storage_path('logs/widget-audit.log'),
    'level' => 'info',
    'days' => 30,
],
```

Middleware log emission (inside `RequireWidgetSessionToken::handle`):
```php
\Illuminate\Support\Facades\Log::channel('widget_audit')->info('widget_request', [
    'tenant_id' => $tenant->id,
    'origin' => $origin,
    'ip_hash' => hash('sha256', ($request->ip() ?? '').config('app.key')),
    'endpoint' => $request->path(),
    'method' => $request->method(),
]);
```

(Same shape from `init()` on token mint.)

**GREEN**:
```bash
php artisan test --filter=AuditLogTest
php artisan test
```

**Pint**:
```bash
./vendor/bin/pint config/logging.php app/Http/Middleware/RequireWidgetSessionToken.php app/Http/Controllers/Api/V1/Widget/ChatController.php tests/Feature/Api/V1/Widget/AuditLogTest.php
```

**Commit**:
```
feat(widget): structured audit logging with ip_hash to widget_audit channel
```

---

## Task 9 — Browser smoke + Pint + PHPStan + /simplify x2

Per CLAUDE.md final hardening pass.

1. **Full test suite**:
   ```bash
   php artisan test
   ```
   Expect all green.

2. **Pint sweep**:
   ```bash
   ./vendor/bin/pint --test
   ```
   If anything flagged: `./vendor/bin/pint`, commit `style(pint): apply auto-fixes`.

3. **PHPStan**:
   ```bash
   ./vendor/bin/phpstan analyse --no-progress --memory-limit=2G
   ```
   Expect `[OK] No errors`. Fix any new issues introduced by this PR (no baseline edits).

4. **Browser smoke** — full widget flow on http://127.0.0.1:8001/widget/test.html:
   - Click launcher → init request fires → response includes `session_token`
   - Send a message → request has `Authorization: Bearer ...` header
   - Wait 31+ minutes (or temporarily set `WIDGET_SESSION_TTL=10` in `.env` + `php artisan config:clear`) → send another message → confirm 401 → automatic refresh → original request retries → response delivers
   - Verify no console errors, no infinite refresh loop

5. **`/simplify` pass 1** — dispatch three parallel reviewers. Apply real findings; skip noise.

6. **Pint after `/simplify`**:
   ```bash
   ./vendor/bin/pint
   ```

7. **`/simplify` pass 2** — second pass catches issues introduced by pass 1.

8. **Pint after second `/simplify`**.

9. **Open PR**:
   ```bash
   gh pr create --title "feat(widget): session tokens + per-IP rate limits" --body "$(cat docs/superpowers/specs/2026-05-19-widget-session-tokens-design.md | head -40)
   ...
   "
   ```

---

## Out of plan (separate PRs)

- **D — SRI / supply-chain** (separate PR after this lands)
- **C — encryption-at-rest** (separate PR, larger DB migration)
- **TrustProxies prod configuration** — deployment step, not code, done as part of prod rollout
- **Tenant-side UI for tuning `widget_ip_daily_cap`** — admin dashboard work, separate PR

---

## Risk register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Stale chatbot.js cached in browsers post-deploy | High | Low | Dual-accept window absorbs the upgrade lag; old api_key flow still works for 7 days |
| TrustProxies misconfigured in prod, IP binding becomes per-LB | Medium | High | Documented deployment-time gate; cutover blocked until verified |
| Per-IP cap of 5000 too low for a specific tenant | Low | Medium | Tenant-tunable from v1 via `settings.widget_ip_daily_cap` |
| firebase/php-jwt 7.x deprecation | Low | Low | Library is actively maintained by Google; locked to ^7.0 |
| Widget retry loop on persistent 401 | Medium | Medium | Retry capped at 1 attempt; surfaces "Service unavailable" on second failure |
