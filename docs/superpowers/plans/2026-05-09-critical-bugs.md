# Critical Bug Fixes — 2026-05-09 Audit Slice

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the 3 newly-found CRITICAL bugs (yearly-plan undercount, API-key rotation cache regression, zero-amount payment) and the SSRF trio (IPv4-mapped IPv6, DNS rebinding, redirect-following), plus cheap frontend null-guards that ride along.

**Architecture:**
- Backend: each fix is local — `Tenant::extendPlan` reads `billing_period`; `WidgetController::regenerateApiKey` reorders cache forget after update; `BillingController::submitPayment` validates amount against plan price; `SafeExternalUrl` adds IPv4-mapped-IPv6 detection + an exposed `isSafe(string $url): bool` static; `DocumentProcessor::extractFromUrl` calls that guard at fetch time and disables HTTP redirects.
- Frontend: defensive `?.` and `?? []` on Analytics, Admin Dashboard, Admin Clients/Show, Conversations/Index. Pure render hardening — no controller changes.

**Tech Stack:** Laravel 13 + PHPUnit (not Pest), Vue 3 Composition API + Inertia, Tailwind v4. Tests live under `tests/Feature` and `tests/Unit/Rules`. `RefreshDatabase` is on by default; `actingAsTenantUser()` provisions a tenant with a 14-day trial.

---

## Pre-flight: branch + verification

- [ ] **Pre-flight 1: Create the fix branch**

```bash
git checkout -b fix/critical-bugs-2026-05-09
```

- [ ] **Pre-flight 2: Confirm baseline test suite passes**

```bash
php artisan test
```

Expected: all green. If not, stop and surface the failures — don't layer fixes onto a broken baseline.

---

## Task 1: `Tenant::extendPlan` reads `billing_period`

**Bug:** `extendPlan` always adds 1 month. `TransactionController::approve` calls it with no args, so a yearly plan gets 30 days of activation per approval.

**Files:**
- Modify: `app/Models/Tenant.php:127-137`
- Modify: `app/Http/Controllers/Admin/TransactionController.php` (no signature change needed; verify the call site stays correct)
- Test: `tests/Unit/Models/TenantExtendPlanTest.php` (create)

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Models/TenantExtendPlanTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Plan;
use App\Models\Tenant;
use Tests\TestCase;

class TenantExtendPlanTest extends TestCase
{
    public function test_yearly_plan_extends_by_twelve_months(): void
    {
        $this->createTenantWithUser();
        $this->tenant->update(['plan_id' => null, 'plan_expires_at' => null]);

        $plan = Plan::create([
            'name' => 'Yearly Pro',
            'slug' => 'yearly-pro',
            'price' => 1200,
            'billing_period' => 'yearly',
            'is_active' => true,
            'conversations_limit' => -1,
            'leads_limit' => -1,
            'tokens_limit' => -1,
            'knowledge_items_limit' => -1,
        ]);

        $before = now();
        $this->tenant->extendPlan($plan);
        $this->tenant->refresh();

        $expected = $before->copy()->addMonths(12);
        $this->assertEqualsWithDelta(
            $expected->getTimestamp(),
            $this->tenant->plan_expires_at->getTimestamp(),
            5,
            'Yearly plan should extend 12 months from now'
        );
    }

    public function test_monthly_plan_extends_by_one_month(): void
    {
        $this->createTenantWithUser();
        $this->tenant->update(['plan_id' => null, 'plan_expires_at' => null]);

        $plan = Plan::create([
            'name' => 'Monthly Starter',
            'slug' => 'monthly-starter',
            'price' => 100,
            'billing_period' => 'monthly',
            'is_active' => true,
            'conversations_limit' => 100,
            'leads_limit' => 50,
            'tokens_limit' => 10000,
            'knowledge_items_limit' => 5,
        ]);

        $before = now();
        $this->tenant->extendPlan($plan);
        $this->tenant->refresh();

        $expected = $before->copy()->addMonths(1);
        $this->assertEqualsWithDelta(
            $expected->getTimestamp(),
            $this->tenant->plan_expires_at->getTimestamp(),
            5
        );
    }

    public function test_active_yearly_plan_extension_preserves_remaining_time(): void
    {
        $this->createTenantWithUser();
        $existing = now()->addMonths(3);
        $this->tenant->update([
            'plan_id' => null,
            'plan_expires_at' => $existing,
        ]);

        $plan = Plan::create([
            'name' => 'Yearly Renewal',
            'slug' => 'yearly-renewal',
            'price' => 1200,
            'billing_period' => 'yearly',
            'is_active' => true,
            'conversations_limit' => -1,
            'leads_limit' => -1,
            'tokens_limit' => -1,
            'knowledge_items_limit' => -1,
        ]);

        $this->tenant->extendPlan($plan);
        $this->tenant->refresh();

        $expected = $existing->copy()->addMonths(12);
        $this->assertEqualsWithDelta(
            $expected->getTimestamp(),
            $this->tenant->plan_expires_at->getTimestamp(),
            5
        );
    }
}
```

- [ ] **Step 2: Run the tests, confirm they FAIL**

```bash
php artisan test --filter=TenantExtendPlanTest
```

Expected: yearly tests fail (`extendPlan` adds 1 month, not 12).

- [ ] **Step 3: Implement — read `billing_period` in `extendPlan`**

Edit `app/Models/Tenant.php:127-137` — replace the method body:

```php
public function extendPlan(Plan $plan): void
{
    $months = $plan->billing_period === 'yearly' ? 12 : 1;

    $base = $this->plan_expires_at && $this->plan_expires_at->isFuture()
        ? $this->plan_expires_at
        : now();

    $this->update([
        'plan_id' => $plan->id,
        'plan_expires_at' => $base->copy()->addMonths($months),
    ]);
}
```

Note the dropped `$months` parameter. If any other caller passes a custom value, the build will break — search for it now:

```bash
grep -rn "extendPlan" app/ tests/ | grep -v "function extendPlan"
```

If anything other than `Admin/TransactionController.php` and `Client/BillingController.php` is found, extend the test cases to cover it before changing the signature; otherwise proceed.

- [ ] **Step 4: Run the tests, confirm they PASS**

```bash
php artisan test --filter=TenantExtendPlanTest
```

Expected: all three tests green.

- [ ] **Step 5: Run the full suite to catch regressions**

```bash
php artisan test
```

Expected: all green. If `BillingTest.php` or any test referencing `extendPlan` breaks, fix the call site to drop the now-removed second arg.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Tenant.php tests/Unit/Models/TenantExtendPlanTest.php
git commit -m "$(cat <<'EOF'
fix(billing): honor plan billing_period in extendPlan

Yearly plans were silently activated for 30 days because extendPlan
always called addMonths(1). Now reads $plan->billing_period and maps
yearly => 12, monthly => 1.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `WidgetController::regenerateApiKey` cache order

**Bug:** `Cache::forget(old)` runs *before* `$tenant->update(new)`. A concurrent widget request between the two calls re-caches the tenant under the old key for 300s. The old key remains valid for up to 5 minutes after rotation. Direct regression of C2.

**Files:**
- Modify: `app/Http/Controllers/Client/WidgetController.php:61-73`
- Test: `tests/Feature/Client/WidgetApiKeyRotationTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Client/WidgetApiKeyRotationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WidgetApiKeyRotationTest extends TestCase
{
    public function test_cache_forget_runs_after_db_update(): void
    {
        $this->actingAsTenantUser();
        $oldKey = $this->tenant->api_key;

        $events = [];

        Cache::shouldReceive('forget')
            ->once()
            ->with("tenant:api_key:{$oldKey}")
            ->andReturnUsing(function () use (&$events) {
                $events[] = 'forget';
                return true;
            });

        // The Tenant::saved hook also forgets tenant:{id}:with_plan — allow it.
        Cache::shouldReceive('forget')
            ->zeroOrMoreTimes()
            ->withAnyArgs()
            ->andReturn(true);

        DB::listen(function ($query) use (&$events) {
            if (
                str_contains(strtolower($query->sql), 'update')
                && str_contains($query->sql, 'tenants')
            ) {
                $events[] = 'update';
            }
        });

        $this->post(route('client.widget.regenerate-key'))->assertRedirect();

        $this->assertSame(
            ['update', 'forget'],
            $events,
            'DB update must complete before Cache::forget is called'
        );
    }

    public function test_old_api_key_cache_is_evicted_and_db_holds_new_key(): void
    {
        $this->actingAsTenantUser();
        $oldKey = $this->tenant->api_key;

        Cache::put("tenant:api_key:{$oldKey}", $this->tenant->fresh(), 300);

        $this->post(route('client.widget.regenerate-key'))->assertRedirect();

        $this->tenant->refresh();
        $this->assertNotSame($oldKey, $this->tenant->api_key);
        $this->assertNull(Cache::get("tenant:api_key:{$oldKey}"));
    }
}
```

- [ ] **Step 2: Run the test, confirm `test_cache_forget_runs_after_db_update` FAILS**

```bash
php artisan test --filter=WidgetApiKeyRotationTest
```

Expected: events array is `['forget', 'update']` — assertion fails.

- [ ] **Step 3: Fix the order**

Edit `app/Http/Controllers/Client/WidgetController.php:61-73`:

```php
public function regenerateApiKey(Request $request): RedirectResponse
{
    $tenant = $this->getTenant($request);
    $oldKey = $tenant->api_key;

    $tenant->update([
        'api_key' => bin2hex(random_bytes(32)),
    ]);

    Cache::forget("tenant:api_key:{$oldKey}");

    return back()->with('success', 'API key regenerated successfully.');
}
```

- [ ] **Step 4: Run the tests, confirm both PASS**

```bash
php artisan test --filter=WidgetApiKeyRotationTest
```

Expected: events array is `['update', 'forget']` and old-key cache is evicted — both green.

- [ ] **Step 5: Run the full suite**

```bash
php artisan test
```

Expected: green. The `tenant:api_key:{key}` cache is *not* invalidated by the `Tenant::saved` hook, so this manual forget remains essential after the reorder.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Client/WidgetController.php tests/Feature/Client/WidgetApiKeyRotationTest.php
git commit -m "$(cat <<'EOF'
fix(widget): rotate api key in DB before evicting old cache entry

Forgetting the old cache entry before the DB update opened a 300s
window where a concurrent widget request could re-cache the old key.
This was a regression of C2 (PR #3). Reversing the order makes the
DB authoritative before any cache reseeding can occur.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `BillingController::submitPayment` validates amount against plan price

**Bug:** `'amount' => 'required|numeric|min:0'` accepts any value including 0. A tenant can submit a Nu. 0 transaction and have it approved by a distracted admin.

**Files:**
- Modify: `app/Http/Controllers/Client/BillingController.php:88-95`
- Test: `tests/Feature/BillingTest.php` (extend) or `tests/Feature/Client/BillingSubmitPaymentTest.php` (create — preferred to keep this fix scoped)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Client/BillingSubmitPaymentTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Models\Plan;
use Tests\TestCase;

class BillingSubmitPaymentTest extends TestCase
{
    private function makePlan(int $price = 500): Plan
    {
        return Plan::create([
            'name' => 'Pro',
            'slug' => 'pro',
            'price' => $price,
            'billing_period' => 'monthly',
            'is_active' => true,
            'conversations_limit' => 100,
            'leads_limit' => 50,
            'tokens_limit' => 10000,
            'knowledge_items_limit' => 5,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'transaction_number' => 'TXN-' . uniqid(),
            'reference_number' => 'ABC123',
            'amount' => 500,
            'payment_method' => 'bob',
            'payment_date' => now()->toDateString(),
            'notes' => null,
        ], $overrides);
    }

    public function test_zero_amount_is_rejected(): void
    {
        $this->actingAsTenantUser();
        $plan = $this->makePlan(500);

        $response = $this->post(
            route('client.billing.submit-payment', ['plan' => $plan->id]),
            $this->payload(['amount' => 0])
        );

        $response->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_amount_below_plan_price_is_rejected(): void
    {
        $this->actingAsTenantUser();
        $plan = $this->makePlan(500);

        $response = $this->post(
            route('client.billing.submit-payment', ['plan' => $plan->id]),
            $this->payload(['amount' => 499])
        );

        $response->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_amount_equal_to_plan_price_is_accepted(): void
    {
        $this->actingAsTenantUser();
        $plan = $this->makePlan(500);

        $response = $this->post(
            route('client.billing.submit-payment', ['plan' => $plan->id]),
            $this->payload(['amount' => 500])
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_amount_above_plan_price_is_accepted(): void
    {
        // Tenants paying more than the price (rounding, tip, currency
        // confusion) should not be blocked — admin will reconcile.
        $this->actingAsTenantUser();
        $plan = $this->makePlan(500);

        $response = $this->post(
            route('client.billing.submit-payment', ['plan' => $plan->id]),
            $this->payload(['amount' => 600])
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('transactions', 1);
    }
}
```

- [ ] **Step 2: Run, confirm zero/below-price tests FAIL**

```bash
php artisan test --filter=BillingSubmitPaymentTest
```

Expected: `test_zero_amount_is_rejected` and `test_amount_below_plan_price_is_rejected` fail because amount 0 / 499 currently pass validation.

- [ ] **Step 3: Tighten validation**

Edit `app/Http/Controllers/Client/BillingController.php:88-95`:

```php
$validated = $request->validate([
    'transaction_number' => 'required|string|max:255',
    'reference_number' => 'required|string|size:6|alpha_num',
    'amount' => "required|numeric|min:{$plan->price}",
    'payment_method' => 'required|in:bob,bnb,dpnb,bdbl,tbank,dk',
    'payment_date' => 'required|date|before_or_equal:today',
    'notes' => 'nullable|string|max:1000',
]);
```

(Interpolating `$plan->price` is safe — `$plan` is the route-bound model, `price` is an integer column.)

- [ ] **Step 4: Run, confirm all 4 PASS**

```bash
php artisan test --filter=BillingSubmitPaymentTest
```

- [ ] **Step 5: Full suite**

```bash
php artisan test
```

Expected: green. If existing `BillingTest.php` cases sent `amount: 0` against a paid plan, update them to send a value ≥ plan price.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Client/BillingController.php tests/Feature/Client/BillingSubmitPaymentTest.php
git commit -m "$(cat <<'EOF'
fix(billing): reject payment amounts below plan price

submitPayment previously validated amount as min:0 with no comparison
to the selected plan's price, allowing tenants to submit zero-value
transactions that an inattentive admin could approve into a free
paid-plan activation. Now requires amount >= plan.price.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: `SafeExternalUrl` rejects IPv4-mapped IPv6 addresses

**Bug:** `filter_var('::ffff:127.0.0.1', FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)` returns true (the address is "valid IP, not private") because PHP's flags don't catch the IPv4-in-IPv6 form. SSRF to loopback / cloud metadata.

**Files:**
- Modify: `app/Rules/SafeExternalUrl.php` (extend `isPrivateIp` and add a public `isSafe(string $url): bool` static for re-validation in Task 5)
- Test: `tests/Unit/Rules/SafeExternalUrlTest.php` (extend)

- [ ] **Step 1: Append the failing tests**

Append to `tests/Unit/Rules/SafeExternalUrlTest.php` (above the closing `}`):

```php
public function test_ipv4_mapped_ipv6_loopback_rejected(): void
{
    $this->assertTrue($this->fails('http://[::ffff:127.0.0.1]/admin'));
}

public function test_ipv4_mapped_ipv6_aws_metadata_rejected(): void
{
    $this->assertTrue($this->fails('http://[::ffff:169.254.169.254]/latest/meta-data/'));
}

public function test_ipv4_mapped_ipv6_rfc1918_rejected(): void
{
    $this->assertTrue($this->fails('http://[::ffff:10.0.0.1]/'));
}

public function test_zero_address_rejected(): void
{
    $this->assertTrue($this->fails('http://0.0.0.0/'));
}

public function test_unspecified_ipv6_rejected(): void
{
    $this->assertTrue($this->fails('http://[::]/'));
}
```

- [ ] **Step 2: Run, confirm new tests FAIL**

```bash
php artisan test --filter=SafeExternalUrlTest
```

Expected: at least the IPv4-mapped IPv6 tests fail. `0.0.0.0` and `[::]` may or may not — confirm before patching.

- [ ] **Step 3: Patch `SafeExternalUrl`**

Edit `app/Rules/SafeExternalUrl.php` — replace the entire class body so the file becomes:

```php
<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeExternalUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        if (! self::isSafe($value)) {
            $fail('The :attribute points to a non-public address.');
        }
    }

    /**
     * Returns true if $url has a parseable host that resolves to public IPs only.
     * Re-callable at fetch time to defeat DNS rebinding.
     */
    public static function isSafe(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        // parse_url leaves brackets on IPv6 literals — strip them.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return ! self::isPrivateHost($host);
    }

    private static function isPrivateHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPrivateIp($host);
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false || $records === []) {
            return true;
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if ($ip !== null && self::isPrivateIp($ip)) {
                return true;
            }
        }

        return false;
    }

    private static function isPrivateIp(string $ip): bool
    {
        if ($ip === '0.0.0.0' || $ip === '::') {
            return true;
        }

        // IPv4-mapped IPv6: ::ffff:x.x.x.x — extract the embedded IPv4 and recheck.
        if (stripos($ip, '::ffff:') === 0) {
            $embedded = substr($ip, 7);
            if (filter_var($embedded, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return self::isPrivateIp($embedded);
            }
        }

        return ! filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
```

Three changes from the original:
1. The validation logic is moved into a public static `isSafe(string $url): bool` so Task 5 can re-validate at fetch time.
2. `isPrivateIp` now strips `::ffff:` and recurses on the embedded IPv4.
3. `0.0.0.0` and `::` are explicit rejections.

The `validate()` instance method now delegates to `isSafe()`.

- [ ] **Step 4: Run, confirm all `SafeExternalUrlTest` tests PASS**

```bash
php artisan test --filter=SafeExternalUrlTest
```

- [ ] **Step 5: Full suite**

```bash
php artisan test
```

- [ ] **Step 6: Commit**

```bash
git add app/Rules/SafeExternalUrl.php tests/Unit/Rules/SafeExternalUrlTest.php
git commit -m "$(cat <<'EOF'
fix(security): close IPv4-mapped IPv6 SSRF bypass in SafeExternalUrl

PHP's FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE does not
reject IPv4-mapped IPv6 addresses (::ffff:0:0/96) so URLs like
http://[::ffff:127.0.0.1]/ and the AWS metadata equivalent passed.
Now strips the ::ffff: prefix and recurses on the embedded IPv4, plus
explicit rejection of 0.0.0.0 and ::. Validation logic also exposed
as SafeExternalUrl::isSafe() for fetch-time re-validation.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: `DocumentProcessor::extractFromUrl` re-validates and disables redirects

**Bugs covered:** H-NEW-2 (DNS rebinding TOCTOU between validation and fetch) and H-NEW-3 (Guzzle follows redirects by default, allowing a validated public URL to 30x to `169.254.169.254`).

**Files:**
- Modify: `app/Services/Knowledge/DocumentProcessor.php:37-60`
- Test: `tests/Feature/KnowledgeBaseTest.php` (extend) or `tests/Unit/Services/DocumentProcessorFetchTest.php` (create — preferred for scope)

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Services/DocumentProcessorFetchTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Knowledge\DocumentProcessor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentProcessorFetchTest extends TestCase
{
    public function test_loopback_url_is_rejected_at_fetch_time(): void
    {
        // Simulates DNS rebinding: validation already ran and passed (as
        // it would for a public-resolving hostname), but now the URL points
        // at a private IP. extractFromUrl must guard.
        $processor = app(DocumentProcessor::class);

        $this->expectException(\Throwable::class);
        $processor->extractFromUrl('http://127.0.0.1/admin');
    }

    public function test_redirect_response_is_not_followed(): void
    {
        // Http::fake never auto-follows redirects regardless of options, so
        // the canonical assertion is "exactly one HTTP request was sent and
        // it surfaced the 30x to the caller as a non-successful response,
        // which extractFromUrl converts into an exception."
        Http::fake([
            'public.example.com/redir' => Http::response('', 302, [
                'Location' => 'http://169.254.169.254/latest/meta-data/',
            ]),
        ]);

        $processor = app(DocumentProcessor::class);

        $this->expectException(\Throwable::class);
        try {
            $processor->extractFromUrl('http://public.example.com/redir');
        } finally {
            Http::assertSentCount(1);
            Http::assertNotSent(fn ($req) => str_contains($req->url(), '169.254.169.254'));
        }
    }

    public function test_public_url_is_fetched_normally(): void
    {
        Http::fake([
            'public.example.com/page' => Http::response('<p>Hello</p>', 200),
        ]);

        $processor = app(DocumentProcessor::class);
        $text = $processor->extractFromUrl('http://public.example.com/page');

        $this->assertStringContainsString('Hello', $text);
        Http::assertSentCount(1);
    }
}
```

- [ ] **Step 2: Run, confirm the loopback test FAILS**

```bash
php artisan test --filter=DocumentProcessorFetchTest
```

Expected: `test_loopback_url_is_rejected_at_fetch_time` fails because `extractFromUrl` currently calls `Http::get('http://127.0.0.1/...')` without any guard. The redirect test may already pass under `Http::fake` (it doesn't auto-follow), but the patch below also makes it pass against real Guzzle by setting `allow_redirects => false`.

- [ ] **Step 3: Patch `extractFromUrl`**

Edit `app/Services/Knowledge/DocumentProcessor.php:37-60`:

```php
public function extractFromUrl(string $url): string
{
    Log::debug('[DocumentProcessor] (IS $) Extracting from URL', [
        'url' => $url,
    ]);

    if (! \App\Rules\SafeExternalUrl::isSafe($url)) {
        Log::warning('[DocumentProcessor] Rejected non-public URL at fetch time', [
            'url' => $url,
        ]);
        throw new \Exception("Refusing to fetch non-public URL: {$url}");
    }

    try {
        $response = Http::timeout(30)
            ->withOptions(['allow_redirects' => false])
            ->get($url);

        if (! $response->successful()) {
            throw new \Exception("Failed to fetch URL: {$url}");
        }

        $html = $response->body();

        return $this->extractTextFromHtml($html);
    } catch (\Exception $e) {
        Log::error('[DocumentProcessor] URL extraction failed', [
            'url' => $url,
            'error' => $e->getMessage(),
        ]);
        throw $e;
    }
}
```

(Add `use App\Rules\SafeExternalUrl;` to the file's `use` block instead of the inline FQN if the file already namespaces other classes — check the top of the file first.)

- [ ] **Step 4: Run the tests, confirm they PASS**

```bash
php artisan test --filter=DocumentProcessorFetchTest
```

- [ ] **Step 5: Full suite**

```bash
php artisan test
```

Expected: green. If `KnowledgeBaseTest` had a fixture URL pointing at a private host, update it to `http://public.example.com/...` with `Http::fake`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Knowledge/DocumentProcessor.php tests/Unit/Services/DocumentProcessorFetchTest.php
git commit -m "$(cat <<'EOF'
fix(security): re-validate URL at fetch time and disable HTTP redirects

DNS rebinding could flip a previously-validated hostname to a private
IP between validation and the queued fetch; Guzzle's default redirect
following also let a validated public URL 30x to internal addresses.
extractFromUrl now (a) re-runs SafeExternalUrl::isSafe immediately
before HTTP, and (b) sets allow_redirects=false so any 30x is surfaced
rather than transparently followed.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Frontend null-guards

**Bug:** `Analytics/Index.vue` crashes on first-time accounts (unguarded `Math.max(...)` and `leadScoreDistribution.hot`); `Admin/Dashboard.vue` and `Admin/Clients/Show.vue` crash if any `stats.*` nested key is null; `Conversations/Index.vue` crashes if `conversations.data` is null on partial reload.

**Approach:** Pure render hardening — no controller change. Tested via existing PHP feature tests (controllers already return non-null in the happy path) plus a manual browser smoke at the end of the plan. No new automated test for this task; the harm-reduction is mechanical.

**Files (modify):**
- `resources/js/Pages/Client/Analytics/Index.vue:48-55`
- `resources/js/Pages/Admin/Dashboard.vue:69-70`
- `resources/js/Pages/Admin/Clients/Show.vue:165-166`
- `resources/js/Pages/Client/Conversations/Index.vue:86`

- [ ] **Step 1: Open `resources/js/Pages/Client/Analytics/Index.vue` and read lines 40-70**

Confirm the four time-series props (`conversationsOverTime`, `leadsOverTime`, `tokenUsageOverTime`, `conversationsByHour`) and the `leadScoreDistribution` access. Apply optional-chaining + nullish-default to each:

- Replace each `Math.max(...props.X.map(...))` with `Math.max(0, ...((props.X ?? []).map(...)))` (the `0` seed avoids `Math.max()` returning `-Infinity` on empty arrays).
- Replace `props.leadScoreDistribution.hot` (and warm/cold) with `props.leadScoreDistribution?.hot ?? 0` etc.

- [ ] **Step 2: Open `resources/js/Pages/Admin/Dashboard.vue` and `resources/js/Pages/Admin/Clients/Show.vue`**

Replace bare `stats.foo.bar` with `stats?.foo?.bar ?? 0` (or `?? '—'` if it's a string field). Specifically lines 69-70 in Dashboard and 165-166 in Clients/Show — but scan the surrounding template for the same pattern and patch all of them in one go (don't leave half-guarded files).

- [ ] **Step 3: Open `resources/js/Pages/Client/Conversations/Index.vue` line 86**

Change `v-if="conversations.data.length === 0"` to `v-if="(conversations?.data?.length ?? 0) === 0"` and the `v-for="row in conversations.data"` to `v-for="row in conversations?.data ?? []"`.

- [ ] **Step 4: Build the frontend to confirm no syntax error**

```bash
npm run build
```

Expected: success.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Client/Analytics/Index.vue resources/js/Pages/Admin/Dashboard.vue resources/js/Pages/Admin/Clients/Show.vue resources/js/Pages/Client/Conversations/Index.vue
git commit -m "$(cat <<'EOF'
fix(ui): defensive null-guards on Analytics, Admin Dashboard, Clients/Show, Conversations/Index

Pages crashed when partial reloads or empty-tenant states delivered
null where an array or nested object was expected. Render-time guards
only — no controller changes.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Browser smoke test

- [ ] **Step 1: Start the dev server in the background**

```bash
php artisan serve --port=8001 &
npm run dev &
```

- [ ] **Step 2: Walk the affected flows**

Login as `test@example.com` / `password`:
- Visit `/dashboard/analytics` — should render without errors even with no data.
- Visit `/dashboard/conversations` — list renders, empty state if no conversations.
- Visit `/dashboard/widget-settings`, click "Regenerate API Key" — confirm key changes; old `tenant:api_key:{old}` cache key should be evicted (verify via `redis-cli get` if Redis is configured, or via the tinker REPL if cache is the file driver).
- Visit `/dashboard/billing`, attempt to subscribe to a paid plan with `amount: 0` — expect the validation error message; with `amount: <plan.price - 1>` — expect error; with the exact price — accept.

Login as `admin@example.com` / `password`:
- Visit `/admin/dashboard` — renders.
- Visit `/admin/clients/<id>` — renders even if stats are partial.
- Approve a pending transaction for a yearly plan; confirm `tenant.plan_expires_at` is now ~12 months out (via tinker).

- [ ] **Step 3: Verify SSRF guards manually**

```bash
php artisan tinker --execute="dump(\App\Rules\SafeExternalUrl::isSafe('http://[::ffff:127.0.0.1]/'));"
```

Expected: `false`.

```bash
php artisan tinker --execute="try { app(\App\Services\Knowledge\DocumentProcessor::class)->extractFromUrl('http://127.0.0.1/'); } catch (\Throwable \$e) { dump('blocked: ' . \$e->getMessage()); }"
```

Expected: `"blocked: Refusing to fetch non-public URL: http://127.0.0.1/"`.

- [ ] **Step 4: Stop the dev server**

```bash
pkill -f "artisan serve"
pkill -f "vite"
```

If browser smoke is fully green, hand off to the post-execution `/simplify` pass.

---

## Out of scope

The following audit findings are **not** in this plan and should be tracked separately:
- C-NEW-4 streaming chat orphans on retrieval failure
- C-NEW-5 chunk duplication on `ProcessKnowledgeItem` retry
- All HIGH and MEDIUM findings beyond the SSRF trio
- The 11 documented Mediums M1–M11 from the May 2026 audit

The bundling decision: this plan stays narrow to one PR's worth of "money + key + SSRF" risk and one cheap UI ride-along. Splitting further is needless churn; widening invites scope drift.
