# Plan A — Tenant-Scoping Enforcement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `BelongsToTenant` trait (with `forTenant` query scope + boot-hook auto-stamp + `tenant()` relation) on every tenant-scoped model, plus a Larastan rule that blocks future raw `where('tenant_id', ...)` queries. Existing violations land in a baseline file as known debt; new violations fail CI.

**Architecture:** No global query scope, no Spatie wire-up. Explicit `Lead::forTenant($tenant)` calls everywhere; `creating` boot-hook stamps `tenant_id` from `Auth::user()->tenant_id` when omitted and the authed user is a tenant-bound user. Static analysis (PHPStan/Larastan custom rule) prevents new raw-form code from being merged. Cluster A is prevention infrastructure — at merge time, the 30+ existing violations all enter the baseline; cure accumulates through Clusters B/C/D as they touch each file.

**Tech Stack:** Laravel 13, PHP 8.3, Larastan/PHPStan level 6, PHPUnit (existing test framework), Composer.

**Spec:** `docs/superpowers/specs/2026-05-14-architecture-deepening-design.md` (Cluster A).

---

## File Structure

**New files:**
- `app/Models/Concerns/BelongsToTenant.php` — the trait
- `app/Rules/PHPStan/NoRawTenantIdWhere.php` — the custom Larastan rule
- `tests/Unit/Models/BelongsToTenantTest.php` — trait test
- `tests/Unit/Rules/NoRawTenantIdWhereTest.php` — rule self-test
- `tests/Unit/Rules/Fixtures/raw_tenant_id_where_fixture.php` — fixture that triggers the rule
- `tests/Unit/Rules/Fixtures/safe_wheres_fixture.php` — fixture that doesn't
- `phpstan-baseline.neon` — generated baseline grandfathering existing violations

**Modified files (Task 2 — apply trait):**
- `app/Models/User.php`
- `app/Models/Tenant.php` — N/A (Tenant is the parent, doesn't belong to itself)
- `app/Models/Transaction.php`
- `app/Models/KnowledgeItem.php`
- `app/Models/UsageRecord.php`
- `app/Models/EnterpriseInquiry.php`
- `app/Models/Lead.php`
- `app/Models/Conversation.php` — also delete redundant `scopeForTenant` (G-6)

**Modified files (config):**
- `phpstan.neon` — register the rule + include the baseline

---

## Task 0 — Verifications (no code; probe reality, update plan if assumptions break)

**Files:** none modified; results may update later tasks.

- [ ] **Step 1: Confirm admin user is on a separate auth model**

Run:
```bash
php artisan tinker --execute="echo json_encode(['admin_provider' => config('auth.providers.admin_users.model'), 'web_provider' => config('auth.providers.users.model')]);"
```

Expected: `{"admin_provider":"App\\Models\\AdminUser","web_provider":"App\\Models\\User"}`

If admin uses the `User` model (not `AdminUser`), the boot hook's safety analysis from the spec breaks — STOP and update the spec before proceeding.

- [ ] **Step 2: Audit existing factories for tenant_id treatment**

Run:
```bash
for f in database/factories/*Factory.php; do echo "--- $f ---"; grep -nE "tenant_id|Tenant::factory" "$f" || echo "(no tenant_id reference)"; done
```

Expected output should match (substantively):
- `ConversationFactory.php` — sets `'tenant_id' => Tenant::factory()`
- `UserFactory.php` — does NOT set `tenant_id` (nullable; tenant_id stays null in unauthed tests)
- `MessageFactory.php` — no tenant_id (Message scopes via conversation)
- `TenantFactory.php` — defines Tenant itself

If any factory creates a tenant-scoped model (Lead/KnowledgeItem/UsageRecord/EnterpriseInquiry/Transaction) without setting `tenant_id` AND relies on something other than explicit factory state to fill it, flag and fix before Task 1.

- [ ] **Step 3: Grep for `Lead::create([...])` and equivalents without `tenant_id`**

Run:
```bash
grep -rn "::create\(\[" app/Http app/Services app/Jobs | grep -E "Lead|KnowledgeItem|UsageRecord|Transaction|EnterpriseInquiry|Conversation" | grep -v "tenant_id"
```

Expected: empty, or a small list where `tenant_id` is on a nearby line. If any call site creates a tenant-scoped model without setting `tenant_id` in any auth-less context (e.g., a console command), the boot hook will leave `tenant_id` null and the DB constraint will catch it loudly — note these as call-out items for the PR description.

- [ ] **Step 4: Confirm Larastan setup is intact**

Run:
```bash
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -20
```

Expected: clean run (PHPStan baseline currently has zero errors; level 6).

If errors exist on `main` unrelated to this plan, fix them on a separate PR first or the new baseline will contaminate.

- [ ] **Step 5: Decide whether to proceed**

If every verification matched expectations, proceed to Task 1.

If any verification surfaced an unexpected result (admin uses the `User` model not `AdminUser`; a factory creates a tenant-scoped model without `tenant_id`; an existing create site relies on neither factory nor auth context), **stop and discuss with the user before proceeding**. Do not modify this plan file mid-execution — surface the finding in chat and the user will decide whether to revise the spec/plan or accept the deviation.

---

## Task 1 — `BelongsToTenant` trait + boot hook + RED test

**Files:**
- Create: `tests/Unit/Models/BelongsToTenantTest.php`
- Create: `app/Models/Concerns/BelongsToTenant.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Models/BelongsToTenantTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BelongsToTenantTest extends TestCase
{
    use RefreshDatabase;

    private function fixtureModel(): Model
    {
        // Use a real existing tenant-scoped table for the fixture so we don't
        // need to manage extra migrations. `leads` has tenant_id NOT NULL.
        //
        // FALLBACK: if these tests fail with "model not booted" or class-resolution
        // errors related to the anonymous class, replace this with a real fixture
        // class at tests/Fixtures/TenantScopedFixture.php that extends Model,
        // sets protected $table = 'leads', and uses BelongsToTenant — then
        // instantiate that here instead.
        return new class extends Model
        {
            use BelongsToTenant;

            protected $table = 'leads';

            protected $fillable = ['tenant_id', 'name', 'email'];
        };
    }

    public function test_for_tenant_scope_filters_by_tenant_model(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $fixture = $this->fixtureModel();
        $fixture::create(['tenant_id' => $tenantA->id, 'name' => 'A1', 'email' => 'a1@example.com']);
        $fixture::create(['tenant_id' => $tenantA->id, 'name' => 'A2', 'email' => 'a2@example.com']);
        $fixture::create(['tenant_id' => $tenantB->id, 'name' => 'B1', 'email' => 'b1@example.com']);

        $aOnly = $fixture::query()->forTenant($tenantA)->get();
        $this->assertCount(2, $aOnly);
        $this->assertEqualsCanonicalizing(['A1', 'A2'], $aOnly->pluck('name')->all());
    }

    public function test_for_tenant_scope_filters_by_int_id(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $fixture = $this->fixtureModel();
        $fixture::create(['tenant_id' => $tenantA->id, 'name' => 'A1', 'email' => 'a1@example.com']);
        $fixture::create(['tenant_id' => $tenantB->id, 'name' => 'B1', 'email' => 'b1@example.com']);

        $aOnly = $fixture::query()->forTenant($tenantA->id)->get();
        $this->assertCount(1, $aOnly);
        $this->assertSame('A1', $aOnly->first()->name);
    }

    public function test_boot_hook_stamps_tenant_id_when_authed_user_has_one(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Auth::login($user);

        $fixture = $this->fixtureModel();
        $row = $fixture::create(['name' => 'NoTenantPassed', 'email' => 'no-tenant@example.com']);

        $this->assertSame($tenant->id, $row->tenant_id);
    }

    public function test_boot_hook_does_not_overwrite_explicit_tenant_id(): void
    {
        $tenantAuth = Tenant::factory()->create();
        $tenantTarget = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenantAuth->id]);

        Auth::login($user);

        $fixture = $this->fixtureModel();
        $row = $fixture::create(['tenant_id' => $tenantTarget->id, 'name' => 'Explicit', 'email' => 'x@example.com']);

        $this->assertSame($tenantTarget->id, $row->tenant_id);
    }

    public function test_boot_hook_does_nothing_when_no_authed_user(): void
    {
        $tenant = Tenant::factory()->create();
        $fixture = $this->fixtureModel();

        // No Auth::login. tenant_id is NOT NULL on `leads`, so we pass it explicitly
        // and assert the hook didn't try to overwrite or interfere.
        $row = $fixture::create(['tenant_id' => $tenant->id, 'name' => 'NoAuth', 'email' => 'na@example.com']);

        $this->assertSame($tenant->id, $row->tenant_id);
    }

    public function test_boot_hook_does_nothing_when_authed_user_has_no_tenant(): void
    {
        $userWithoutTenant = User::factory()->create(['tenant_id' => null]);
        Auth::login($userWithoutTenant);

        $tenant = Tenant::factory()->create();
        $fixture = $this->fixtureModel();
        $row = $fixture::create(['tenant_id' => $tenant->id, 'name' => 'AuthNoTenant', 'email' => 'ant@example.com']);

        // Hook leaves the explicit tenant_id alone (since user has none).
        $this->assertSame($tenant->id, $row->tenant_id);
    }

    public function test_tenant_relation_resolves(): void
    {
        $tenant = Tenant::factory()->create();
        $fixture = $this->fixtureModel();
        $row = $fixture::create(['tenant_id' => $tenant->id, 'name' => 'Rel', 'email' => 'rel@example.com']);

        $this->assertInstanceOf(Tenant::class, $row->tenant);
        $this->assertSame($tenant->id, $row->tenant->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
php artisan test --filter=BelongsToTenantTest
```

Expected: FAIL — `Class "App\Models\Concerns\BelongsToTenant" not found` (or PHP fatal during autoload).

- [ ] **Step 3: Implement the trait**

Create `app/Models/Concerns/BelongsToTenant.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Tenant-aware behaviour for any model with a `tenant_id` column.
 *
 * Provides:
 * - `tenant(): BelongsTo` — the canonical relation.
 * - `scopeForTenant(Tenant|int)` — the public query scope; use everywhere
 *   instead of raw `where('tenant_id', ...)`. The Larastan rule
 *   `App\Rules\PHPStan\NoRawTenantIdWhere` blocks raw form on new code.
 * - `creating` boot hook that auto-stamps `tenant_id` from `Auth::user()`
 *   when both: (a) the model has no `tenant_id` set yet, and (b) the
 *   authed user has a `tenant_id`. Admin/console/queue contexts have no
 *   authed user (admin uses a separate `AdminUser` guard) — the hook is
 *   a no-op there; callers must pass `tenant_id` explicitly.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $authUser = Auth::user();
            $authTenantId = $authUser?->getAttribute('tenant_id');

            if ($authTenantId === null) {
                return;
            }

            $model->setAttribute('tenant_id', $authTenantId);
        });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForTenant(Builder $query, Tenant|int $tenant): Builder
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return $query->where("{$this->getTable()}.tenant_id", $tenantId);
    }

    /** @return BelongsTo<Tenant, static> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run:
```bash
php artisan test --filter=BelongsToTenantTest
```

Expected: PASS — 7 assertions, 7 tests green.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Concerns/BelongsToTenant.php tests/Unit/Models/BelongsToTenantTest.php
git commit -m "$(cat <<'EOF'
feat(tenancy): add BelongsToTenant trait — forTenant scope, boot-hook auto-stamp, tenant relation

Trait provides the canonical tenant-scoping interface for all tenant-scoped
models. Boot hook stamps tenant_id from Auth::user() on create when omitted
in authed contexts (no-op in admin/queue/console). Application to models
follows in the next task.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster A)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 2 — Apply trait to 7 models + delete redundant `Conversation::forTenant`

**Files:**
- Modify: `app/Models/User.php`, `Transaction.php`, `KnowledgeItem.php`, `UsageRecord.php`, `EnterpriseInquiry.php`, `Lead.php`, `Conversation.php`
- Test: full suite (`php artisan test`)

- [ ] **Step 1: Apply trait + delete redundant `tenant()` to `Lead.php`**

Edit `app/Models/Lead.php`:

Replace this block:
```php
use App\Models\Concerns\BustsTenantUsageCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use BustsTenantUsageCache;
```

with:
```php
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\BustsTenantUsageCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use BelongsToTenant, BustsTenantUsageCache;
```

Delete the existing `tenant()` method block (lines 40-44):
```php
    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
```

- [ ] **Step 2: Apply the same pattern to the other 6 models**

For each model below, the edit is identical in shape: add `use App\Models\Concerns\BelongsToTenant;` import, add `use BelongsToTenant;` (preserving any existing traits — most likely `BustsTenantUsageCache` on some), and delete the local `tenant(): BelongsTo` method (always at the same shape — `return $this->belongsTo(Tenant::class)`).

Apply to:
- `app/Models/User.php` (line 53-57 has the `tenant()` method; check for other traits)
- `app/Models/Transaction.php` (line 34-37)
- `app/Models/KnowledgeItem.php` (line 35-38; already has `BustsTenantUsageCache`)
- `app/Models/UsageRecord.php` (line 30-33; already has `BustsTenantUsageCache`)
- `app/Models/EnterpriseInquiry.php` (line 28-31)
- `app/Models/Conversation.php` (line 35-38; also delete `scopeForTenant` per next step)

- [ ] **Step 3: Delete redundant `scopeForTenant` from `Conversation.php` (G-6)**

In `app/Models/Conversation.php`, locate and delete any existing `scopeForTenant` method. The trait now provides it; leaving both creates a confusing override.

Run:
```bash
grep -n "scopeForTenant" app/Models/Conversation.php
```

If a method exists, delete its full method block. If grep returns no results, this step is already satisfied — proceed.

- [ ] **Step 4: Run the full test suite**

Run:
```bash
php artisan test
```

Expected: PASS — all existing tests still pass. If any fail with `tenant_id` null-related errors, the boot hook is auto-stamping where the test didn't expect; check `actingAs(...)` calls in failing tests and add explicit `tenant_id` to factories or `actingAs` calls.

- [ ] **Step 5: Run Pint to normalize imports**

Run:
```bash
./vendor/bin/pint --test
```

If anything is flagged:
```bash
./vendor/bin/pint
php artisan test
```

Expected: clean Pint pass after fixes.

- [ ] **Step 6: Commit**

```bash
git add app/Models/
git commit -m "$(cat <<'EOF'
feat(tenancy): apply BelongsToTenant trait to 7 tenant-scoped models

Adds the trait to User, Transaction, KnowledgeItem, UsageRecord,
EnterpriseInquiry, Lead, Conversation. Removes redundant local tenant()
methods (trait provides) and the unused Conversation::scopeForTenant
(trait's scopeForTenant supersedes).

Behaviour: forTenant($tenant) scope now available on all 7 models;
Lead::create([...]) and equivalents auto-stamp tenant_id from
Auth::user() when omitted in client (web-guard) contexts.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster A)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 3 — Larastan custom rule + RED rule-self-test

**Files:**
- Create: `app/Rules/PHPStan/NoRawTenantIdWhere.php`
- Create: `tests/Unit/Rules/NoRawTenantIdWhereTest.php`
- Create: `tests/Unit/Rules/Fixtures/raw_tenant_id_where_fixture.php`
- Create: `tests/Unit/Rules/Fixtures/safe_wheres_fixture.php`

- [ ] **Step 1: Write the failing rule test + fixtures**

Create `tests/Unit/Rules/Fixtures/raw_tenant_id_where_fixture.php`:

```php
<?php

// LINE-NUMBER-SENSITIVE: NoRawTenantIdWhereTest references specific line numbers
// in this file. Do not reformat, reorder, or add/remove lines without also
// updating the test's line-number assertions.

namespace Tests\Unit\Rules\Fixtures\RawTenantIdWhere;

use App\Models\Lead;

class RawTenantIdWhereFixture
{
    public function flagged(int $tenantId): mixed
    {
        return Lead::where('tenant_id', $tenantId)->get();         // line 15 — flagged
    }

    public function flaggedWhereIn(array $ids): mixed
    {
        return Lead::whereIn('tenant_id', $ids)->get();            // line 20 — flagged
    }

    public function flaggedQualified(int $tenantId): mixed
    {
        return Lead::join('users', 'users.id', 'leads.id')
            ->where('leads.tenant_id', $tenantId)                  // line 26 — flagged
            ->get();
    }
}
```

Create `tests/Unit/Rules/Fixtures/safe_wheres_fixture.php`:

```php
<?php

// LINE-NUMBER-SENSITIVE: the companion raw_tenant_id_where_fixture.php has
// line-number assertions in NoRawTenantIdWhereTest. This file expects zero
// errors regardless of line layout, but reformat with care to keep the pair
// readable side-by-side.

namespace Tests\Unit\Rules\Fixtures\SafeWheres;

use App\Models\Lead;
use App\Models\Tenant;

class SafeWheresFixture
{
    public function viaScope(Tenant $tenant): mixed
    {
        return Lead::forTenant($tenant)->get();
    }

    public function whereOnOtherColumn(int $score): mixed
    {
        return Lead::where('score', '>', $score)->get();
    }

    public function whereWithVariable(string $col, int $tenantId): mixed
    {
        // Rule must only flag literal 'tenant_id'; dynamic columns are out of scope.
        return Lead::where($col, $tenantId)->get();
    }
}
```

Create `tests/Unit/Rules/NoRawTenantIdWhereTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Rules;

use App\Rules\PHPStan\NoRawTenantIdWhere;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

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
                ["Raw where('tenant_id', ...) bypasses tenant scoping. Use forTenant(\$tenant) instead.", 15],
                ["Raw whereIn('tenant_id', ...) bypasses tenant scoping. Use forTenant(\$tenant) instead.", 20],
                ["Raw where('leads.tenant_id', ...) bypasses tenant scoping. Use forTenant(\$tenant) instead.", 26],
            ],
        );
    }

    public function test_does_not_flag_safe_queries(): void
    {
        $this->analyse(
            [__DIR__.'/Fixtures/safe_wheres_fixture.php'],
            [], // zero errors expected
        );
    }
}
```

- [ ] **Step 2: Run the rule test to verify it fails**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Rules/NoRawTenantIdWhereTest.php 2>&1 | tail -20
```

Expected: FAIL — `Class "App\Rules\PHPStan\NoRawTenantIdWhere" not found`.

(`./vendor/bin/phpunit` is used here, not `php artisan test`, because PHPStan's `RuleTestCase` boots its own analyser harness and skips Laravel's `TestCase` base. If the project's `phpunit.xml` doesn't pick up `tests/Unit/Rules/`, run the file path directly as shown.)

- [ ] **Step 2.5: Confirm the testsuite picks up the new file**

Run:
```bash
php artisan test --filter=NoRawTenantIdWhereTest 2>&1 | tail -10
```

Expected: at least one test executes (showing FAIL since the rule isn't implemented yet) — confirms the testsuite includes `tests/Unit/Rules/`. If output reads "No tests executed" or similar, edit `phpunit.xml` to add `<directory>tests/Unit/Rules</directory>` inside the `Unit` testsuite block:

```xml
<testsuite name="Unit">
    <directory>tests/Unit</directory>
    <directory>tests/Unit/Rules</directory>  <!-- add if missing -->
</testsuite>
```

Re-run the command above to confirm the test now executes.

- [ ] **Step 3: Implement the rule**

Create `app/Rules/PHPStan/NoRawTenantIdWhere.php`:

```php
<?php

declare(strict_types=1);

namespace App\Rules\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids raw `where('tenant_id', ...)` / `whereIn('tenant_id', ...)` /
 * qualified `where('leads.tenant_id', ...)` patterns. Use the
 * `forTenant($tenant)` scope provided by `App\Models\Concerns\BelongsToTenant`.
 *
 * Scope-by-design: matches Eloquent-style `where*` MethodCall nodes with a
 * string-literal first argument equal to 'tenant_id' or '*.tenant_id'.
 * Does NOT cover `DB::table(...)->where('tenant_id', ...)` (qualified column
 * on the DB facade) — those sites are converted to Eloquent in Cluster B.
 *
 * @implements Rule<MethodCall>
 */
class NoRawTenantIdWhere implements Rule
{
    /** @var array<int, string> */
    private const WHERE_METHODS = [
        'where',
        'orWhere',
        'whereIn',
        'orWhereIn',
        'whereNotIn',
        'orWhereNotIn',
    ];

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @return array<int, \PHPStan\Rules\RuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Node\Identifier) {
            return [];
        }

        $methodName = $node->name->toString();
        if (! in_array($methodName, self::WHERE_METHODS, true)) {
            return [];
        }

        if (! isset($node->args[0]) || ! $node->args[0] instanceof Node\Arg) {
            return [];
        }

        $firstArg = $node->args[0]->value;
        if (! $firstArg instanceof String_) {
            return [];
        }

        $column = $firstArg->value;
        if ($column !== 'tenant_id' && ! str_ends_with($column, '.tenant_id')) {
            return [];
        }

        // Self-exempt the trait file itself — its scope method must be raw.
        if (str_ends_with($scope->getFile(), 'BelongsToTenant.php')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                "Raw %s('%s', ...) bypasses tenant scoping. Use forTenant(\$tenant) instead.",
                $methodName,
                $column,
            ))->identifier('tenancy.rawTenantId')->build(),
        ];
    }
}
```

- [ ] **Step 4: Register the rule in `phpstan.neon` (no baseline include yet)**

Edit `phpstan.neon`. Append a `rules:` block at the bottom. The full file should now read:

```yaml
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app/
    level: 6
    parallel:
        maximumNumberOfProcesses: 1
    ignoreErrors:
        # Laravel relationship type inference
        - '#Access to an undefined property Illuminate\\Database\\Eloquent\\Model::\$\w+#'
        # Match arms for environment-based logic
        - '#Match arm comparison between .* is always true#'
        # Nullsafe on optional chaining that may be defensive
        - '#Using nullsafe property access .* on left side of \?\? is unnecessary#'
        # Dynamic properties from raw SQL selects
        - '#Access to an undefined property App\\Models\\\w+::\$\w+#'
        # Metadata array casts work correctly at runtime
        - '#Property App\\Models\\KnowledgeItem::\$metadata .* does not accept array#'
    reportUnmatchedIgnoredErrors: true

rules:
    - App\Rules\PHPStan\NoRawTenantIdWhere
```

The only addition vs. the current file is the `rules:` block at the bottom. **Do not** add the `phpstan-baseline.neon` include yet — Task 4 generates the baseline first, then adds the include in the same task. Including a non-existent file here would error PHPStan before the rule runs.

- [ ] **Step 5: Run the rule test to verify it passes**

Run:
```bash
./vendor/bin/phpunit tests/Unit/Rules/NoRawTenantIdWhereTest.php 2>&1 | tail -15
```

Expected: PASS — both tests green.

- [ ] **Step 6: Commit (rule + tests + phpstan.neon registration only — baseline comes in Task 4)**

If `phpunit.xml` was modified in Step 2.5, include it in the commit.

```bash
git add app/Rules/PHPStan/NoRawTenantIdWhere.php tests/Unit/Rules/ phpstan.neon phpunit.xml
git commit -m "$(cat <<'EOF'
feat(tenancy): add Larastan rule banning raw where('tenant_id', ...) calls

NoRawTenantIdWhere flags Eloquent-style where/orWhere/whereIn/etc. calls
with literal first arg of 'tenant_id' or '*.tenant_id'. Suggests
forTenant($tenant) replacement. Self-exempts the BelongsToTenant trait.
Does not cover DB::table queries — those sites are converted to Eloquent
in Cluster B.

Rule is registered in phpstan.neon. Task 4 generates the baseline that
grandfathers existing violations and wires it into the include chain.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster A)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 4 — Generate baseline + wire it into the include chain

**Files:**
- Create: `phpstan-baseline.neon`
- Modify: `phpstan.neon` (add `- phpstan-baseline.neon` to `includes`)

- [ ] **Step 1: Confirm PHPStan runs with the new rule (and surfaces violations)**

Run:
```bash
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -40
```

Expected: 25-40 errors with messages starting `Raw where('tenant_id', ...) bypasses tenant scoping` from various files (`AnalyticsService.php`, controllers, services). If the count is dramatically off from the spec's "~30+", investigate before generating the baseline — it means the rule's match pattern is wrong.

- [ ] **Step 2: Generate the baseline**

Run:
```bash
./vendor/bin/phpstan analyse --memory-limit=512M --generate-baseline=phpstan-baseline.neon --allow-empty-baseline
```

Expected: writes `phpstan-baseline.neon` with the violations, exit code 0.

- [ ] **Step 3: Add the baseline to `phpstan.neon` includes**

Edit `phpstan.neon`. Change the `includes:` block from:

```yaml
includes:
    - vendor/larastan/larastan/extension.neon
```

to:

```yaml
includes:
    - vendor/larastan/larastan/extension.neon
    - phpstan-baseline.neon
```

- [ ] **Step 4: Verify PHPStan now passes with the baseline wired in**

Run:
```bash
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -5
```

Expected: `[OK] No errors` (the baseline absorbs every violation).

- [ ] **Step 5: Inspect the baseline file**

Run:
```bash
head -30 phpstan-baseline.neon && echo "---" && wc -l phpstan-baseline.neon
```

Expected: structured YAML entries with `message` and `path` for each grandfathered violation. Line count roughly matches violation count × 4-5 (one block per entry). Confirm the file content looks sane before committing.

- [ ] **Step 6: Commit**

```bash
git add phpstan-baseline.neon phpstan.neon
git commit -m "$(cat <<'EOF'
chore(tenancy): generate PHPStan baseline grandfathering existing tenant_id violations

Records all current raw where('tenant_id', ...) call sites as known debt
and wires the baseline file into phpstan.neon's includes. New code adding
raw form fails CI. The baseline shrinks as Clusters B/C/D touch each file
and convert to forTenant($tenant). Files no subsequent cluster touches
(notably AnalyticsService's ~10 sites) remain until an explicit Cluster E
cleanup sweep.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster A, "Scope of this cluster — prevention, not cure")

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 5 — Browser smoke (boot-hook non-regression)

**Files:** none modified — local browser verification only.

**Purpose:** verify the boot hook fires on create paths in real-world usage without regression. We are NOT testing query correctness (no queries changed in this PR).

- [ ] **Step 1: Start the dev server (skip if already running on 8001)**

Run:
```bash
php artisan serve --port=8001 &
```

- [ ] **Step 2: Smoke flow 1 — Lead capture via widget chat**

Browser: `http://127.0.0.1:8001/widget/test.html`

Steps:
- Send a chat message that includes contact info, e.g., "Hi, my name is Test User and my email is testsmoke@example.com."
- Wait for response.

Verify with tinker:
```bash
php artisan tinker --execute="\$l = \\App\\Models\\Lead::latest()->first(); echo json_encode(['id'=>\$l?->id, 'tenant_id'=>\$l?->tenant_id, 'email'=>\$l?->email]);"
```

Expected: latest lead has `tenant_id` set (non-null) and matches the widget's tenant.

- [ ] **Step 3: Smoke flow 2 — Knowledge upload from client dashboard**

Browser: `http://127.0.0.1:8001/dashboard/knowledge` (login as `test@example.com` / `password` if needed).

Steps:
- Click "Add Knowledge" → "Text".
- Title "Smoke Test"; content "This is a tenant boot-hook smoke test."
- Submit.

Verify:
```bash
php artisan tinker --execute="\$k = \\App\\Models\\KnowledgeItem::latest()->first(); echo json_encode(['id'=>\$k?->id, 'tenant_id'=>\$k?->tenant_id, 'title'=>\$k?->title]);"
```

Expected: `tenant_id` matches the logged-in user's tenant.

- [ ] **Step 4: Smoke flow 3 — Conversation start via widget**

Browser: `http://127.0.0.1:8001/widget/test.html` (fresh session — clear localStorage or use incognito).

Send any chat message. Verify:
```bash
php artisan tinker --execute="\$c = \\App\\Models\\Conversation::latest()->first(); echo json_encode(['id'=>\$c?->id, 'tenant_id'=>\$c?->tenant_id]);"
```

Expected: `tenant_id` set correctly.

- [ ] **Step 5: Smoke flow 4 — Register a new user (boot hook does NOT misfire)**

Browser: open incognito → `http://127.0.0.1:8001/register`.

Register a new account (any email/password). After successful registration:
```bash
php artisan tinker --execute="\$u = \\App\\Models\\User::latest()->first(); echo json_encode(['id'=>\$u?->id, 'email'=>\$u?->email, 'tenant_id'=>\$u?->tenant_id]);"
```

Expected: the new user has `tenant_id` set to the newly-created tenant (from registration flow), NOT inherited from any prior session. Confirms the boot hook does not interfere with multi-tenant onboarding.

- [ ] **Step 6: Take screenshots**

Capture before-merge evidence. Save as `smoke-tenancy-01-lead.png`, `smoke-tenancy-02-knowledge.png`, `smoke-tenancy-03-conversation.png`, `smoke-tenancy-04-register.png` in the repo root.

- [ ] **Step 7: Admin paths — no smoke needed**

Admin uses `Auth::guard('admin')` with a separate `AdminUser` model. Default `Auth::user()` is null in admin requests, so the boot hook is a no-op there. Admin reads are unaffected (they explicitly pass `where('tenant_id', $client->id)` for arbitrary client tenants). No admin → tenant-scoped create paths exist in the codebase as of Task 0 verification. If a future feature adds one, the implementer of that feature is responsible for passing `tenant_id` explicitly.

- [ ] **Step 8: No commit needed** — screenshots are not committed (they're temporary artifacts referenced in the PR description).

---

## Task 6 — Pint, /simplify, Pint, /simplify, PR

**Expected commit count when this PR is ready to push:** 4 feature commits from Tasks 1–4, plus 0–2 `style(pint): apply auto-fixes` commits per Pint pass, plus 0+ commits from each `/simplify` pass. Final total typically 5–10 commits.

- [ ] **Step 1: First Pint pass**

Run:
```bash
./vendor/bin/pint --test
```

If anything is flagged on PR-touched files only (not unrelated repo files):
```bash
./vendor/bin/pint app/Models/Concerns/BelongsToTenant.php app/Rules/PHPStan/NoRawTenantIdWhere.php app/Models/User.php app/Models/Lead.php app/Models/Conversation.php app/Models/KnowledgeItem.php app/Models/UsageRecord.php app/Models/Transaction.php app/Models/EnterpriseInquiry.php tests/Unit/Models/BelongsToTenantTest.php tests/Unit/Rules/
php artisan test
git add -p
git commit -m "style(pint): apply auto-fixes — Cluster A"
```

- [ ] **Step 2: First `/simplify` pass**

Run `/simplify` (the slash command). It dispatches three parallel reviewers (reuse / quality / efficiency) over the diff. Apply real fixes; for stylistic noise, skip with a one-line reason in the response.

- [ ] **Step 3: Second Pint pass**

Run `./vendor/bin/pint --test`. If `/simplify` introduced new code that needs normalization, fix-and-commit as in Step 1.

- [ ] **Step 4: Second `/simplify` pass**

Run `/simplify` again. The first pass's cleanups may have surfaced new opportunities or introduced new minor issues; this catches them.

- [ ] **Step 5: Push branch and create PR**

```bash
git push -u origin HEAD
```

Create the PR:

```bash
gh pr create --title "feat(tenancy): BelongsToTenant trait + Larastan rule + baseline (Cluster A)" --body "$(cat <<'EOF'
## Summary

Adds the prevention infrastructure for tenant-scoping enforcement (Cluster A of the architecture-deepening backlog):

- `App\Models\Concerns\BelongsToTenant` trait — `forTenant($tenant)` scope, `creating` boot-hook auto-stamp from `Auth::user()->tenant_id`, `tenant()` relation. Applied to 7 tenant-scoped models.
- `App\Rules\PHPStan\NoRawTenantIdWhere` — Larastan rule banning new raw `where('tenant_id', ...)` / `whereIn('tenant_id', ...)` / qualified `where('*.tenant_id', ...)` calls on Eloquent builders.
- `phpstan-baseline.neon` — grandfathers all ~30 existing violations as known debt. Baseline shrinks opportunistically through Clusters B/C/D as each file is touched.

## Deploy steps

No DB migrations; no config changes; no env vars. Standard merge → deploy.

**Rollback:** `git revert <merge-sha>` is sufficient. No data migration to reverse; the trait + rule + baseline are additive.

## ⚠️ Behavior changes

- **PHPStan CI**: new code adding raw `where('tenant_id', ...)` patterns on Eloquent models now fails the build. Use `Lead::forTenant($tenant)` (or equivalent on other models) instead.
- **`Lead::create([...])` and equivalents on 7 tenant-scoped models**: when called in a client (web-guard) authed context with `tenant_id` omitted, the boot hook auto-stamps from the authed user's `tenant_id`. Admin/console/queue contexts are unaffected (no authed user under the default guard → hook is a no-op; caller must pass `tenant_id` explicitly).
- **`Conversation::scopeForTenant`**: deleted from the model; trait provides the same scope.
- **No queries converted in this PR.** The 30+ existing raw call sites remain in the baseline; they convert as Clusters B/C/D touch each file. Files no subsequent cluster touches (notably `AnalyticsService`'s 10 sites) remain in baseline indefinitely until a Cluster E cleanup sweep.

## Test plan

- [x] `BelongsToTenantTest` — 7 assertions covering scope (Tenant arg + int arg), boot hook (3 conditions), and relation
- [x] `NoRawTenantIdWhereTest` — 2 test cases (positive: 3 flagged forms; negative: 3 safe forms)
- [x] Full Pest/PHPUnit suite passes
- [x] `./vendor/bin/phpstan analyse` — `[OK] No errors` (baseline absorbs existing violations)
- [x] Browser smoke: widget chat lead capture, dashboard knowledge upload, fresh conversation, new user registration — all stamp `tenant_id` correctly. Screenshots `smoke-tenancy-{01..04}.png` attached.

## Architecture

Cluster A of the 4-cluster architecture-deepening initiative. Prevention infrastructure only — at this PR's merge moment, the cross-tenant leak surface is unchanged from `main`. Cure accumulates through subsequent clusters.

- Spec: `docs/superpowers/specs/2026-05-14-architecture-deepening-design.md`
- Plan: `docs/superpowers/plans/2026-05-14-tenant-scoping.md`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 6: Wait for CI green and merge**

Watch the PR's checks. Fix any failures and re-push. Merge once green.

- [ ] **Step 7: Update memory after merge**

Save a project memory entry capturing what shipped + what's still pending:

```text
medium-cluster-archA-shipped (project memory):
- PR # merged 2026-05-XX; trait + rule + baseline (~30 grandfathered violations).
- Prevention only — no existing call sites converted in this PR.
- Cluster B (knowledge pipeline) next; will convert RetrievalService DB::table queries to Eloquent (closes Rule A coverage gap).
- AnalyticsService's 10 sites unmigrated; need Cluster E cleanup pass after D.
```

Refs the existing memory format used in `~/.claude/projects/-Users-sam-Dev-laravel-chatbot/memory/`.

---

## Self-review summary

Spec coverage check passes:
- Cluster A "Decisions (locked)" table → Task 1 (trait), Task 2 (apply), Task 3 (rule), Task 4 (baseline).
- G-1 (boot hook in non-auth) → Task 0 Step 1 verifies admin-guard separation.
- G-2 (Larastan rule coverage) → Task 3 Step 3 rule limits to Eloquent MethodCall; A→B window noted in PR description.
- G-3 (cluster order) → already enforced at master spec level; nothing here to do.
- G-6 (delete `Conversation::forTenant`) → Task 2 Step 3.
- Plan A Task 0 (factories audit, create-without-tenant_id grep) → Task 0 Steps 2-3.
- Plan A Task 5 (smoke = boot-hook non-regression) → Task 5 entirely.

No placeholders. All file paths absolute under repo root. All commands explicit. All code blocks complete.

Cluster B preview: when B's plan opens, its Task 0 should re-verify that `RetrievalService.php:55, 95` still have `DB::table()->where('ki.tenant_id', ...)` form (could have shifted between A merging and B starting); if so, B's plan rewrites them to Eloquent `KnowledgeChunk::whereHas('knowledgeItem', fn ($q) => $q->forTenant($tenant))`, which closes the A→B window.
