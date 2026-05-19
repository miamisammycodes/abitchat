# Testing Patterns

**Analysis Date:** 2026-05-20

## Test Framework

**Runner:**
- PHPUnit 13+ (via Pest-compatible `TestCase`), run through Laravel's `php artisan test`
- Config: `phpunit.xml` at repo root
- Note: The codebase uses PHPUnit-style class-based tests with `$this->assert*` methods, not Pest function-based syntax.

**Assertion Library:**
- PHPUnit built-in assertions (`assertSame`, `assertCount`, `assertNull`, `assertEquals`, `assertInstanceOf`, etc.)
- Laravel HTTP test assertions (`assertOk`, `assertStatus`, `assertRedirect`, `assertJson`, `assertJsonStructure`, `assertSessionHasErrors`)

**Static Analysis:**
- Larastan (PHPStan level 6) via `./vendor/bin/phpstan analyse`
- Zero-baseline enforced — `phpstan-baseline.neon` is empty

**Run Commands:**

```bash
php artisan test                          # Run full suite (always use between tasks)
php artisan test --filter ClassName       # Filter to a specific test class
php artisan test tests/Feature/Widget/    # Run a specific subdirectory
./vendor/bin/pint --test                  # Check style without fixing
./vendor/bin/pint                         # Auto-fix style
./vendor/bin/phpstan analyse              # Static analysis
```

**Test environment (from `phpunit.xml`):**
- `APP_ENV=testing`
- `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` (in-memory SQLite)
- `CACHE_STORE=array` (in-memory, no Redis)
- `QUEUE_CONNECTION=sync` (jobs run inline unless faked)
- `BCRYPT_ROUNDS=4` (fast hashing)
- `MAIL_MAILER=array`, `SESSION_DRIVER=array`
- Pulse, Telescope, Nightwatch disabled

## Test File Organization

**Location:** `tests/` (separate from `app/`)

**Structure:**
```
tests/
├── TestCase.php                          # Base class — all tests extend this
├── Concerns/
│   └── AuthenticatesWidget.php           # Trait for widget Bearer-token tests
├── Support/
│   └── EmbeddingFakeFactory.php          # Prism embedding stub factory
├── Unit/
│   ├── Enums/
│   ├── Exceptions/
│   ├── Jobs/
│   ├── Models/
│   ├── Rules/
│   │   └── Fixtures/                     # PHPStan fixture files for rule tests
│   └── Services/
│       ├── Crawler/
│       └── Payment/DkBank/
└── Feature/
    ├── Admin/
    ├── Auth/
    ├── Client/
    ├── Migrations/
    └── Widget/
```

**Naming:**
- Test files: `{SubjectName}Test.php` (always `Test` suffix)
- Test methods: `test_{snake_case_description}` — e.g., `test_init_blocked_after_per_ip_threshold`
- Unit tests: narrow to one class/method, no HTTP calls, may use in-memory DB
- Feature tests: full HTTP cycle via Laravel's `TestCase` HTTP helpers

## Base TestCase

All tests extend `Tests\TestCase` (`tests/TestCase.php`), which provides:

- `RefreshDatabase` trait (all tests get a fresh in-memory SQLite DB)
- `createTenantWithUser(): User` — creates a `Tenant` + `User` and populates `$this->tenant` / `$this->user`
- `actingAsTenantUser(): static` — calls `createTenantWithUser()` then `actingAs($this->user)`
- `createAdmin(): AdminUser` — creates an admin user for admin-route tests

**Critical quirk — `trial_ends_at` default:**
The `createTenantWithUser()` helper always sets `trial_ends_at` = `now()->addDays(14)`. This keeps tests passing through the `check.limits` middleware. When creating tenants inline (not via the helper), always include `trial_ends_at`:

```php
$tenant = Tenant::create([
    'name' => 'Acme',
    'slug' => 'acme',
    'status' => 'active',
    'trial_ends_at' => now()->addDays(14),   // Required — do not omit
]);
```

## Test Structure

**Typical Feature Test:**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => ['example.com']],
        ]);
    }

    public function test_some_behavior(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/init', ['api_key' => $this->tenant->api_key]);

        $response->assertOk()->assertJsonStructure(['session_token']);
    }
}
```

**Typical Unit Test:**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SomeModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_some_model_behavior(): void
    {
        $tenant = Tenant::factory()->create();
        // ... assertions
        $this->assertSame('expected', $model->attribute);
    }
}
```

**RefreshDatabase placement:** Applied both in the base `TestCase` and repeated with `use RefreshDatabase;` inside individual test classes (both are acceptable — redundant in the base but not harmful).

## TDD Process (Mandatory for Non-Trivial Changes)

The workflow for every task in a plan:

1. **Write the failing test** — run the suite, confirm RED
2. **Implement the feature** — run the suite, confirm GREEN
3. **Commit** — test + implementation together in one commit

```bash
php artisan test --filter SpecificTest    # Confirm RED
# ... implement ...
php artisan test --filter SpecificTest    # Confirm GREEN
php artisan test                          # Confirm no regressions (full suite)
```

Run the **full suite** between tasks, not just the feature filter. This catches regressions in existing tests caused by behavioral changes.

## Widget Tests: `AuthenticatesWidget` Trait

Tests for widget Bearer-token endpoints use the `Tests\Concerns\AuthenticatesWidget` trait (`tests/Concerns/AuthenticatesWidget.php`):

```php
use Tests\Concerns\AuthenticatesWidget;

class SomeWidgetTest extends TestCase
{
    use AuthenticatesWidget;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuthenticatesWidget(); // Forces strict mode (session_dual_accept=false)
    }

    public function test_something(): void
    {
        $tenant = $this->createWidgetTenant();
        $this->ensureWidgetOriginAllowed($tenant);
        $headers = $this->widgetHeaders($tenant); // Returns ['Origin' => ..., 'Authorization' => 'Bearer ...']

        $this->withHeaders($headers)->postJson('/api/v1/widget/conversation', [...]);
    }
}
```

Provided helpers:
- `setUpAuthenticatesWidget()` — sets `widget.session_dual_accept=false` (post-cutover strict mode)
- `createWidgetTenant(array $overrides = [])` — creates a tenant with `allowed_domains=['example.com']` and `trial_ends_at`
- `ensureWidgetOriginAllowed(Tenant $tenant)` — adds `example.com` to allowed domains if not present
- `widgetHeaders(Tenant $tenant)` — mints a session token and returns HTTP headers for `withHeaders()`

For session-flow tests that test dual-accept mode, set `config()->set('widget.session_dual_accept', true)` inline instead of calling `setUpAuthenticatesWidget()`.

## Mocking Patterns

**Facade fakes (preferred):**

```php
// Queue — fake specific job classes
Queue::fake([GenerateEmbeddings::class]);
Bus::fake();
Bus::assertDispatched(CrawlWebsiteJob::class, function ($job) {
    return $job->mode === CrawlMode::Initial;
});
Bus::assertNotDispatched(CrawlWebsiteJob::class);

// HTTP client
Http::fake([
    '1.1.1.1/page' => Http::response('<p>content</p>', 200),
    '1.1.1.1/redir' => Http::response('', 302, ['Location' => 'http://internal/']),
]);
Http::fakeSequence()->push('{"ok":true}', 200)->push('{"ok":false}', 500);
Http::assertSentCount(1);
Http::assertNotSent(fn ($req) => str_contains($req->url(), '169.254.169.254'));

// Log facade (for audit log tests)
Log::shouldReceive('channel')->with('widget_audit')->andReturnSelf();
Log::shouldReceive('info')->once()->andReturnUsing(function ($msg, $ctx) use (&$captured) {
    $captured = ['message' => $msg, 'context' => $ctx];
    return null;
});
Log::shouldReceive('debug')->andReturnNull(); // Absorb debug noise
```

**Prism (LLM) faking:**

```php
use Prism\Prism\Facades\Prism;
use Tests\Support\EmbeddingFakeFactory;

// Single embedding response
Prism::fake(EmbeddingFakeFactory::single());

// N responses for loop-based embedding jobs
Prism::fake(EmbeddingFakeFactory::many(30));
```

**What NOT to mock:** Database (use `RefreshDatabase` with real SQLite instead). Avoid `Mockery::mock()` for Eloquent models — test against real DB records.

## `EmbeddingFakeFactory` Helper

Located at `tests/Support/EmbeddingFakeFactory.php`. Used to stub Prism embedding calls in tests:

```php
// Single 768-dim vector filled with 0.01
EmbeddingFakeFactory::single()
EmbeddingFakeFactory::single(dimensions: 512, value: 0.5)

// N fake responses for jobs that embed in a loop
EmbeddingFakeFactory::many(30)
EmbeddingFakeFactory::many(count: 5, dimensions: 768, value: 0.01)
```

Use with `Prism::fake([...])` — the array must match the number of Prism calls the code-under-test makes.

## Model Factories

Located in `database/factories/`. All factories use `declare(strict_types=1)` and the `@extends Factory<ModelClass>` generic annotation.

Available factories: `TenantFactory`, `UserFactory`, `KnowledgeItemFactory`, `ConversationFactory`, `MessageFactory`, `CrawlUrlBlocklistFactory`, `CrawlSessionFactory`.

```php
// Use factory when you need defaults filled automatically
$tenant = Tenant::factory()->create();
$tenant = Tenant::factory()->create(['status' => 'inactive']);

// Use Tenant::create([...]) when you need explicit control (most widget tests)
$tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active', 'trial_ends_at' => ...]);
```

`TenantFactory::definition()` does NOT set `trial_ends_at` — the `check.limits` middleware will fail in tests that hit that middleware unless `trial_ends_at` is set explicitly or via the `createTenantWithUser()` helper.

## DataProvider Pattern

Use PHPUnit 10+ attribute syntax (not docblock `@dataProvider`):

```php
use PHPUnit\Framework\Attributes\DataProvider;

#[DataProvider('strictModeEndpointsProvider')]
public function test_endpoint_returns_401_without_bearer_in_strict_mode(string $uri): void
{
    $response = $this->postJson($uri, ['api_key' => $this->tenant->api_key]);
    $response->assertStatus(401);
}

public static function strictModeEndpointsProvider(): array
{
    return [
        'conversation' => ['/api/v1/widget/conversation'],
        'message'      => ['/api/v1/widget/message'],
    ];
}
```

DataProvider methods must be `public static`.

## Exception Testing

```php
// Expect a specific exception class
$this->expectException(TransactionStatusNotAllowed::class);
$transaction->approve($admin);

// Expect a Throwable (broad — for SSRF / HTTP errors)
$this->expectException(\Throwable::class);
$processor->process($item);

// Verify side effects even when exception thrown
$this->expectException(\Throwable::class);
try {
    $processor->process($item);
} finally {
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'internal'));
}
```

## PHPStan Rule Tests

Custom PHPStan rules are tested with `PHPStan\Testing\RuleTestCase`. Fixture PHP files are stored in `tests/Unit/Rules/Fixtures/`:

```php
/** @extends RuleTestCase<NoRawTenantIdWhere> */
class NoRawTenantIdWhereTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoRawTenantIdWhere;
    }

    public function test_flags_unqualified_tenant_id_where(): void
    {
        $this->analyse(
            [__DIR__.'/Fixtures/raw_tenant_id_where_fixture.php'],
            [
                ["Raw where('tenant_id', ...) ...", 15],
            ],
        );
    }
}
```

## SQLite Quirks (Known Issues)

- PHP 8.4 + SQLite + escaped exceptions can cascade-wedge `RefreshDatabase` transactions. If tests hang or fail with transaction state errors, check for exception handling that escapes across transaction boundaries.
- In-memory SQLite does not support all MySQL features. Avoid MySQL-specific syntax in migrations run during tests.
- `KnowledgeChunk::insert($rows)` (bulk insert) bypasses Eloquent events — use for performance in tests, but verify event-dependent behavior separately.

## Playwright / Browser Testing

Playwright is referenced in `CLAUDE.md` as Layer 3 of the three-layer testing strategy. No Playwright config file or `tests/e2e/` directory exists in the repo yet — browser smoke testing is done manually against the local dev server (`http://127.0.0.1:8001`) before PRs.

**Test credentials for manual / Playwright smoke:**
- Client: `test@example.com` / `password`
- Admin: `admin@example.com` / `password`

**Key URLs to smoke-test:**
- `http://127.0.0.1:8001/dashboard` — Client dashboard
- `http://127.0.0.1:8001/admin/dashboard` — Admin dashboard
- `http://127.0.0.1:8001/widget/test.html` — Widget test page
- `http://127.0.0.1:8001/widget-settings` — Widget settings

**When browser smoke is required:** Before every PR that touches UI, routes, form submission, CSRF, modal behavior, or flash messages. Browser testing catches bugs invisible to Pest: wrong route paths, JS form-targeting issues, CSRF cookie scope, modal toggle state, redirect vs 200 behavior.

**When browser smoke can be skipped:** Pure backend changes with full Pest coverage and no UI surface.

## Test Coverage

**Requirements:** No numeric threshold enforced (no `--coverage-min` config). The source is configured in `phpunit.xml`:

```xml
<source>
    <include>
        <directory>app</directory>
    </include>
</source>
```

**Generate coverage:**
```bash
php artisan test --coverage
```

**Total tests:** ~499 test methods across 95 test files (as of 2026-05-20).

## Three Layers of Testing (Summary)

Never substitute one layer for another:

| Layer | Mechanism | Catches |
|-------|-----------|---------|
| 1 — TDD inside tasks | Failing test → RED → implement → GREEN | Logic bugs, missing cases, API contracts |
| 2 — Full suite between tasks | `php artisan test` | Regressions in pre-existing tests caused by behavioral changes |
| 3 — Browser smoke before PR | Manual or Playwright against local server | Route mismatches, JS bugs, CSRF, modal state, redirect vs 200 |

---

*Testing analysis: 2026-05-20*
