# DK Bank Key Loading + Widget Audit Chokepoint Bugfix Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the two Known Bugs from `.planning/codebase/CONCERNS.md` — `DkBankClient::getPrivateKey()` throwing a cryptic `TypeError` on a missing key file, and the `WidgetAudit` crash-safety living in fragile per-call-site `try/catch` boilerplate instead of the audit chokepoint itself.

**Architecture:**
- **Bug 1** — add an explicit unreadable-file guard in `DkBankClient::getPrivateKey()` that throws a clear `RuntimeException` naming the path, instead of letting `file_get_contents() === false` cascade into a typed-property `TypeError`.
- **Bug 2** — move crash-safety *inside* `WidgetAudit` (a `try/catch` in `log()` plus a new `reject()` method that owns the rejected-path log shape). Then delete the three duplicated `try/catch` wrappers at the call sites so a future caller cannot forget the guard.

**Tech Stack:** PHP 8.4 / Laravel 13, PHPUnit 11, Larastan (PHPStan level 6, zero baseline), Pint (Laravel preset). Tests are class-based PHPUnit extending `Tests\TestCase`.

---

## Pre-Verified Facts (probed against current code during planning — do not re-assume)

1. **Bug 1 current behavior (empirically captured via `php artisan tinker`):** with `services.dk_bank.private_key_path` pointing at a missing file, current code emits a `file_get_contents(): Failed to open stream` E_WARNING and then throws:
   `TypeError: Cannot assign false to property App\Services\Payment\DkBank\DkBankClient::$cachedPrivateKey of type ?string`.
   So the RED assertion (`expectException(RuntimeException::class)`) fails today because a `TypeError` is thrown instead.
2. **`DkBankClient` has no singleton binding** — it is constructed only via `DkBankQrService.__construct(private readonly DkBankClient $client)`. `$this->app->make(DkBankClient::class)` returns a fresh instance, so reflection-based tests are isolated.
3. **All three `WidgetAudit::log` call sites already 500-safe today** (CONS-22-b / PR #31 SC2): `RequireWidgetSessionToken` rejected path (`:52-62`), approved path (`:74-79`), and `ChatController::init` (`:67-72`). This plan moves that safety into `WidgetAudit` and removes the now-redundant wrappers — behavior is preserved, not changed.
4. **Import impact of the Bug 2 refactor (grep-verified):**
   - `RequireWidgetSessionToken`: `Cache::` and `Log::` appear ONLY inside the two `catch` blocks being removed → both imports become unused and must be deleted. `WidgetAuditEvent` stays (approved path still uses `WidgetAuditEvent::Request`).
   - `ChatController`: `Cache::` is also used at `:376/:383` (tenant cache) and `Log::` at many `debug`/`error` lines → **no import changes** in this file.
5. **Mockery pattern for forcing an audit failure** (proven in `tests/Feature/Widget/WidgetAuditGuardTest.php`): `Log::shouldReceive('channel')->with(WidgetAudit::CHANNEL)->andThrow(...)` makes `Log::channel(CHANNEL)` itself throw before `->info()/->warning()` runs.

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `app/Services/Payment/DkBank/DkBankClient.php` | Modify (`getPrivateKey`, `:118-125`) | Guard unreadable key file with a clear exception |
| `tests/Unit/Services/Payment/DkBank/DkBankClientPrivateKeyTest.php` | Create | RED/GREEN for the key-file guard |
| `app/Support/Widget/WidgetAudit.php` | Modify | Self-guarding `log()`; new `reject()`; shared `recordFailure()` |
| `tests/Unit/Support/Widget/WidgetAuditTest.php` | Create | Prove `log()`/`reject()` swallow failures + increment counter |
| `app/Http/Middleware/RequireWidgetSessionToken.php` | Modify (`:47-83`) | Route through `WidgetAudit::reject()`/`log()`; drop wrappers + dead imports |
| `app/Http/Controllers/Api/V1/Widget/ChatController.php` | Modify (`:64-72`) | Drop the init-path `try/catch` wrapper |
| `tests/Feature/Widget/WidgetAuditGuardTest.php` | Unchanged (regression net) | Must stay green through the Bug 2 refactor |

---

## Task 1: DkBankClient — clear error on unreadable key file (Bug 1)

**Files:**
- Test: `tests/Unit/Services/Payment/DkBank/DkBankClientPrivateKeyTest.php` (create)
- Modify: `app/Services/Payment/DkBank/DkBankClient.php:118-125`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Payment/DkBank/DkBankClientPrivateKeyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\DkBank;

use App\Services\Payment\DkBank\DkBankClient;
use ReflectionMethod;
use Tests\TestCase;

class DkBankClientPrivateKeyTest extends TestCase
{
    private function invokeGetPrivateKey(): mixed
    {
        $client = $this->app->make(DkBankClient::class);
        $method = new ReflectionMethod($client, 'getPrivateKey');
        $method->setAccessible(true);

        return $method->invoke($client);
    }

    public function test_get_private_key_throws_clear_error_when_file_missing(): void
    {
        config(['services.dk_bank.private_key_path' => '/tmp/dk-missing-'.uniqid().'.pem']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DK Bank private key file unreadable');

        $this->invokeGetPrivateKey();
    }

    public function test_get_private_key_returns_file_contents_when_readable(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'dkpem');
        file_put_contents($path, 'FAKE-PEM-CONTENTS');
        config(['services.dk_bank.private_key_path' => $path]);

        $result = $this->invokeGetPrivateKey();

        $this->assertSame('FAKE-PEM-CONTENTS', $result);

        unlink($path);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=DkBankClientPrivateKeyTest`
Expected: `test_get_private_key_throws_clear_error_when_file_missing` FAILS — current code throws `TypeError: Cannot assign false to property ...$cachedPrivateKey of type ?string` (plus a `file_get_contents` warning), not the expected `RuntimeException`. (`test_..._returns_file_contents_when_readable` passes already since the file is readable.)

- [ ] **Step 3: Implement the guard**

In `app/Services/Payment/DkBank/DkBankClient.php`, replace `getPrivateKey()` (`:118-125`):

```php
    private function getPrivateKey(): string
    {
        if ($this->cachedPrivateKey === null) {
            $path = (string) config('services.dk_bank.private_key_path');
            $contents = is_readable($path) ? file_get_contents($path) : false;

            if ($contents === false) {
                throw new \RuntimeException("DK Bank private key file unreadable: {$path}");
            }

            $this->cachedPrivateKey = $contents;
        }

        return $this->cachedPrivateKey;
    }
```

Note: the `is_readable(...) ? ... : false` short-circuit means `file_get_contents` is never called on a missing file, so no E_WARNING fires; the `=== false` check both produces the clear error and narrows the type to `string` for PHPStan.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=DkBankClientPrivateKeyTest`
Expected: both tests PASS, no warnings emitted.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Payment/DkBank/DkBankClient.php tests/Unit/Services/Payment/DkBank/DkBankClientPrivateKeyTest.php
git commit -m "$(cat <<'EOF'
fix(dk-bank): throw clear error when private key file is unreadable

Previously a missing/unreadable PEM let file_get_contents() return false,
which under strict_types cascaded into a cryptic TypeError on the
?string property assignment. Now guard with is_readable + an explicit
RuntimeException naming the path.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: WidgetAudit — make the chokepoint self-guarding (Bug 2a)

**Files:**
- Test: `tests/Unit/Support/Widget/WidgetAuditTest.php` (create)
- Modify: `app/Support/Widget/WidgetAudit.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Support/Widget/WidgetAuditTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Widget;

use App\Enums\Widget\WidgetAuditEvent;
use App\Models\Tenant;
use App\Support\Widget\WidgetAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WidgetAuditTest extends TestCase
{
    public function test_log_swallows_logging_failure_and_increments_counter(): void
    {
        Cache::forget('widget_audit_failures');

        Log::shouldReceive('channel')->with(WidgetAudit::CHANNEL)->andThrow(new \RuntimeException('boom'));
        Log::shouldReceive('warning')->andReturnNull();

        $tenant = new Tenant();
        $tenant->id = 1;
        $request = Request::create('/api/v1/widget/init', 'POST');

        // Must NOT throw despite the log channel failing.
        WidgetAudit::log(WidgetAuditEvent::Init, $tenant, 'https://example.com', $request);

        $this->assertGreaterThanOrEqual(1, Cache::get('widget_audit_failures', 0));
    }

    public function test_reject_swallows_logging_failure_and_increments_counter(): void
    {
        Cache::forget('widget_audit_failures');

        Log::shouldReceive('channel')->with(WidgetAudit::CHANNEL)->andThrow(new \RuntimeException('boom'));
        Log::shouldReceive('warning')->andReturnNull();

        $request = Request::create('/api/v1/widget/conversation', 'POST');

        // Must NOT throw, and must own the rejected-path log shape internally.
        WidgetAudit::reject('invalid token', 'https://example.com', $request);

        $this->assertGreaterThanOrEqual(1, Cache::get('widget_audit_failures', 0));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=WidgetAuditTest`
Expected: FAILS — `test_log_...` throws the uncaught `RuntimeException('boom')` (current `log()` has no `try/catch`); `test_reject_...` errors with `Call to undefined method App\Support\Widget\WidgetAudit::reject()`.

- [ ] **Step 3: Implement the self-guarding chokepoint**

Replace the entire body of `app/Support/Widget/WidgetAudit.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Support\Widget;

use App\Enums\Widget\WidgetAuditEvent;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class WidgetAudit
{
    public const CHANNEL = 'widget_audit';

    public static function log(WidgetAuditEvent $event, Tenant $tenant, ?string $origin, Request $request): void
    {
        try {
            Log::channel(self::CHANNEL)->info($event->value, [
                'tenant_id' => $tenant->id,
                'origin' => $origin,
                'ip_hash' => self::ipHash($request->ip()),
                'endpoint' => $request->path(),
                'method' => $request->method(),
            ]);
        } catch (\Throwable $e) {
            self::recordFailure($e);
        }
    }

    public static function reject(string $reason, ?string $origin, Request $request): void
    {
        try {
            Log::channel(self::CHANNEL)->warning(WidgetAuditEvent::Rejected->value, [
                'reason' => $reason,
                'origin' => $origin,
                'ip_hash' => self::ipHash($request->ip()),
                'endpoint' => $request->path(),
            ]);
        } catch (\Throwable $e) {
            self::recordFailure($e);
        }
    }

    public static function ipHash(?string $ip): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new \RuntimeException('APP_KEY must be set for widget audit IP hashing');
        }

        return hash('sha256', ($ip ?? '').$key);
    }

    private static function recordFailure(\Throwable $e): void
    {
        Cache::increment('widget_audit_failures');
        Log::warning('[Widget] Audit log failure', ['error' => $e->getMessage()]);
    }
}
```

Note: `ipHash()` is intentionally called *inside* each `try` so both an empty-`APP_KEY` `RuntimeException` and a log-driver failure land in the same `catch`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=WidgetAuditTest`
Expected: both tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Widget/WidgetAudit.php tests/Unit/Support/Widget/WidgetAuditTest.php
git commit -m "$(cat <<'EOF'
fix(widget): make WidgetAudit self-guarding at the chokepoint

Move crash-safety into log() and a new reject() so callers cannot
forget the try/catch. Both swallow logging failures, bump the
widget_audit_failures counter, and never propagate a 500.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Route call sites through the chokepoint, delete redundant guards (Bug 2b)

**Files:**
- Modify: `app/Http/Middleware/RequireWidgetSessionToken.php:47-83` (+ imports)
- Modify: `app/Http/Controllers/Api/V1/Widget/ChatController.php:64-72`
- Regression net: `tests/Feature/Widget/WidgetAuditGuardTest.php` (unchanged)

- [ ] **Step 1: Confirm the regression net is green before refactoring**

Run: `php artisan test --filter=WidgetAuditGuardTest`
Expected: all 4 tests PASS (baseline before refactor).

- [ ] **Step 2: Refactor `RequireWidgetSessionToken`**

In `app/Http/Middleware/RequireWidgetSessionToken.php`:

(a) Remove the two now-unused imports:
```php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
```
(Keep `use App\Enums\Widget\WidgetAuditEvent;` — still used by the approved path.)

(b) Replace the rejected-path `catch` block (`:49-65`) with:
```php
        } catch (InvalidSessionTokenException $e) {
            WidgetAudit::reject($e->getMessage(), $origin, $request);

            return response()->json(['error' => WidgetErrors::SESSION_EXPIRED], 401);
        }
```

(c) Replace the approved-path audit block (`:72-79`) with:
```php
        WidgetAudit::log(WidgetAuditEvent::Request, $tenant, $origin, $request);
```

- [ ] **Step 3: Refactor `ChatController::init`**

In `app/Http/Controllers/Api/V1/Widget/ChatController.php`, replace the init-path audit block (`:64-72`) with:
```php
        WidgetAudit::log(WidgetAuditEvent::Init, $tenant, $origin, $request);
```
Do NOT touch imports here — `Cache` (`:376/:383`) and `Log` (`debug`/`error` throughout) remain in use.

- [ ] **Step 4: Run the regression net + the new unit tests to verify behavior is preserved**

Run: `php artisan test --filter='WidgetAuditGuardTest|WidgetAuditTest'`
Expected: all PASS — the feature tests still assert no-500 + counter increments, now satisfied by the chokepoint guard instead of call-site wrappers.

- [ ] **Step 5: Confirm no dead imports remain**

Run: `./vendor/bin/pint --test app/Http/Middleware/RequireWidgetSessionToken.php app/Http/Controllers/Api/V1/Widget/ChatController.php && ./vendor/bin/phpstan analyse app/Http/Middleware/RequireWidgetSessionToken.php app/Http/Controllers/Api/V1/Widget/ChatController.php`
Expected: Pint reports no style issues (no unused-import flag); PHPStan reports no errors. If Pint flags style, run `./vendor/bin/pint` (no `--test`) on those files.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/RequireWidgetSessionToken.php app/Http/Controllers/Api/V1/Widget/ChatController.php
git commit -m "$(cat <<'EOF'
refactor(widget): route audit calls through self-guarding chokepoint

Delete the three duplicated try/catch wrappers now that WidgetAudit
guards itself. Rejected path uses WidgetAudit::reject(); approved/init
paths call WidgetAudit::log() directly. Drop dead Cache/Log imports
from the middleware.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Full verification + PR

**Files:** none modified (verification only)

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: all tests PASS (was 881+ before this work; this adds 4 new tests). Investigate any failure before proceeding — a regression elsewhere means the behavioral assumption in Task 3 was wrong.

- [ ] **Step 2: Pint on all touched files**

Run: `./vendor/bin/pint --test app/Services/Payment/DkBank/DkBankClient.php app/Support/Widget/WidgetAudit.php app/Http/Middleware/RequireWidgetSessionToken.php app/Http/Controllers/Api/V1/Widget/ChatController.php tests/Unit/Services/Payment/DkBank/DkBankClientPrivateKeyTest.php tests/Unit/Support/Widget/WidgetAuditTest.php`
Expected: clean. If anything flagged, run without `--test` to fix, re-run `php artisan test`, then commit as `style(pint): apply auto-fixes` (scope to these files only).

- [ ] **Step 3: PHPStan (zero baseline must hold)**

Run: `./vendor/bin/phpstan analyse`
Expected: `[OK] No errors`. The baseline is zero and must stay zero.

- [ ] **Step 4: Open the PR**

```bash
git push -u origin HEAD
gh pr create --title "fix: DK Bank key-file guard + widget audit chokepoint hardening" --body "$(cat <<'EOF'
## Summary
- **Bug 1 (DK Bank):** `DkBankClient::getPrivateKey()` now throws a clear `RuntimeException` naming the path when the PEM file is missing/unreadable, instead of a cryptic `TypeError` from assigning `file_get_contents() === false` to a `?string` property.
- **Bug 2 (Widget audit):** crash-safety moved *into* `WidgetAudit` (`log()` self-guards; new `reject()` owns the rejected-path shape). The three duplicated call-site `try/catch` wrappers are deleted so a future caller can't reintroduce the empty-`APP_KEY`/log-driver 500. Behavior is preserved (no-500 + `widget_audit_failures` counter).

Both fixes come from `.planning/codebase/CONCERNS.md` → Known Bugs.

## Deploy steps
- None. No migrations, no env, no config changes.

## ⚠️ Behavior changes
- DK Bank signed requests now fail fast with a descriptive error if the key file is absent (only reachable when `DK_BANK_ENABLED=true`).
- No change to widget request behavior — same status codes, same audit-failure counter.

## Test plan
- [ ] `php artisan test --filter=DkBankClientPrivateKeyTest` (new)
- [ ] `php artisan test --filter=WidgetAuditTest` (new)
- [ ] `php artisan test --filter=WidgetAuditGuardTest` (regression net, unchanged)
- [ ] `php artisan test` (full suite green)
- [ ] `./vendor/bin/phpstan analyse` (zero baseline)
- [ ] `./vendor/bin/pint --test` (clean)

## Notes
- `WidgetAudit::ipHash()` left `public` (no behavior need to privatize; out of scope).
- Plan: `docs/superpowers/plans/2026-05-28-bugfix-dkbank-key-and-widget-audit.md`

🤖 Generated with Claude Code
EOF
)"
```

---

## Self-Review

**Spec coverage:** Both Known Bugs covered — Bug 1 by Task 1 (guard + 2 tests), Bug 2 by Task 2 (chokepoint guard + reject() + 2 unit tests) and Task 3 (call-site cleanup, regression net). Task 4 enforces the CLAUDE.md gates (full suite, Pint, PHPStan).

**Placeholder scan:** No TBD/TODO/"handle edge cases" — every code step has complete code, every run step has an exact command + expected output.

**Type consistency:** `getPrivateKey(): string` returns the narrowed `$cachedPrivateKey`. `WidgetAudit::log(WidgetAuditEvent, Tenant, ?string, Request): void`, `reject(string, ?string, Request): void`, `ipHash(?string): string`, `recordFailure(\Throwable): void` — call sites in Task 3 match these signatures exactly (`reject($e->getMessage(), $origin, $request)`, `log(WidgetAuditEvent::Request, $tenant, $origin, $request)`, `log(WidgetAuditEvent::Init, $tenant, $origin, $request)`). The `widget_audit_failures` cache key is identical across `recordFailure` and both test assertions.

---

## Out of Scope

- Privatizing `WidgetAudit::ipHash()` (no functional need; would risk an unrelated test).
- The DK Bank stale-key-under-Octane concern (Fragile Areas) — separate lazy-vs-eager loading decision.
- Any other CONCERNS.md item (analytics caching, killswitch route guard, DNS-TXT verify, etc.).
- Splitting into two PRs — kept as one branch per the "fix both" request; commits are grouped (DK Bank = Task 1; Widget = Tasks 2–3) so a reviewer can mentally separate them.
