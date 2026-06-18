# Security & DK Hardening (PR 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship PR 1 of the 2026-06-16 security-hardening batch — the self-contained, deployable-without-PR-2 set of security and DK Bank fixes (spec §1.1–§1.8). Each fix is a *tolerant/configurable* change that keeps the DK killswitch dark and makes no live behavior flip; the prod dual-accept flip stays an ops action.

**Architecture:** Laravel 13 / PHP 8.3 multi-tenant SaaS (VILT). Changes touch: one new HTTP middleware + alias + route wiring (DK killswitch), one Form-validation regex tweak, the DkBankQrService (new private helper + defensive envelope parse), `config/services.php` (two new keys), the widget session-token middleware (passthrough telemetry mirroring `WidgetAudit`'s never-throw posture), `.env.example`, `DocumentProcessor` (inject the SSRF-guarded `GuardedHttpClient` the crawler already uses), and one `authorize()` call in `LeadController::index`. Tests are PHPUnit class-style extending `Tests\TestCase` (RefreshDatabase baked in). Authorization Gates are registered in `AppServiceProvider::boot()` (NO AuthServiceProvider in this project). The custom Larastan rule forbids raw `where('tenant_id', …)` — none of these tasks add tenant queries.

**Tech Stack:** Laravel 13, PHP 8.3 (`declare(strict_types=1)`), PHPUnit (class-style), Pint (laravel preset), PHPStan/Larastan level 6 (baseline MUST stay zero), Inertia + Vue 3 (not touched in PR 1).

---

## File map

**Create**
- `app/Http/Middleware/EnsureDkBankEnabled.php` — abort(404) when `services.dk_bank.enabled` is false
- `tests/Feature/Client/Billing/DkBankKillswitchTest.php` — disabled→404, enabled→reaches controller
- `tests/Unit/Services/Payment/DkBank/DkBankQrServiceCreditAccountTest.php` — exact + suffix credit-account match
- `tests/Unit/Services/Payment/DkBank/DkBankQrServiceMccTest.php` — configured MCC is sent on `startQrSession`
- `tests/Unit/Services/Payment/DkBank/DkBankQrServiceEnvelopeTest.php` — object / array-indexed / neither status envelope shapes
- `tests/Feature/Widget/DualAcceptPassthroughTelemetryTest.php` — passthrough counter + audit on dual-accept fallthrough

**Modify**
- `bootstrap/app.php` — register `dk.enabled` alias
- `routes/web.php` — wrap the four `dk-qr.*` routes with `dk.enabled`
- `app/Http/Controllers/Client/DkBankQrController.php` — RRN regex
- `app/Services/Payment/DkBank/DkBankQrService.php` — `creditAccountMatches()` helper + defensive `extractPaidStatusData()`
- `config/services.php` — `account_match`, `account_match_digits`
- `app/Support/Widget/WidgetAudit.php` — `PASSTHROUGH_COUNTER_KEY` + `passthrough()` writer
- `app/Http/Middleware/RequireWidgetSessionToken.php` — telemetry in the dual-accept branch
- `config/widget.php` — (no change; default already false — verified by existing `StrictModeSystemTest`)
- `.env.example` — `WIDGET_SESSION_DUAL_ACCEPT=false` + TrustProxies/MCC comments
- `app/Services/Knowledge/DocumentProcessor.php` — inject `GuardedHttpClient`, route `fetchUrl` through it
- `app/Http/Controllers/Client/LeadController.php` — `index()` authorize

**Test (modify, where existing tests are affected)**
- `tests/Unit/Services/DocumentProcessorFetchTest.php` — already uses `app(DocumentProcessor::class)`; no change needed but re-run to confirm guarded path still passes
- `tests/Feature/LeadManagementTest.php` — already uses Owner-role `actingAsTenantUser`; no change needed but re-run to confirm authorize passes for Owner

---

### Task 1: DK Bank server-side killswitch — `EnsureDkBankEnabled` middleware + alias + route wiring (§1.1)

**Files:**
- Create `app/Http/Middleware/EnsureDkBankEnabled.php`
- Create `tests/Feature/Client/Billing/DkBankKillswitchTest.php`
- Modify `bootstrap/app.php` (alias block, lines 42–49)
- Modify `routes/web.php` (DK route group, lines 123–137)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Client/Billing/DkBankKillswitchTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Client\Billing;

use App\Models\Plan;
use App\Models\Transaction;
use App\Services\Payment\DkBank\DkBankClient;
use Mockery;
use Tests\TestCase;

class DkBankKillswitchTest extends TestCase
{
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.dk_bank.beneficiary_account', '110158212197');

        $this->plan = Plan::create([
            'name' => 'Pro', 'slug' => 'pro', 'description' => 'd', 'price' => 1000,
            'billing_period' => 'yearly', 'conversations_limit' => 100,
            'messages_per_conversation' => 10, 'knowledge_items_limit' => 10,
            'tokens_limit' => 1000, 'leads_limit' => 100,
            'is_active' => true, 'sort_order' => 1,
        ]);
    }

    public function test_start_returns_404_when_dk_bank_disabled(): void
    {
        config()->set('services.dk_bank.enabled', false);
        $this->actingAsTenantUser();

        $this->post(route('client.billing.dk-qr.start', $this->plan))->assertNotFound();
        $this->assertDatabaseMissing('transactions', ['payment_method' => 'dk_qr']);
    }

    public function test_show_returns_404_when_dk_bank_disabled(): void
    {
        config()->set('services.dk_bank.enabled', false);
        $this->actingAsTenantUser();
        $tx = $this->makeTx(['dk_reference_no' => 'DKQR-KS-001', 'dk_qr_image_base64' => 'B64']);

        $this->get(route('client.billing.dk-qr.show', $tx))->assertNotFound();
    }

    public function test_status_returns_404_when_dk_bank_disabled(): void
    {
        config()->set('services.dk_bank.enabled', false);
        $this->actingAsTenantUser();
        $tx = $this->makeTx(['dk_reference_no' => 'DKQR-KS-002']);

        $this->get(route('client.billing.dk-qr.status', $tx))->assertNotFound();
    }

    public function test_verify_rrn_returns_404_when_dk_bank_disabled(): void
    {
        config()->set('services.dk_bank.enabled', false);
        $this->actingAsTenantUser();
        $tx = $this->makeTx(['dk_reference_no' => 'DKQR-KS-003']);

        $this->postJson(route('client.billing.dk-qr.verify-rrn', $tx), ['rrn' => 'ABCD1234'])
            ->assertNotFound();
    }

    public function test_start_reaches_controller_when_dk_bank_enabled(): void
    {
        config()->set('services.dk_bank.enabled', true);
        $this->actingAsTenantUser();

        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->once()
            ->andReturn(['response_code' => '0000', 'response_data' => ['image' => 'B64PNG']]);
        $this->app->instance(DkBankClient::class, $mock);

        $tx = null;
        $response = $this->post(route('client.billing.dk-qr.start', $this->plan));

        $tx = Transaction::where('tenant_id', $this->tenant->id)->latest('id')->firstOrFail();
        $response->assertRedirect(route('client.billing.dk-qr.show', $tx));
        $this->assertSame('dk_qr', $tx->payment_method);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTx(array $overrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $this->plan->id,
            'amount' => 1000,
            'payment_method' => 'dk_qr',
            'payment_date' => now(),
            'status' => 'awaiting_payment',
        ], $overrides));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run it, expect fail**
  `php artisan test --filter=DkBankKillswitchTest`
  Expected failure: the four `*_returns_404_when_dk_bank_disabled` tests fail because no middleware blocks the route — `start` will currently hit the controller/service (and the `dk.enabled` alias does not yet exist, so route registration would error once added). At this point the alias isn't referenced anywhere so the tests reach the controller and assert 404 against a non-404 response.

- [ ] **Step 3: Implement**

Create `app/Http/Middleware/EnsureDkBankEnabled.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side killswitch for the DK Bank QR payment flow.
 *
 * The DK controller reaches the live DK Bank APIs, so a disabled feature must be
 * unreachable — not just hidden in the UI. Aborts 404 (not 403) so a disabled
 * feature stays invisible: a guessed route name reveals nothing about its existence.
 */
class EnsureDkBankEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('services.dk_bank.enabled'), 404);

        return $next($request);
    }
}
```

Register the alias in `bootstrap/app.php` — add the `dk.enabled` line to the `$middleware->alias([...])` block (keep alphabetical-ish ordering used in the file; insert after `'check.limits'`):

```php
        $middleware->alias([
            'block.expired' => EnsureNotExpired::class,
            'check.limits' => CheckUsageLimits::class,
            'dk.enabled' => EnsureDkBankEnabled::class,
            'require.super_admin' => RequireSuperAdmin::class,
            'validate.widget.domain' => ValidateWidgetDomain::class,
            'widget.session_token' => RequireWidgetSessionToken::class,
            'widget.throttle_ip' => ThrottleWidgetPerIp::class,
        ]);
```

Add the import at the top of `bootstrap/app.php` (after `use App\Http\Middleware\EnsureNotExpired;`):

```php
use App\Http\Middleware\EnsureDkBankEnabled;
```

Wrap the four DK routes in `routes/web.php`. Replace the existing block (current lines 123–137):

```php
        // DK Bank QR payment flow
        Route::post('/dk-qr/{plan}', [DkBankQrController::class, 'start'])
            ->name('dk-qr.start');

        Route::get('/dk-qr/transaction/{transaction}', [DkBankQrController::class, 'show'])
            ->name('dk-qr.show');

        Route::get('/dk-qr/{transaction}/status', [DkBankQrController::class, 'status'])
            ->name('dk-qr.status')
            ->middleware('throttle:60,1');

        Route::post('/dk-qr/{transaction}/verify-rrn', [DkBankQrController::class, 'verifyRrn'])
            ->name('dk-qr.verify-rrn')
            ->middleware('throttle:dk-rrn-verify');
```

with this (a `dk.enabled` middleware group around all four):

```php
        // DK Bank QR payment flow — guarded by the server-side killswitch so a
        // disabled feature is unreachable (404), not merely hidden in the UI.
        Route::middleware('dk.enabled')->group(function () {
            Route::post('/dk-qr/{plan}', [DkBankQrController::class, 'start'])
                ->name('dk-qr.start');

            Route::get('/dk-qr/transaction/{transaction}', [DkBankQrController::class, 'show'])
                ->name('dk-qr.show');

            Route::get('/dk-qr/{transaction}/status', [DkBankQrController::class, 'status'])
                ->name('dk-qr.status')
                ->middleware('throttle:60,1');

            Route::post('/dk-qr/{transaction}/verify-rrn', [DkBankQrController::class, 'verifyRrn'])
                ->name('dk-qr.verify-rrn')
                ->middleware('throttle:dk-rrn-verify');
        });
```

- [ ] **Step 4: Run it, expect pass**
  `php artisan test --filter=DkBankKillswitchTest`

- [ ] **Step 5: Run full suite** (the existing `DkBankQrControllerTest` already sets `config()->set('services.dk_bank.enabled', true)` in its `setUp`, so it stays green)
  `php artisan test`

- [ ] **Step 6: Commit**
  ```
  git add app/Http/Middleware/EnsureDkBankEnabled.php bootstrap/app.php routes/web.php tests/Feature/Client/Billing/DkBankKillswitchTest.php
  git commit -m "$(cat <<'EOF'
feat(billing): server-side DK Bank killswitch middleware (§1.1)

EnsureDkBankEnabled aborts 404 when services.dk_bank.enabled is false,
applied to all four dk-qr.* routes. 404 (not 403) keeps the disabled
feature invisible.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
  ```

---

### Task 2: DK RRN validation — accept hyphen/slash/space (§1.2)

**Files:**
- Modify `app/Http/Controllers/Client/DkBankQrController.php` (validation rule, line 89)
- Modify `tests/Feature/Client/Billing/DkBankQrControllerTest.php` (add hyphenated-RRN test alongside the existing validation test)

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Feature/Client/Billing/DkBankQrControllerTest.php` (after `test_verify_rrn_validates_input`):

```php
    public function test_verify_rrn_accepts_hyphenated_cross_bank_reference(): void
    {
        $this->actingAsTenantUser();
        $tx = Transaction::create([
            'tenant_id' => $this->tenant->id, 'plan_id' => $this->plan->id,
            'amount' => 1000, 'payment_method' => 'dk_qr',
            'payment_date' => now(), 'status' => 'awaiting_payment',
            'dk_reference_no' => 'DKQR-H-HYPHEN',
        ]);

        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->once()
            ->andReturn(['response_code' => '0000', 'response_data' => ['status' => [
                'status' => '0', 'amount' => '1000.00',
                'credit_account' => '110158212197',
                'txn_ts' => now()->addMinute()->toDateTimeString(),
            ]]]);
        $this->app->instance(DkBankClient::class, $mock);

        // Hyphenated cross-bank RRN (e.g. SEL-2309203) must pass validation and reach DK.
        $response = $this->postJson(route('client.billing.dk-qr.verify-rrn', $tx), [
            'rrn' => 'SEL-2309203',
        ]);

        $response->assertOk()->assertJson(['state' => 'paid']);
        $tx->refresh();
        $this->assertSame('approved', $tx->status);
        // Service uppercases + trims; hyphen preserved.
        $this->assertSame('SEL-2309203', $tx->dk_rrn);
    }
```

- [ ] **Step 2: Run it, expect fail**
  `php artisan test --filter=DkBankQrControllerTest::test_verify_rrn_accepts_hyphenated_cross_bank_reference`
  Expected failure: the current rule `rrn => 'required|alpha_num|min:4|max:32'` rejects the hyphen → `422` with a `rrn` validation error, so the `assertOk()` fails.

- [ ] **Step 3: Implement**

In `app/Http/Controllers/Client/DkBankQrController.php`, replace the `$validated` block in `verifyRrn` (current line 88–90):

```php
        $validated = $request->validate([
            'rrn' => 'required|alpha_num|min:4|max:32',
        ]);
```

with:

```php
        $validated = $request->validate([
            // Real cross-bank RRNs contain hyphens/slashes/spaces (e.g. "SEL-2309203").
            // The service already strtoupper(trim())s the value, so this loosened rule is safe.
            // Max 32 (not 40) to match the dk_rrn VARCHAR(32) column.
            'rrn' => ['required', 'string', 'regex:/^[A-Za-z0-9\/\- ]{4,32}$/'],
        ]);
```

- [ ] **Step 4: Run it, expect pass**
  `php artisan test --filter=DkBankQrControllerTest`
  (The existing `test_verify_rrn_validates_input` — which sends `'rrn' => 'a'` — still fails validation because 'a' is below the 4-char minimum, so it stays green.)

- [ ] **Step 5: Run full suite**
  `php artisan test`

- [ ] **Step 6: Commit**
  ```
  git add app/Http/Controllers/Client/DkBankQrController.php tests/Feature/Client/Billing/DkBankQrControllerTest.php
  git commit -m "$(cat <<'EOF'
fix(billing): accept hyphen/slash/space in DK RRN validation (§1.2)

Real cross-bank reference numbers (e.g. SEL-2309203) contain hyphens;
the old alpha_num rule rejected legitimate payers before they reached DK.
Service already uppercases+trims, so the loosened regex is safe.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
  ```

---

### Task 3: DK credit-account match — `creditAccountMatches()` + config (§1.3)

**Files:**
- Modify `config/services.php` (dk_bank block, lines 51–64)
- Modify `app/Services/Payment/DkBank/DkBankQrService.php` (both compare sites: `verifyByRrn` line 110, `interpretStatusResponse` lines 224–234; add private helper)
- Create `tests/Unit/Services/Payment/DkBank/DkBankQrServiceCreditAccountTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Payment/DkBank/DkBankQrServiceCreditAccountTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\DkBank;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Payment\DkBank\DkBankClient;
use App\Services\Payment\DkBank\DkBankQrService;
use App\Services\Payment\DkBank\DTO\DkStatusState;
use Mockery;
use Tests\TestCase;

class DkBankQrServiceCreditAccountTest extends TestCase
{
    private Transaction $tx;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.dk_bank.beneficiary_account', '110158212197');

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active', 'trial_ends_at' => now()]);
        $plan = Plan::create([
            'name' => 'P', 'slug' => 'p', 'description' => 'd', 'price' => 1000,
            'billing_period' => 'yearly', 'conversations_limit' => 1, 'messages_per_conversation' => 1,
            'knowledge_items_limit' => 1, 'tokens_limit' => 1, 'leads_limit' => 1,
            'is_active' => true, 'sort_order' => 1,
        ]);
        $this->tx = Transaction::create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'amount' => 1000,
            'payment_method' => 'dk_qr', 'payment_date' => now(),
            'status' => 'awaiting_payment', 'dk_reference_no' => 'DKQR-CA-AAAAAA',
        ]);
    }

    /**
     * @param  array<string, mixed>  $statusOverrides
     */
    private function mockPaid(array $statusOverrides = []): void
    {
        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->once()
            ->andReturn(['response_code' => '0000', 'response_data' => ['status' => array_merge([
                'status' => '0', 'amount' => '1000.00',
                'credit_account' => '110158212197',
                'txn_ts' => now()->addMinute()->toDateTimeString(),
            ], $statusOverrides)]]);
        $this->app->instance(DkBankClient::class, $mock);
    }

    public function test_exact_mode_matches_identical_account(): void
    {
        config()->set('services.dk_bank.account_match', 'exact');
        $this->mockPaid(['credit_account' => '110158212197']);

        $result = $this->app->make(DkBankQrService::class)->verifyByRrn($this->tx, 'RRN_EXACT_OK');

        $this->assertSame(DkStatusState::Paid, $result->state);
    }

    public function test_exact_mode_normalizes_spaces_and_case(): void
    {
        // Same digits, DK returns with spacing/case noise — normalization must still match.
        config()->set('services.dk_bank.account_match', 'exact');
        $this->mockPaid(['credit_account' => ' 110158212197 ']);

        $result = $this->app->make(DkBankQrService::class)->verifyByRrn($this->tx, 'RRN_EXACT_NORM');

        $this->assertSame(DkStatusState::Paid, $result->state);
    }

    public function test_exact_mode_rejects_suffix_only_match(): void
    {
        // A masked/last-4 reported account must NOT pass in exact mode.
        config()->set('services.dk_bank.account_match', 'exact');
        $this->mockPaid(['credit_account' => '2197']);

        $result = $this->app->make(DkBankQrService::class)->verifyByRrn($this->tx, 'RRN_EXACT_FAIL');

        $this->assertSame(DkStatusState::Failed, $result->state);
        $this->tx->refresh();
        $this->assertSame('awaiting_payment', $this->tx->status);
    }

    public function test_suffix_mode_matches_last_four_digits(): void
    {
        config()->set('services.dk_bank.account_match', 'suffix');
        config()->set('services.dk_bank.account_match_digits', 4);
        $this->mockPaid(['credit_account' => 'XXXXXXXX2197']);

        $result = $this->app->make(DkBankQrService::class)->verifyByRrn($this->tx, 'RRN_SUFFIX_OK');

        $this->assertSame(DkStatusState::Paid, $result->state);
    }

    public function test_suffix_mode_rejects_different_last_four(): void
    {
        config()->set('services.dk_bank.account_match', 'suffix');
        config()->set('services.dk_bank.account_match_digits', 4);
        $this->mockPaid(['credit_account' => 'XXXXXXXX9999']);

        $result = $this->app->make(DkBankQrService::class)->verifyByRrn($this->tx, 'RRN_SUFFIX_FAIL');

        $this->assertSame(DkStatusState::Failed, $result->state);
        $this->tx->refresh();
        $this->assertSame('awaiting_payment', $this->tx->status);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run it, expect fail**
  `php artisan test --filter=DkBankQrServiceCreditAccountTest`
  Expected failure: `test_exact_mode_normalizes_spaces_and_case` and both `suffix_mode_*` matching tests fail. The current `(string) $status['credit_account'] !== (string) config(...)` does a raw, un-normalized exact compare: the spaced value `' 110158212197 '` does not `===` `'110158212197'` (fails), and `suffix` mode does not exist yet (the masked value never matches the full account). `account_match`/`account_match_digits` config keys are also absent.

- [ ] **Step 3: Implement**

Add the two config keys to `config/services.php` `dk_bank` block — insert after `'beneficiary_account'` (line 60):

```php
        'beneficiary_account' => env('DK_BANK_BENEFICIARY_ACCOUNT'),
        // Credit-account match strategy: 'exact' (default — no behavior change until
        // DK confirms it returns a masked account) or 'suffix' (compare last N digits).
        'account_match' => env('DK_BANK_ACCOUNT_MATCH', 'exact'),
        'account_match_digits' => (int) env('DK_BANK_ACCOUNT_MATCH_DIGITS', 4),
        'mcc_code' => env('DK_BANK_MCC_CODE', '5817'),
```

In `app/Services/Payment/DkBank/DkBankQrService.php`, replace the credit compare in `verifyByRrn` (current line 110):

```php
        if ((string) $status['credit_account'] !== (string) config('services.dk_bank.beneficiary_account')) {
            return new DkStatusResult(state: DkStatusState::Failed, errorMessage: 'Payment was not credited to our account. Contact support.');
        }
```

with:

```php
        if (! $this->creditAccountMatches((string) $status['credit_account'])) {
            return new DkStatusResult(state: DkStatusState::Failed, errorMessage: 'Payment was not credited to our account. Contact support.');
        }
```

And replace the credit compare in `interpretStatusResponse` (current lines 224–234):

```php
        $reportedCredit = (string) ($status['credit_account'] ?? '');
        $expectedCredit = (string) config('services.dk_bank.beneficiary_account');
        if ($reportedCredit !== $expectedCredit) {
            Log::warning('[DK QR] (NO $) Status credit_account mismatch', [
                'transaction_id' => $transaction->id,
                'expected' => $expectedCredit,
                'reported' => $reportedCredit,
            ]);

            return new DkStatusResult(state: DkStatusState::Failed, errorMessage: 'Credit account mismatch');
        }
```

with:

```php
        $reportedCredit = (string) ($status['credit_account'] ?? '');
        if (! $this->creditAccountMatches($reportedCredit)) {
            Log::warning('[DK QR] (NO $) Status credit_account mismatch', [
                'transaction_id' => $transaction->id,
                'expected' => (string) config('services.dk_bank.beneficiary_account'),
                'reported' => $reportedCredit,
            ]);

            return new DkStatusResult(state: DkStatusState::Failed, errorMessage: 'Credit account mismatch');
        }
```

Add the private helper at the end of the class, immediately before the closing `}` of `DkBankQrService` (after `extractPaidStatusData`):

```php
    /**
     * Compare DK's reported credit account against our beneficiary account.
     *
     * Both sides are normalized (whitespace stripped, uppercased) before comparing.
     * 'exact' (default) requires a full normalized match. 'suffix' compares only the
     * last N digits (config account_match_digits, default 4) — for the case where DK
     * confirms it returns a masked/reformatted account.
     */
    private function creditAccountMatches(string $reported): bool
    {
        $normalize = static fn (string $v): string => strtoupper(str_replace(' ', '', trim($v)));

        $reportedNorm = $normalize($reported);
        $expectedNorm = $normalize((string) config('services.dk_bank.beneficiary_account'));

        if (config('services.dk_bank.account_match') === 'suffix') {
            $digits = max(1, (int) config('services.dk_bank.account_match_digits', 4));
            $reportedNorm = substr($reportedNorm, -$digits);
            $expectedNorm = substr($expectedNorm, -$digits);
        }

        return $reportedNorm !== '' && hash_equals($expectedNorm, $reportedNorm);
    }
```

- [ ] **Step 4: Run it, expect pass**
  `php artisan test --filter=DkBankQrServiceCreditAccountTest`

- [ ] **Step 5: Run full suite** (existing `DkBankQrServiceVerifyRrnTest` / `DkBankQrServiceCheckTest` use the exact-matching account `110158212197` with default `account_match` unset → falls through to `'exact'` default, stays green)
  `php artisan test`

- [ ] **Step 6: Commit**
  ```
  git add config/services.php app/Services/Payment/DkBank/DkBankQrService.php tests/Unit/Services/Payment/DkBank/DkBankQrServiceCreditAccountTest.php
  git commit -m "$(cat <<'EOF'
fix(billing): tolerant DK credit-account match (exact|suffix) (§1.3)

creditAccountMatches() normalizes both sides (strip spaces, uppercase)
before comparing. New config services.dk_bank.account_match defaults to
'exact' (no behavior change); 'suffix' compares last N digits for the
masked-account case DK may return. Both verify paths use the helper.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
  ```

---

### Task 4: DK MCC code — assertion test that the configured value is sent (§1.4)

No logic change — `mcc_code` is already config-driven (`config/services.php` line 61, sent at `DkBankQrService::startQrSession` line 35). This task pins the behavior with a test and documents the choice (the `.env.example` comment lands in Task 7).

**Files:**
- Create `tests/Unit/Services/Payment/DkBank/DkBankQrServiceMccTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Payment/DkBank/DkBankQrServiceMccTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\DkBank;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Payment\DkBank\DkBankClient;
use App\Services\Payment\DkBank\DkBankQrService;
use Mockery;
use Tests\TestCase;

class DkBankQrServiceMccTest extends TestCase
{
    /**
     * @return array{0: Tenant, 1: Plan}
     */
    private function makeTenantAndPlan(): array
    {
        $tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme', 'status' => 'active', 'trial_ends_at' => now(),
        ]);
        $plan = Plan::create([
            'name' => 'Pro', 'slug' => 'pro', 'description' => 'd', 'price' => 1000,
            'billing_period' => 'yearly', 'conversations_limit' => 100, 'messages_per_conversation' => 10,
            'knowledge_items_limit' => 10, 'tokens_limit' => 1000, 'leads_limit' => 100,
            'is_active' => true, 'sort_order' => 1,
        ]);

        return [$tenant, $plan];
    }

    public function test_start_qr_session_sends_configured_mcc_code(): void
    {
        config()->set('services.dk_bank.beneficiary_account', '110158212197');
        // Set a non-default MCC to prove the configured value (not a hardcode) is sent.
        config()->set('services.dk_bank.mcc_code', '5734');

        [$tenant, $plan] = $this->makeTenantAndPlan();

        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')
            ->once()
            ->with('/v1/generate_qr', Mockery::on(function (array $body): bool {
                return ($body['mcc_code'] ?? null) === '5734';
            }))
            ->andReturn(['response_code' => '0000', 'response_data' => ['image' => 'B64']]);
        $this->app->instance(DkBankClient::class, $mock);

        $this->app->make(DkBankQrService::class)->startQrSession($tenant, $plan);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run it, expect fail**
  `php artisan test --filter=DkBankQrServiceMccTest`
  Expected failure (RED state for a no-logic-change task): the test is a behavior pin. To confirm it actually exercises the assertion, temporarily it WILL pass against current code — so first run it to confirm GREEN immediately (the value is already config-driven). If it does NOT pass, the `postSigned` payload shape diverged from the spec and must be investigated before proceeding. Document the result: this is a characterization test, expected to pass on first run against the existing config-driven code.

  > Note for the implementer: because §1.4 is explicitly "no logic change", this task's test is a *characterization* test, not a red→green TDD cycle. Run it; expect PASS. If it fails, STOP — the `mcc_code` wiring regressed and must be fixed before continuing.

- [ ] **Step 3: Implement**
  No production change. (The behavior under test — `'mcc_code' => config('services.dk_bank.mcc_code')` at `DkBankQrService::startQrSession` — already exists.)

- [ ] **Step 4: Run it, expect pass**
  `php artisan test --filter=DkBankQrServiceMccTest`

- [ ] **Step 5: Run full suite**
  `php artisan test`

- [ ] **Step 6: Commit**
  ```
  git add tests/Unit/Services/Payment/DkBank/DkBankQrServiceMccTest.php
  git commit -m "$(cat <<'EOF'
test(billing): pin that startQrSession sends the configured MCC (§1.4)

Characterization test proving mcc_code is config-driven (DK may require
5734 vs the 5817 default). No logic change; the .env.example doc note
lands with the dual-accept commit.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
  ```

---

### Task 5: DK status-envelope parsing — defensive object/array-indexed parse (§1.5)

**Files:**
- Modify `app/Services/Payment/DkBank/DkBankQrService.php` (`extractPaidStatusData`, lines 253–258)
- Create `tests/Unit/Services/Payment/DkBank/DkBankQrServiceEnvelopeTest.php`

`extractPaidStatusData` is `private`, so the unit test drives it through the public `verifyByRrn` with each envelope shape.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Payment/DkBank/DkBankQrServiceEnvelopeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\DkBank;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Payment\DkBank\DkBankClient;
use App\Services\Payment\DkBank\DkBankQrService;
use App\Services\Payment\DkBank\DTO\DkStatusState;
use Mockery;
use Tests\TestCase;

class DkBankQrServiceEnvelopeTest extends TestCase
{
    private Transaction $tx;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.dk_bank.beneficiary_account', '110158212197');

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active', 'trial_ends_at' => now()]);
        $plan = Plan::create([
            'name' => 'P', 'slug' => 'p', 'description' => 'd', 'price' => 1000,
            'billing_period' => 'yearly', 'conversations_limit' => 1, 'messages_per_conversation' => 1,
            'knowledge_items_limit' => 1, 'tokens_limit' => 1, 'leads_limit' => 1,
            'is_active' => true, 'sort_order' => 1,
        ]);
        $this->tx = Transaction::create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'amount' => 1000,
            'payment_method' => 'dk_qr', 'payment_date' => now(),
            'status' => 'awaiting_payment', 'dk_reference_no' => 'DKQR-EN-AAAAAA',
        ]);
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function mock(array $responseData): void
    {
        $mock = Mockery::mock(DkBankClient::class);
        $mock->shouldReceive('generateRequestId')->andReturn(str_repeat('a', 32));
        $mock->shouldReceive('postSigned')->once()
            ->andReturn(['response_code' => '0000', 'response_data' => $responseData]);
        $this->app->instance(DkBankClient::class, $mock);
    }

    /**
     * @return array<string, mixed>
     */
    private function paidStatus(): array
    {
        return [
            'status' => '0', 'amount' => '1000.00',
            'credit_account' => '110158212197',
            'txn_ts' => now()->addMinute()->toDateTimeString(),
        ];
    }

    public function test_object_shaped_status_envelope_is_parsed(): void
    {
        // response_data.status (object shape)
        $this->mock(['status' => $this->paidStatus()]);

        $result = $this->app->make(DkBankQrService::class)->verifyByRrn($this->tx, 'RRN_OBJECT');

        $this->assertSame(DkStatusState::Paid, $result->state);
    }

    public function test_array_indexed_status_envelope_is_parsed(): void
    {
        // response_data[0].status (array-indexed shape) — previously yielded null silently
        $this->mock([0 => ['status' => $this->paidStatus()]]);

        $result = $this->app->make(DkBankQrService::class)->verifyByRrn($this->tx, 'RRN_INDEXED');

        $this->assertSame(DkStatusState::Paid, $result->state);
        $this->tx->refresh();
        $this->assertSame('approved', $this->tx->status);
    }

    public function test_neither_shape_present_is_treated_as_not_paid(): void
    {
        // No status block at all — must NOT approve.
        $this->mock(['something_else' => true]);

        $result = $this->app->make(DkBankQrService::class)->verifyByRrn($this->tx, 'RRN_NEITHER');

        $this->assertSame(DkStatusState::Failed, $result->state);
        $this->tx->refresh();
        $this->assertSame('awaiting_payment', $this->tx->status);
    }
}
```

- [ ] **Step 2: Run it, expect fail**
  `php artisan test --filter=DkBankQrServiceEnvelopeTest`
  Expected failure: `test_array_indexed_status_envelope_is_parsed` fails — current `extractPaidStatusData` only reads `$response['response_data']['status']`, so the `response_data[0].status` shape yields `null`, `verifyByRrn` returns `Failed`, and the transaction never flips to `approved`.

- [ ] **Step 3: Implement**

In `app/Services/Payment/DkBank/DkBankQrService.php`, replace `extractPaidStatusData` (current lines 245–258):

```php
    /**
     * Extracts the inner status block from DK's `/v1/intra-transaction/status`
     * envelope when DK reports a successful credit (`status === '0'`).
     * Returns null in every "not paid" case so callers branch on a single check.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    private function extractPaidStatusData(array $response): ?array
    {
        $status = $response['response_data']['status'] ?? null;

        return is_array($status) && ($status['status'] ?? null) === '0' ? $status : null;
    }
```

with:

```php
    /**
     * Extracts the inner status block from DK's `/v1/intra-transaction/status`
     * envelope when DK reports a successful credit (`status === '0'`).
     *
     * DK has been seen returning two shapes: object (`response_data.status`) and
     * array-indexed (`response_data[0].status`). The indexed shape previously
     * yielded null silently, so the payment never flipped to paid. Try the object
     * shape first, then fall back to the indexed shape.
     * Returns null in every "not paid" case so callers branch on a single check.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    private function extractPaidStatusData(array $response): ?array
    {
        $data = $response['response_data'] ?? [];
        $status = $data['status'] ?? ($data[0]['status'] ?? null);

        return is_array($status) && ($status['status'] ?? null) === '0' ? $status : null;
    }
```

- [ ] **Step 4: Run it, expect pass**
  `php artisan test --filter=DkBankQrServiceEnvelopeTest`

- [ ] **Step 5: Run full suite**
  `php artisan test`

- [ ] **Step 6: Commit**
  ```
  git add app/Services/Payment/DkBank/DkBankQrService.php tests/Unit/Services/Payment/DkBank/DkBankQrServiceEnvelopeTest.php
  git commit -m "$(cat <<'EOF'
fix(billing): defensive DK status-envelope parse (object|indexed) (§1.5)

extractPaidStatusData now tries response_data.status, falls back to
response_data[0].status. The array-indexed shape previously yielded null
silently and a real payment never flipped to paid.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
  ```

---

### Task 6: Widget dual-accept passthrough telemetry (§1.6, code half)

Mirror `WidgetAudit`'s never-throw counter pattern: add a `passthrough()` writer + `PASSTHROUGH_COUNTER_KEY` to `WidgetAudit`, and call it from the dual-accept fallthrough branch of `RequireWidgetSessionToken`. The strict-mode 401 case is already covered by the existing `StrictModeSystemTest`; this task adds the passthrough-counter test.

**Files:**
- Modify `app/Support/Widget/WidgetAudit.php` (add constant + `passthrough()` method)
- Modify `app/Http/Middleware/RequireWidgetSessionToken.php` (dual-accept branch, lines 27–36)
- Create `tests/Feature/Widget/DualAcceptPassthroughTelemetryTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Widget/DualAcceptPassthroughTelemetryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Enums\Widget\WidgetAuditEvent;
use App\Models\Tenant;
use App\Support\Widget\WidgetAudit;
use App\Support\Widget\WidgetErrors;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\AuthenticatesWidget;
use Tests\TestCase;

class DualAcceptPassthroughTelemetryTest extends TestCase
{
    use AuthenticatesWidget;
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAuthenticatesWidget(); // forces session_dual_accept=false
        $this->tenant = $this->createWidgetTenant();
    }

    public function test_dual_accept_passthrough_increments_counter(): void
    {
        config()->set('widget.session_dual_accept', true);
        Cache::forget(WidgetAudit::PASSTHROUGH_COUNTER_KEY);

        // Token-less request — dual-accept lets it through with a Deprecation header.
        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertSuccessful();
        $this->assertSame('true', $response->headers->get('Deprecation'));
        $this->assertSame(1, (int) Cache::get(WidgetAudit::PASSTHROUGH_COUNTER_KEY));
    }

    public function test_strict_mode_does_not_increment_passthrough_counter(): void
    {
        // session_dual_accept already false from setUp.
        Cache::forget(WidgetAudit::PASSTHROUGH_COUNTER_KEY);

        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/message', ['api_key' => $this->tenant->api_key]);

        $response->assertStatus(401)
            ->assertJson(['error' => WidgetErrors::SESSION_TOKEN_REQUIRED]);
        $this->assertNull(Cache::get(WidgetAudit::PASSTHROUGH_COUNTER_KEY));
    }

    public function test_bearer_request_does_not_increment_passthrough_counter(): void
    {
        Cache::forget(WidgetAudit::PASSTHROUGH_COUNTER_KEY);
        $headers = $this->widgetHeaders($this->tenant);

        $this->withHeaders($headers)
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key])
            ->assertOk();

        $this->assertNull(Cache::get(WidgetAudit::PASSTHROUGH_COUNTER_KEY));
    }

    public function test_passthrough_telemetry_never_throws_when_cache_fails(): void
    {
        config()->set('widget.session_dual_accept', true);

        // Even if the counter write blows up, the passthrough request must still succeed.
        Cache::shouldReceive('increment')
            ->with(WidgetAudit::PASSTHROUGH_COUNTER_KEY)
            ->andThrow(new \RuntimeException('cache down'));

        $response = $this->withHeaders(['Origin' => 'https://example.com'])
            ->postJson('/api/v1/widget/conversation', ['api_key' => $this->tenant->api_key]);

        $response->assertSuccessful();
        $this->assertSame('true', $response->headers->get('Deprecation'));
    }
}
```

- [ ] **Step 2: Run it, expect fail**
  `php artisan test --filter=DualAcceptPassthroughTelemetryTest`
  Expected failure: `App\Support\Widget\WidgetAudit::PASSTHROUGH_COUNTER_KEY` is undefined (fatal/error) and the dual-accept branch never increments anything — `test_dual_accept_passthrough_increments_counter` asserts `1` but gets `0`/null.

- [ ] **Step 3: Implement**

In `app/Support/Widget/WidgetAudit.php`, add the counter key constant after `FAILURE_COUNTER_KEY` (line 17):

```php
    public const FAILURE_COUNTER_KEY = 'widget_audit_failures';

    public const PASSTHROUGH_COUNTER_KEY = 'widget_dual_accept_passthrough';
```

Add a `passthrough()` method after `reject()` (before `ipHash()`), mirroring the never-throw posture:

```php
    /**
     * Records a dual-accept passthrough: a token-less widget write that was let
     * through because session_dual_accept is true. Increments a cache counter
     * and logs to the audit channel so ops can confirm zero passthroughs before
     * the strict-mode live flip. Never throws — telemetry must not break the request.
     */
    public static function passthrough(?string $origin, Request $request): void
    {
        try {
            Cache::increment(self::PASSTHROUGH_COUNTER_KEY);
        } catch (\Throwable) {
        }

        try {
            Log::channel(self::CHANNEL)->warning('widget_dual_accept_passthrough', [
                'origin' => $origin,
                'ip_hash' => self::ipHash($request->ip()),
                'endpoint' => $request->path(),
                'method' => $request->method(),
            ]);
        } catch (\Throwable $e) {
            self::recordFailure($e);
        }
    }
```

In `app/Http/Middleware/RequireWidgetSessionToken.php`, replace the dual-accept fallthrough branch (current lines 27–36):

```php
        // Missing Bearer
        if ($bearer === null) {
            if ($dualAccept) {
                $response = $next($request);
                $response->headers->set('Deprecation', 'true');

                return $response;
            }

            return response()->json(['error' => WidgetErrors::SESSION_TOKEN_REQUIRED], 401);
        }
```

with:

```php
        // Missing Bearer
        if ($bearer === null) {
            if ($dualAccept) {
                // Telemetry: ops watch this counter; once it sits at zero the
                // strict-mode live flip (dual_accept=false) is safe.
                $origin = CanonicalOrigin::from($request->header('Origin') ?? $request->header('Referer'));
                WidgetAudit::passthrough($origin, $request);

                $response = $next($request);
                $response->headers->set('Deprecation', 'true');

                return $response;
            }

            return response()->json(['error' => WidgetErrors::SESSION_TOKEN_REQUIRED], 401);
        }
```

(`CanonicalOrigin` and `WidgetAudit` are already imported at the top of the middleware.)

- [ ] **Step 4: Run it, expect pass**
  `php artisan test --filter=DualAcceptPassthroughTelemetryTest`

- [ ] **Step 5: Run full suite** (existing `StrictModeSystemTest::test_dual_accept_env_override_restores_passthrough` still asserts the Deprecation header and a successful passthrough — the added counter write doesn't change the response, so it stays green)
  `php artisan test`

- [ ] **Step 6: Commit**
  ```
  git add app/Support/Widget/WidgetAudit.php app/Http/Middleware/RequireWidgetSessionToken.php tests/Feature/Widget/DualAcceptPassthroughTelemetryTest.php
  git commit -m "$(cat <<'EOF'
feat(widget): dual-accept passthrough telemetry counter (§1.6)

WidgetAudit::passthrough() increments widget_dual_accept_passthrough and
logs to the audit channel (never-throw, mirrors widget_audit_failures)
whenever dual-accept lets a token-less write through. Ops watch this to
confirm zero passthroughs before the strict-mode live flip.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
  ```

---

### Task 7: `.env.example` — flip dual-accept default + TrustProxies/MCC comments (§1.4 doc + §1.6 doc)

No code path runs against `.env.example` in tests, so this task has no TDD test of its own; it is verified by the existing `StrictModeSystemTest::test_config_file_default_is_false` (already green — `config/widget.php` already defaults false) and a grep assertion below.

**Files:**
- Modify `.env.example` (DK block lines 95–104; widget block lines 106–111)

- [ ] **Step 1: Write the failing check** (grep-based, not a PHPUnit test)
  `grep -n 'WIDGET_SESSION_DUAL_ACCEPT' .env.example`
  Expected current output: `107:WIDGET_SESSION_DUAL_ACCEPT=true` — this is the value to flip.

- [ ] **Step 2: Run it, expect fail**
  Run the grep above; the line shows `=true`. The "failure" is that the shipped example default contradicts the secure runtime default (false).

- [ ] **Step 3: Implement**

In `.env.example`, replace the MCC line (current line 103):

```
DK_BANK_MCC_CODE=5817
```

with:

```
# DK merchant category code. Default 5817 (digital goods); DK may require 5734.
# Confirm the production value with DK before go-live — see the deploy runbook.
DK_BANK_MCC_CODE=5817
# Credit-account match: 'exact' (default) or 'suffix' (compare last N digits) if
# DK confirms it returns a masked/reformatted account on status callbacks.
DK_BANK_ACCOUNT_MATCH=exact
DK_BANK_ACCOUNT_MATCH_DIGITS=4
```

And replace the widget session block header + dual-accept line (current lines 106–107):

```
# Widget session tokens — see docs/superpowers/specs/2026-05-18-widget-session-tokens-design.md
WIDGET_SESSION_DUAL_ACCEPT=true
```

with:

```
# Widget session tokens — see docs/superpowers/specs/2026-05-18-widget-session-tokens-design.md
# Strict mode (false) requires a JWT Bearer on every widget write. Flip to true
# ONLY as a temporary migration aid, and ONLY after TRUSTED_PROXIES is configured
# — otherwise per-IP binding and rate limits collapse to the proxy IP. Watch the
# widget_dual_accept_passthrough counter; flip back to false once it sits at zero.
WIDGET_SESSION_DUAL_ACCEPT=false
```

- [ ] **Step 4: Run it, expect pass**
  `grep -n 'WIDGET_SESSION_DUAL_ACCEPT=false' .env.example` (should match)
  `php artisan test --filter=StrictModeSystemTest` (the `test_config_file_default_is_false` characterization test stays green; nothing in code changed)

- [ ] **Step 5: Run full suite**
  `php artisan test`

- [ ] **Step 6: Commit**
  ```
  git add .env.example
  git commit -m "$(cat <<'EOF'
docs(env): default WIDGET_SESSION_DUAL_ACCEPT=false + DK config notes (§1.4, §1.6)

Ship the secure default; document the TRUSTED_PROXIES precondition and the
passthrough counter for the live flip. Add DK_BANK_ACCOUNT_MATCH/_DIGITS and
the MCC 5817-vs-5734 note.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
  ```

---

### Task 8: Crawler SSRF gap on manual add — route `DocumentProcessor::fetchUrl` through `GuardedHttpClient` (§1.7)

Inject `GuardedHttpClient` into `DocumentProcessor` with a **default** so the ~10 existing no-arg `new DocumentProcessor` test instantiations keep working. Replace the raw `Http::timeout(30)->withOptions(['allow_redirects' => false])->get($url)` with the IP-pinned, redirect-revalidating client. Keep the early `SafeExternalUrl::isSafe` guard as cheap pre-validation.

**Files:**
- Modify `app/Services/Knowledge/DocumentProcessor.php` (imports, add constructor, rewrite `fetchUrl` lines 59–75)
- Create test method in `tests/Unit/Services/DocumentProcessorFetchTest.php` (add a guarded-routing assertion; the existing tests in that file already exercise the public path)

- [ ] **Step 1: Write the failing test**

Add this method to `tests/Unit/Services/DocumentProcessorFetchTest.php` (after `test_fetch_url_rejects_loopback`), plus the imports it needs at the top of the file:

```php
use App\Exceptions\BlockedAddressException;
use App\Services\Crawler\GuardedHttpClient;
use Mockery;
```

```php
    public function test_fetch_url_routes_through_guarded_http_client(): void
    {
        // The guarded client is the only thing that should perform the fetch —
        // prove DocumentProcessor delegates to it (IP-pinned, redirect-revalidating).
        $guard = Mockery::mock(GuardedHttpClient::class);
        $guard->shouldReceive('get')
            ->once()
            ->with('http://1.1.1.1/guarded')
            ->andReturn(new \Illuminate\Http\Client\Response(
                new \GuzzleHttp\Psr7\Response(200, [], '<p>Guarded body content here.</p>')
            ));
        $this->app->instance(GuardedHttpClient::class, $guard);

        $body = $this->app->make(DocumentProcessor::class)->fetchUrl('http://1.1.1.1/guarded');

        $this->assertStringContainsString('Guarded body content here.', $body);
    }

    public function test_fetch_url_propagates_guarded_block_for_rebound_host(): void
    {
        // Simulate the DNS-rebind case the raw Http client missed: name-time
        // validation passed, but the guarded client blocks the resolved private IP.
        $guard = Mockery::mock(GuardedHttpClient::class);
        $guard->shouldReceive('get')
            ->once()
            ->andThrow(new BlockedAddressException('Blocked non-public address: rebind.example'));
        $this->app->instance(GuardedHttpClient::class, $guard);

        $this->expectException(BlockedAddressException::class);
        // Public-literal URL passes the cheap SafeExternalUrl pre-check, then the
        // guarded client re-resolves and blocks.
        $this->app->make(DocumentProcessor::class)->fetchUrl('http://1.1.1.1/rebind');
    }
```

Add a `tearDown` to the file if one is not present (this file currently has none) so the Mockery expectations are verified:

```php
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
```

- [ ] **Step 2: Run it, expect fail**
  `php artisan test --filter=DocumentProcessorFetchTest::test_fetch_url_routes_through_guarded_http_client`
  Expected failure: `DocumentProcessor` does not depend on `GuardedHttpClient` yet — it calls the `Http` facade directly, so the mocked `get()` expectation is never satisfied and Mockery fails the `->once()` expectation (and the real `Http` call to `1.1.1.1` is not faked).

- [ ] **Step 3: Implement**

In `app/Services/Knowledge/DocumentProcessor.php`, update the imports (top of file, replacing the `Http` facade import which `fetchUrl` no longer needs):

```php
use App\Models\KnowledgeItem;
use App\Rules\SafeExternalUrl;
use App\Services\Crawler\GuardedHttpClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;
```

Add a constructor (the class currently has none) immediately after the constants, with a default so existing no-arg `new DocumentProcessor` instantiations resolve their own guard:

```php
class DocumentProcessor
{
    private const CHUNK_SIZE = 500;

    private const CHUNK_OVERLAP = 50;

    private const MIN_CHUNK_CHARS = 50;

    private GuardedHttpClient $http;

    public function __construct(?GuardedHttpClient $http = null)
    {
        // Default-construct so the ~10 no-arg `new DocumentProcessor` test sites
        // and any container resolution keep working; the container injects the
        // shared instance in production via type-hint.
        $this->http = $http ?? new GuardedHttpClient;
    }
```

Replace `fetchUrl` (current lines 58–75):

```php
    /** Fetch the raw body of a public URL (SSRF-guarded, redirects disabled). */
    public function fetchUrl(string $url): string
    {
        if (! SafeExternalUrl::isSafe($url)) {
            Log::warning('[DocumentProcessor] Rejected non-public URL at fetch time', ['url' => $url]);
            throw new \Exception("Refusing to fetch non-public URL: {$url}");
        }

        $response = Http::timeout(30)
            ->withOptions(['allow_redirects' => false])
            ->get($url);

        if (! $response->successful()) {
            throw new \Exception("Failed to fetch URL: {$url}");
        }

        return $response->body();
    }
```

with:

```php
    /**
     * Fetch the raw body of a public URL through the SSRF-guarded HTTP client.
     *
     * The early SafeExternalUrl::isSafe check is cheap name-time pre-validation;
     * GuardedHttpClient is the real defense — it pins each request to the host's
     * pre-validated IP set (CURLOPT_RESOLVE) and re-validates every redirect hop,
     * closing the DNS-rebind TOCTOU that the bare Http client left open here.
     */
    public function fetchUrl(string $url): string
    {
        if (! SafeExternalUrl::isSafe($url)) {
            Log::warning('[DocumentProcessor] Rejected non-public URL at fetch time', ['url' => $url]);
            throw new \Exception("Refusing to fetch non-public URL: {$url}");
        }

        $response = $this->http->get($url);

        if (! $response->successful()) {
            throw new \Exception("Failed to fetch URL: {$url}");
        }

        return $response->body();
    }
```

- [ ] **Step 4: Run it, expect pass**
  `php artisan test --filter=DocumentProcessorFetchTest`

  > Behavior note for the implementer: the existing `test_non_2xx_response_surfaces_as_exception` and `test_public_url_is_fetched_normally` in this file use `Http::fake([...])` against `1.1.1.1`. `GuardedHttpClient` still uses the `Http` facade under the hood (via `Http::withHeaders(...)->get(...)`), and `1.1.1.1` is a public literal that `SafeExternalUrl::resolvePublicIps` resolves to itself, so `Http::fake` continues to intercept those calls — these two tests stay green. If `resolvePublicIps('1.1.1.1')` returns `[]` in the test env (it should not for a public literal), STOP and verify before forcing a workaround.

- [ ] **Step 5: Run full suite** (verifies the ~10 no-arg `new DocumentProcessor` sites and the `app(DocumentProcessor::class)` resolutions all still construct)
  `php artisan test`

- [ ] **Step 6: Commit**
  ```
  git add app/Services/Knowledge/DocumentProcessor.php tests/Unit/Services/DocumentProcessorFetchTest.php
  git commit -m "$(cat <<'EOF'
fix(crawler): route manual-add fetch through GuardedHttpClient (§1.7)

DocumentProcessor::fetchUrl now uses the IP-pinned, redirect-revalidating
GuardedHttpClient the bulk crawler uses, closing the DNS-rebind TOCTOU on
the single-URL "add webpage" path. SafeExternalUrl stays as cheap
pre-validation. Constructor defaults the guard so no-arg sites still work.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
  ```

---

### Task 9: Lead list authorization — `LeadController::index()` authorize (§1.8)

**Files:**
- Modify `app/Http/Controllers/Client/LeadController.php` (`index()`, add first line at line 25)
- Create `tests/Feature/Client/LeadIndexAuthorizeTest.php`

A user with NO tenant role fails `ManageLeads` (Agent-or-higher). The existing `LeadManagementTest::test_leads_index_can_be_rendered` uses an Owner (via `actingAsTenantUser`), who passes — so it stays green.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Client/LeadIndexAuthorizeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Models\Tenant;
use App\Models\User;
use Tests\Concerns\SeedsRoleMatrix;
use Tests\TestCase;

/**
 * LeadController::index() must gate on Ability::ManageLeads like every other
 * method in the controller — it previously relied only on route-group auth
 * middleware, leaving the list readable by any authenticated user without the
 * manage-leads ability.
 */
class LeadIndexAuthorizeTest extends TestCase
{
    use SeedsRoleMatrix;

    private Tenant $leadTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->leadTenant = Tenant::create([
            'name' => 'Lead Co', 'slug' => 'lead-co',
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function test_agent_with_manage_leads_can_view_index(): void
    {
        // Agent is the minimum role that holds ManageLeads.
        $this->actingAsAgent($this->leadTenant);

        $this->get('/leads')->assertOk();
    }

    public function test_user_without_manage_leads_is_forbidden(): void
    {
        // A tenant user with NO role fails the ManageLeads gate.
        $user = User::create([
            'name' => 'No Role', 'email' => 'norole@example.com',
            'password' => bcrypt('password'), 'tenant_id' => $this->leadTenant->id,
        ]);
        $this->actingAs($user);

        $this->get('/leads')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run it, expect fail**
  `php artisan test --filter=LeadIndexAuthorizeTest`
  Expected failure: `test_user_without_manage_leads_is_forbidden` returns `200` (the index renders for any authenticated user) instead of `403`, because `index()` has no `authorize()` call.

- [ ] **Step 3: Implement**

In `app/Http/Controllers/Client/LeadController.php`, add the authorize as the first line of `index()` (before `$tenant = $this->getTenant($request);`, current line 25):

```php
    public function index(Request $request): InertiaResponse
    {
        $this->authorize(Ability::ManageLeads->value);

        $tenant = $this->getTenant($request);
```

(`Ability` is already imported at the top of the controller.)

- [ ] **Step 4: Run it, expect pass**
  `php artisan test --filter=LeadIndexAuthorizeTest`

- [ ] **Step 5: Run full suite** (`LeadManagementTest` uses Owner-role `actingAsTenantUser`, who passes ManageLeads — stays green)
  `php artisan test`

- [ ] **Step 6: Commit**
  ```
  git add app/Http/Controllers/Client/LeadController.php tests/Feature/Client/LeadIndexAuthorizeTest.php
  git commit -m "$(cat <<'EOF'
fix(leads): gate LeadController::index on ManageLeads (§1.8)

index() previously relied only on route-group middleware; every other
method authorizes ManageLeads. A tenant user without the ability could
read the lead list. Add the authorize() call as the first line.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
  ```

---

### Task 10: Pint + PHPStan-zero + browser smoke

Quality gate before the PR. No new feature code; this leaves the suite green and the baseline at zero.

**Files:** none new — formatting/static-analysis only.

- [ ] **Step 1: Pint — check**
  `./vendor/bin/pint --test`
  If clean, skip to PHPStan. If anything is flagged on PR-touched files, continue.

- [ ] **Step 2: Pint — fix (scope to touched files only)**
  ```
  ./vendor/bin/pint app/Http/Middleware/EnsureDkBankEnabled.php app/Http/Middleware/RequireWidgetSessionToken.php app/Http/Controllers/Client/DkBankQrController.php app/Http/Controllers/Client/LeadController.php app/Services/Payment/DkBank/DkBankQrService.php app/Services/Knowledge/DocumentProcessor.php app/Support/Widget/WidgetAudit.php config/services.php bootstrap/app.php routes/web.php tests/Feature/Client/Billing/DkBankKillswitchTest.php tests/Feature/Client/Billing/DkBankQrControllerTest.php tests/Unit/Services/Payment/DkBank/DkBankQrServiceCreditAccountTest.php tests/Unit/Services/Payment/DkBank/DkBankQrServiceMccTest.php tests/Unit/Services/Payment/DkBank/DkBankQrServiceEnvelopeTest.php tests/Feature/Widget/DualAcceptPassthroughTelemetryTest.php tests/Unit/Services/DocumentProcessorFetchTest.php tests/Feature/Client/LeadIndexAuthorizeTest.php
  ```

- [ ] **Step 3: Re-run the suite after Pint**
  `php artisan test`

- [ ] **Step 4: PHPStan — baseline must stay zero**
  `vendor/bin/phpstan analyse`
  Expected: `[OK] No errors`. If any new error appears, fix the code (do NOT add baseline entries). Likely watch-points:
    - `DocumentProcessor::$http` is non-nullable typed and set in the constructor — fine.
    - `creditAccountMatches` uses `config(...)` casts to `(int)`/`(string)` explicitly — fine.
    - The new test files declare `array<string, mixed>` PHPDoc on helpers that return arrays.

- [ ] **Step 5: Commit (only if Pint or PHPStan changed files)**
  ```
  git add -A
  git commit -m "$(cat <<'EOF'
style(pint): apply auto-fixes

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
  ```

- [ ] **Step 6: Browser smoke (manual, per CLAUDE.md Layer 3 — no automated assertion)**
  Start the dev server (`php artisan serve` / Herd at http://127.0.0.1:8001). Verify two things by hand:
    1. **DK killswitch 404:** with `DK_BANK_ENABLED=false` in `.env`, navigate to a DK route (e.g. POST via the Subscribe page's DK button, or hit `/billing/dk-qr/transaction/1`) → confirm a 404, not a stack trace or a reachable controller. Flip `DK_BANK_ENABLED=true` and confirm the QR session page renders.
    2. **Widget strict-mode reject:** from `http://127.0.0.1:8001/widget/test.html`, with `WIDGET_SESSION_DUAL_ACCEPT=false`, attempt a token-less write (`POST /api/v1/widget/message` with only `api_key`) via the browser console and confirm `401 {"error":"session_token_required"}`. Then run the normal widget flow (init → conversation → message) and confirm it works with the issued JWT. Record the observed status codes in the PR's Test plan.

---

## Done criteria for PR 1

- All ten tasks committed; `php artisan test` green; `vendor/bin/phpstan analyse` reports zero errors; `./vendor/bin/pint --test` clean.
- DK routes 404 when disabled, reach the controller when enabled.
- RRN accepts `SEL-2309203`; credit-account compare is normalized + mode-aware (exact default); status envelope parses both shapes; MCC behavior pinned.
- Dual-accept passthrough increments `widget_dual_accept_passthrough` and audits; `.env.example` ships `WIDGET_SESSION_DUAL_ACCEPT=false`.
- `DocumentProcessor::fetchUrl` routes through `GuardedHttpClient`; manual-add SSRF/DNS-rebind closed.
- `LeadController::index` gates on `ManageLeads`.
- Open PR `fix/security-hardening` → `main` with Summary / Deploy steps / ⚠️ behavior changes / Test plan checklist, linking the spec and this plan. Note: no migrations in PR 1; deploy is config-only (`DK_BANK_*`, `WIDGET_SESSION_DUAL_ACCEPT`, `TRUSTED_PROXIES`).
