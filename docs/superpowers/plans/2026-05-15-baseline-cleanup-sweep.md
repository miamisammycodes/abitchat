# Plan E — Baseline Tenancy Cleanup Sweep Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert every remaining raw `where('tenant_id', ...)` query in the codebase to the canonical `forTenant($tenant)` scope from `BelongsToTenant`. Shrink the PHPStan baseline by 25 violations (43 → 18) and 4 blocks (22 → 18).

**Architecture:** Pure mechanical refactor. Four files, four blocks, all converted via the same trait scope established in Cluster A. One `whereHas` closure rewrites to a `whereIn` subquery (same pattern Plan B used for `RetrievalService`) because Larastan can't resolve `forTenant` through a closure's `Builder<Model>` parameter. No behavior change; identical SQL semantics.

**Tech Stack:** Laravel 13, Eloquent, PHPStan/Larastan. No new files, no migrations, no routes, no UI.

**Spec:** `docs/superpowers/specs/2026-05-14-architecture-deepening-design.md` ("Cluster E — baseline cleanup sweep").

---

## Scope

**In scope (this PR):**

| File | Violations | Pattern |
|---|---|---|
| `app/Services/Analytics/AnalyticsService.php` | 12 | 11× `Model::where('tenant_id', ...)` + 1× `whereHas('conversation', fn ($q) => $q->where('tenant_id', ...))` |
| `app/Http/Controllers/Admin/ClientController.php` | 8 | All `Model::where('tenant_id', $client->id)` reads inside `show()` |
| `app/Http/Controllers/Api/V1/Widget/ChatController.php` | 3 | All `Conversation::where('id', $id)->where('tenant_id', $tenant->id)` lookups |
| `app/Http/Controllers/Client/LeadController.php` | 2 | `Lead::where('tenant_id', $tenant->id)` in `index()` + `export()` |

**Out of scope (deferred — separate "Plan F" PHPStan baseline cleanup):**

| Category | Count | Why deferred |
|---|---|---|
| `BelongsTo<Tenant, static>` vs `$this` covariance on `tenant()` returns | 7 | Larastan/PHPDoc fluency fix on the `BelongsToTenant` trait — different concern from query refactoring |
| `HasFactory` missing-generics on Conversation/Message/Tenant | 3 | Requires `@use HasFactory<TFactory>` template annotations on each model |
| `UsageTracker::countByPeriod` `HasMany<Model, Tenant>` covariance | 2 | Method signature needs `@template` or generic-aware widening |
| Misc one-off PHPStan warnings | ~6 | `ChatService` alwaysTrue, `HandleInertiaRequests` offset checks, `WidgetController` collect template types, `Tenant::extendPlan` arity, `Admin/ClientController` one-off |

Plan F should ship as a separate small PR once Plan E lands. It's PHPStan-fluency work, not tenancy work — different mindset, different review.

**Plan F sizing note:** the 18-entry count is misleading. 7 of those entries are all variations of the same covariance issue (`BelongsTo<Tenant, static>` vs `$this`) generated per-model by the `BelongsToTenant::tenant()` method docblock. A single edit on the trait — `@return BelongsTo<Tenant, $this>` instead of `<Tenant, static>` — closes all 7 in one stroke. Plan F is materially ~11 entries spread across ~5 fixes, not 18 entries.

---

## File Structure

**Modified files:**
- `app/Services/Analytics/AnalyticsService.php` — 11 mechanical conversions + 1 `whereHas → whereIn` subquery rewrite
- `app/Http/Controllers/Admin/ClientController.php` — 8 mechanical conversions
- `app/Http/Controllers/Api/V1/Widget/ChatController.php` — 3 conversions using the `whereKey()->forTenant()` pattern (same as Plan C used for `Widget/LeadController`)
- `app/Http/Controllers/Client/LeadController.php` — 2 mechanical conversions
- `phpstan-baseline.neon` — remove 4 entire blocks (one per file)

**No new tests.** Each file already has existing test coverage:

| File | Existing test |
|---|---|
| `AnalyticsService.php` | `tests/Unit/Services/Analytics/GetTopQuestionsTest.php`, `tests/Feature/Client/AnalyticsDaysCapTest.php` |
| `Admin/ClientController.php` | `tests/Feature/Admin/AdminClientTrashedTest.php` (covers `show()` path) |
| `Widget/ChatController.php` | `tests/Feature/Widget/ChatSendMessageOrphanTest.php`, `tests/Feature/Widget/ChatStreamMessageOrphanTest.php`, `tests/Feature/WidgetApiTest.php` |
| `Client/LeadController.php` | `tests/Feature/LeadManagementTest.php` |

The existing tests exercise the queries that this plan converts. Mechanical refactor with no behavior change means "existing tests still pass + baseline shrinks + PHPStan stays green" is the entire test plan.

---

## Task 0 — Verifications (no code; probe reality)

**Files:** none modified.

- [ ] **Step 1: Confirm test suite + PHPStan are green at HEAD**

```bash
php artisan test 2>&1 | tail -3
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -3
```

Expected: 335 passing (after Cluster D); PHPStan `[OK] No errors`.

- [ ] **Step 2: Confirm the 4 baseline blocks this PR will retire**

```bash
grep -B1 -A4 "tenancy.rawTenantId" phpstan-baseline.neon | grep -E "count:|path:"
```

Expected exactly 4 path/count pairs (order may vary):
```
			count: 8
			path: app/Http/Controllers/Admin/ClientController.php
			count: 3
			path: app/Http/Controllers/Api/V1/Widget/ChatController.php
			count: 2
			path: app/Http/Controllers/Client/LeadController.php
			count: 12
			path: app/Services/Analytics/AnalyticsService.php
```

Sum: 25 violations. If a count differs by ±1, the file has likely been touched since Cluster D — re-read it and adjust the task body to match reality. If a block is missing entirely (someone already converted it), drop that task from the plan.

Also confirm `reportUnmatchedIgnoredErrors: true` is still set:
```bash
grep -n "reportUnmatchedIgnoredErrors" phpstan.neon
```

- [ ] **Step 3: Confirm models in scope have `BelongsToTenant` trait**

```bash
grep -l "use BelongsToTenant" app/Models/Conversation.php app/Models/Lead.php app/Models/UsageRecord.php app/Models/Transaction.php
```

Expected: all 4 paths echoed. If any file is missing the trait, STOP — the `forTenant($tenant)` scope won't resolve.

Note: `app/Models/Message.php` does **not** have the trait (it's "transitive" — scopes via its parent Conversation). The `AnalyticsService::getTopQuestions` method currently uses `Message::whereHas('conversation', fn ($q) => $q->where('tenant_id', ...))`. Task 1 rewrites this to a `whereIn('conversation_id', Conversation::forTenant($tenant)->select('id'))` subquery — the same pattern Plan B used in `RetrievalService` for `KnowledgeChunk`.

- [ ] **Step 4: Confirm covered test files exist and currently pass**

```bash
ls tests/Unit/Services/Analytics/GetTopQuestionsTest.php \
   tests/Feature/Client/AnalyticsDaysCapTest.php \
   tests/Feature/Admin/AdminClientTrashedTest.php \
   tests/Feature/Widget/ChatSendMessageOrphanTest.php \
   tests/Feature/Widget/ChatStreamMessageOrphanTest.php \
   tests/Feature/WidgetApiTest.php \
   tests/Feature/LeadManagementTest.php
```

All should exist. Then run the union to confirm they're currently green:
```bash
php artisan test --filter='GetTopQuestionsTest|AnalyticsDaysCapTest|AdminClientTrashedTest|ChatSendMessageOrphanTest|ChatStreamMessageOrphanTest|WidgetApiTest|LeadManagementTest' 2>&1 | tail -5
```

Expected: all PASS.

- [ ] **Step 5: Decide whether to proceed**

If every verification matched, proceed to Task 1. If any mismatched, **stop and discuss with the user**.

---

## Task 1 — `AnalyticsService.php` (12 violations)

**Files:**
- Modify: `app/Services/Analytics/AnalyticsService.php`
- Modify: `phpstan-baseline.neon` (remove 1 block)

### Step 1: Confirm current state

```bash
grep -n "where('tenant_id'" app/Services/Analytics/AnalyticsService.php | wc -l
```

Expected: `12` (or one less since the `whereHas` closure line counts too). Adjust task body if drift.

### Step 2: Convert the 11 direct `Model::where('tenant_id', $tenant->id)` calls

Apply these edits one-by-one. Each is a 1:1 substring replacement.

**Edit 1** — line ~26 (`getOverviewStats`):
- Find: `$conversations = Conversation::where('tenant_id', $tenant->id)`
- Replace: `$conversations = Conversation::forTenant($tenant)`

**Edit 2** — line ~29 (`getOverviewStats`):
- Find: `$leads = Lead::where('tenant_id', $tenant->id)`
- Replace: `$leads = Lead::forTenant($tenant)`

**Edit 3** — line ~49 (`getOverviewStats`):
- Find: `$tokenUsage = UsageRecord::where('tenant_id', $tenant->id)`
- Replace: `$tokenUsage = UsageRecord::forTenant($tenant)`

**Edit 4** — line ~76 (`getConversationsOverTime`):
- Find: `$data = Conversation::where('tenant_id', $tenant->id)`
- Replace: `$data = Conversation::forTenant($tenant)`

**Edit 5** — line ~106 (`getLeadsOverTime`):
- Find: `$data = Lead::where('tenant_id', $tenant->id)`
- Replace: `$data = Lead::forTenant($tenant)`

**Edit 6** — line ~136 (`getTokenUsageOverTime`):
- Find: `$data = UsageRecord::where('tenant_id', $tenant->id)`
- Replace: `$data = UsageRecord::forTenant($tenant)`

**Edit 7** — line ~165 (`getLeadScoreDistribution`):
- Find: `$leads = Lead::where('tenant_id', $tenant->id)->get();`
- Replace: `$leads = Lead::forTenant($tenant)->get();`

**Edit 8** — line ~183 (`getLeadStatusDistribution`):
- Find: `return Lead::where('tenant_id', $tenant->id)`
- Replace: `return Lead::forTenant($tenant)`

**Edit 9** — line ~237 (`getConversationsByHour`):
- Find: `$data = Conversation::where('tenant_id', $tenant->id)`
- Replace: `$data = Conversation::forTenant($tenant)`

**Edit 10** — line ~264 (`getRecentActivity`):
- Find: `$conversations = Conversation::where('tenant_id', $tenant->id)`
- Replace: `$conversations = Conversation::forTenant($tenant)`

**Edit 11** — line ~277 (`getRecentActivity`):
- Find: `$leads = Lead::where('tenant_id', $tenant->id)`
- Replace: `$leads = Lead::forTenant($tenant)`

### Step 3: Rewrite the `getTopQuestions` `whereHas` closure as a `whereIn` subquery

Current code (around lines 200–209):

```php
public function getTopQuestions(Tenant $tenant, int $limit = 10): array
{
    $rows = Message::whereHas('conversation', fn ($q) => $q->where('tenant_id', $tenant->id))
        ->where('role', 'user')
        ->where('created_at', '>=', now()->subDays(30))
        ->selectRaw('MAX(content) AS sample, COUNT(*) AS count')
        ->groupBy('content_hash')
        ->orderByDesc('count')
        ->limit($limit)
        ->get();
```

Replace with:

```php
public function getTopQuestions(Tenant $tenant, int $limit = 10): array
{
    $rows = Message::whereIn(
            'conversation_id',
            Conversation::forTenant($tenant)->select('id'),
        )
        ->where('role', 'user')
        ->where('created_at', '>=', now()->subDays(30))
        ->selectRaw('MAX(content) AS sample, COUNT(*) AS count')
        ->groupBy('content_hash')
        ->orderByDesc('count')
        ->limit($limit)
        ->get();
```

Why this rewrite: `whereHas('conversation', fn ($q) => $q->forTenant(...))` is functionally equivalent but Larastan can't resolve `forTenant` through the closure's `Builder<Model>` parameter (the same gap that bit Plan B's `RetrievalService`). The `whereIn` subquery form generates identical SQL (`WHERE conversation_id IN (SELECT id FROM conversations WHERE conversations.tenant_id = ?)`) and is the established workaround.

### Step 4: Remove the AnalyticsService baseline block

Open `phpstan-baseline.neon`. Locate:

```yaml
		-
			message: '#^Raw where\(''tenant_id'', \.\.\.\) bypasses tenant scoping\. Use forTenant\(\$tenant\) instead\.$#'
			identifier: tenancy.rawTenantId
			count: 12
			path: app/Services/Analytics/AnalyticsService.php
```

Delete the entire block (the leading `-` line plus 4 inner lines plus the trailing blank line if present).

### Step 5: Run PHPStan

```bash
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -5
```

Expected: `[OK] No errors`. If PHPStan complains `Ignored error pattern ... was not matched`, the deletion was incomplete or a violation was missed (you forgot to convert a line).

### Step 6: Run the targeted tests

```bash
php artisan test --filter='GetTopQuestionsTest|AnalyticsDaysCapTest' 2>&1 | tail -5
```

Expected: PASS — the `getTopQuestions` rewrite produces equivalent SQL and the test asserts on result shape (counts + sample messages), which is unchanged.

### Step 7: Run the full suite

```bash
php artisan test 2>&1 | tail -3
```

Expected: 335 passing (no regressions).

### Step 8: Pint clean

```bash
./vendor/bin/pint --test app/Services/Analytics/AnalyticsService.php 2>&1 | tail -3
```

Apply if needed: `./vendor/bin/pint app/Services/Analytics/AnalyticsService.php`.

### Step 9: Commit

```bash
git add app/Services/Analytics/AnalyticsService.php phpstan-baseline.neon
git commit -m "$(cat <<'EOF'
refactor(analytics): convert all 12 tenant_id queries to forTenant scope

Eleven direct Model::where('tenant_id', $tenant->id) calls swap to
Model::forTenant($tenant). The remaining whereHas('conversation', ...)
in getTopQuestions rewrites to whereIn('conversation_id',
Conversation::forTenant($tenant)->select('id')) — same SQL semantics,
avoids Larastan's closure-parameter inference gap (the pattern Plan B
established in RetrievalService).

No behavior change. Existing tests (GetTopQuestionsTest,
AnalyticsDaysCapTest) cover the touched paths.

Shrinks the Cluster A baseline by 1 block (12 violations).

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster E)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 2 — `Admin/ClientController.php` (8 violations)

**Files:**
- Modify: `app/Http/Controllers/Admin/ClientController.php`
- Create: `tests/Feature/Admin/AdminClientShowTest.php` — protects the show() page; no existing test hits this route
- Modify: `phpstan-baseline.neon` (remove 1 block)

**Why a new test:** none of the existing admin tests directly GET `/admin/clients/{client}`. The 8 queries in `show()` are unexercised by Pest. A typo'd `forTenant` (wrong arg, wrong tenant scope) would still return zero rows without erroring — PHPStan catches "did the conversion happen", not "do the conversions still produce a working page". The test seeds data per type so all 8 queries return non-empty results and asserts the stats props are populated.

### Step 1: Confirm current state

```bash
grep -n "where('tenant_id'" app/Http/Controllers/Admin/ClientController.php
```

Expected: 8 lines, all inside `show(Tenant $client)`. They use `$client->id` as the tenant identifier — `$client` is the Tenant model passed via route binding.

### Step 2: Write the protection test FIRST (before any conversion)

Create `tests/Feature/Admin/AdminClientShowTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\UsageRecord;
use Tests\TestCase;

class AdminClientShowTest extends TestCase
{
    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::create([
            'name' => 'A',
            'email' => 'a@test.example',
            'password' => bcrypt('x'),
        ]);
    }

    public function test_show_renders_stats_for_a_client_with_data(): void
    {
        $tenant = Tenant::create([
            'name' => 'Show Client',
            'slug' => 'show-client-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        // Seed one of each so all eight queries in show() return non-zero.
        Conversation::create([
            'tenant_id' => $tenant->id,
            'session_id' => 'sess-'.uniqid(),
            'status' => 'active',
        ]);
        Lead::create([
            'tenant_id' => $tenant->id,
            'name' => 'Seed',
            'email' => 'seed@example.com',
            'status' => 'new',
            'score' => 50,
        ]);
        UsageRecord::create([
            'tenant_id' => $tenant->id,
            'type' => 'tokens',
            'quantity' => 1234,
            'period' => now()->format('Y-m'),
        ]);
        $plan = Plan::create([
            'name' => 'P', 'slug' => 'p-'.uniqid(),
            'price' => 0, 'billing_period' => 'monthly', 'is_active' => true,
            'conversations_limit' => 10, 'leads_limit' => 10,
            'tokens_limit' => 1000, 'knowledge_items_limit' => 1,
        ]);
        Transaction::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'amount' => 0,
            'status' => 'paid',
            'reference' => 'tx-'.uniqid(),
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->get("/admin/clients/{$tenant->id}");

        $response->assertStatus(200);

        $stats = $response->viewData('page')['props']['stats'];
        $this->assertGreaterThanOrEqual(1, $stats['conversations']['total']);
        $this->assertGreaterThanOrEqual(1, $stats['leads']['total']);
        $this->assertGreaterThanOrEqual(1234, $stats['tokens']['total']);

        $props = $response->viewData('page')['props'];
        $this->assertNotEmpty($props['transactions']);
        $this->assertNotEmpty($props['recentConversations']);
    }
}
```

The test seeds one of each row type so every query in `show()` returns at least one result. After the refactor, a typo'd `forTenant` call would either error (route 500 — caught by `assertStatus(200)`) or return zero rows from the wrong tenant scope (caught by the `assertGreaterThanOrEqual(1, ...)` assertions). "Seed-then-assert-non-zero" distinguishes "query ran" from "query was correctly scoped".

If the Transaction or Plan model schema differs from the columns above (e.g., extra required NOT NULL fields), adjust the seed payload to satisfy them — the test's job is to get to a green `200` baseline, not to validate row content shape.

### Step 3: Run the new test against the pre-refactor code

```bash
php artisan test --filter=AdminClientShowTest 2>&1 | tail -10
```

Expected: PASS. This is the **green baseline** the refactor must preserve.

If it FAILS, the test is wrong or the seed data is incomplete — fix the test (not the controller) until it's green. The refactor in Step 4 must not touch this test.

### Step 4: Convert all 8 queries

In `show(Tenant $client): Response` (around lines 88–114), each query swaps:

**Edit 1** — line ~88:
- Find: `$conversationsCount = Conversation::where('tenant_id', $client->id)->count();`
- Replace: `$conversationsCount = Conversation::forTenant($client)->count();`

**Edit 2** — line ~89:
- Find: `$conversationsThisMonth = Conversation::where('tenant_id', $client->id)`
- Replace: `$conversationsThisMonth = Conversation::forTenant($client)`

**Edit 3** — line ~93:
- Find: `$leadsCount = Lead::where('tenant_id', $client->id)->count();`
- Replace: `$leadsCount = Lead::forTenant($client)->count();`

**Edit 4** — line ~94:
- Find: `$leadsThisMonth = Lead::where('tenant_id', $client->id)`
- Replace: `$leadsThisMonth = Lead::forTenant($client)`

**Edit 5** — line ~98:
- Find: `$tokensUsed = UsageRecord::where('tenant_id', $client->id)`
- Replace: `$tokensUsed = UsageRecord::forTenant($client)`

**Edit 6** — line ~101:
- Find: `$tokensThisMonth = UsageRecord::where('tenant_id', $client->id)`
- Replace: `$tokensThisMonth = UsageRecord::forTenant($client)`

**Edit 7** — line ~107:
- Find: `$transactions = Transaction::where('tenant_id', $client->id)`
- Replace: `$transactions = Transaction::forTenant($client)`

**Edit 8** — line ~114:
- Find: `$recentConversations = Conversation::where('tenant_id', $client->id)`
- Replace: `$recentConversations = Conversation::forTenant($client)`

Note: this is admin code reading **across tenants** — `$client` is the tenant being viewed by an admin user. The `forTenant($client)` scope is parameterized; it accepts any Tenant or tenant_id and produces the same SQL filter. Semantically identical to the raw form. The `BelongsToTenant` boot hook fires only in client/web-guard contexts (where `Auth::user()->tenant_id` exists); admin paths use the `admin` guard with a separate `AdminUser` model, so no boot-hook side-effect occurs.

### Step 5: Remove the Admin/ClientController tenancy baseline block

Open `phpstan-baseline.neon`. Locate:

```yaml
		-
			message: '#^Raw where\(''tenant_id'', \.\.\.\) bypasses tenant scoping\. Use forTenant\(\$tenant\) instead\.$#'
			identifier: tenancy.rawTenantId
			count: 8
			path: app/Http/Controllers/Admin/ClientController.php
```

Delete the entire block.

**Leave alone** the separate `Admin/ClientController.php` block (count: 1, message about `extendPlan invoked with 2 parameters, 1 required`) — that's a different concern, deferred to Plan F.

### Step 6: Run PHPStan

```bash
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -5
```

Expected: `[OK] No errors`.

### Step 7: Run targeted tests including the new protection test

```bash
php artisan test --filter='AdminClient' 2>&1 | tail -5
```

Expected: PASS. The new `AdminClientShowTest` (added in Step 2) must remain green — that's the contract the refactor must preserve.

### Step 8: Run the full suite

```bash
php artisan test 2>&1 | tail -3
```

Expected: 336 passing (335 baseline + 1 new test).

### Step 9: Pint clean

```bash
./vendor/bin/pint --test app/Http/Controllers/Admin/ClientController.php tests/Feature/Admin/AdminClientShowTest.php 2>&1 | tail -3
```

Apply if needed.

### Step 10: Commit

```bash
git add app/Http/Controllers/Admin/ClientController.php tests/Feature/Admin/AdminClientShowTest.php phpstan-baseline.neon
git commit -m "$(cat <<'EOF'
refactor(admin): convert 8 tenant_id queries in ClientController::show to forTenant

All Conversation/Lead/UsageRecord/Transaction queries inside the admin
client-detail page swap from where('tenant_id', $client->id) to
forTenant($client). Same SQL filter — the scope's first arg accepts any
Tenant model. Admin context uses a separate AdminUser guard, so the
BelongsToTenant boot hook stays a no-op (unchanged from before).

Adds tests/Feature/Admin/AdminClientShowTest covering the previously
unexercised show() route. Seeds one row per type so all 8 queries
return non-empty results; assertions on stats[].total catch
silently-wrong scoping (vs PHPStan only catching syntactic changes).

Shrinks the Cluster A baseline by 1 block (8 violations).

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster E)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 3 — `Widget/ChatController.php` (3 violations)

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Widget/ChatController.php`
- Modify: `phpstan-baseline.neon` (remove 1 block)

### Step 1: Confirm current state

```bash
grep -n "where('tenant_id'" app/Http/Controllers/Api/V1/Widget/ChatController.php
```

Expected: 3 lines, all inside `Conversation::where('id', $conversationId)->where('tenant_id', $tenant->id)->first()` patterns at approximately lines 132, 212, 298.

### Step 2: Convert all 3 lookups to `whereKey()->forTenant()`

This is the same pattern Plan C established for `Widget/LeadController`. Each location:

**Edit 1** — around line 131:

Find:
```php
            $conversation = Conversation::where('id', $conversationId)
                ->where('tenant_id', $tenant->id)
                ->first();
```

Replace with:
```php
            $conversation = Conversation::query()
                ->whereKey($conversationId)
                ->forTenant($tenant)
                ->first();
```

**Edit 2** — around line 211: same replacement as Edit 1 (the inner code body is identical).

**Edit 3** — around line 297:

Find:
```php
        $conversation = Conversation::where('id', $request->conversation_id)
            ->where('tenant_id', $tenant->id)
            ->first();
```

Replace with:
```php
        $conversation = Conversation::query()
            ->whereKey($request->conversation_id)
            ->forTenant($tenant)
            ->first();
```

(Note this third instance uses `$request->conversation_id` instead of the bound variable `$conversationId` — keep the original argument shape.)

### Step 3: Remove the Widget/ChatController baseline block

Open `phpstan-baseline.neon`. Locate:

```yaml
		-
			message: '#^Raw where\(''tenant_id'', \.\.\.\) bypasses tenant scoping\. Use forTenant\(\$tenant\) instead\.$#'
			identifier: tenancy.rawTenantId
			count: 3
			path: app/Http/Controllers/Api/V1/Widget/ChatController.php
```

Delete the entire block.

### Step 4: Run PHPStan

```bash
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -5
```

Expected: `[OK] No errors`.

### Step 5: Run targeted tests

```bash
php artisan test --filter='Chat|Widget' 2>&1 | tail -5
```

Expected: PASS — `ChatSendMessageOrphanTest`, `ChatStreamMessageOrphanTest`, `WidgetApiTest`, `WidgetCorsTest`, `WidgetRateLimitTest`, and `WidgetLeadCaptureTest` together exercise these three lookup paths.

### Step 6: Run the full suite

```bash
php artisan test 2>&1 | tail -3
```

Expected: 335 passing.

### Step 7: Pint clean

```bash
./vendor/bin/pint --test app/Http/Controllers/Api/V1/Widget/ChatController.php 2>&1 | tail -3
```

Apply if needed.

### Step 8: Commit

```bash
git add app/Http/Controllers/Api/V1/Widget/ChatController.php phpstan-baseline.neon
git commit -m "$(cat <<'EOF'
refactor(widget): convert 3 ChatController conversation lookups to forTenant

Replaces three `Conversation::where('id', $id)->where('tenant_id', $tenant->id)`
patterns with `Conversation::query()->whereKey($id)->forTenant($tenant)`.
Same SQL; same single-conversation lookup; uses the canonical tenant
scope. Matches the pattern Plan C established for Widget/LeadController.

Shrinks the Cluster A baseline by 1 block (3 violations).

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster E)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 4 — `Client/LeadController.php` (2 violations)

**Files:**
- Modify: `app/Http/Controllers/Client/LeadController.php`
- Modify: `phpstan-baseline.neon` (remove 1 block)

### Step 1: Confirm current state

```bash
grep -n "where('tenant_id'" app/Http/Controllers/Client/LeadController.php
```

Expected: 2 lines (around line 26 in `index()`, around line 194 in `export()`).

### Step 2: Convert both queries

**Edit 1** — around line 26 (`index`):

Find:
```php
        $query = Lead::where('tenant_id', $tenant->id)
            ->with(['conversation' => fn ($q) => $q->withCount('messages')]);
```

Replace with:
```php
        $query = Lead::forTenant($tenant)
            ->with(['conversation' => fn ($q) => $q->withCount('messages')]);
```

**Edit 2** — around line 194 (`export`):

Find:
```php
        $query = Lead::where('tenant_id', $tenant->id);
```

Replace with:
```php
        $query = Lead::forTenant($tenant);
```

### Step 3: Remove the Client/LeadController baseline block

Open `phpstan-baseline.neon`. Locate:

```yaml
		-
			message: '#^Raw where\(''tenant_id'', \.\.\.\) bypasses tenant scoping\. Use forTenant\(\$tenant\) instead\.$#'
			identifier: tenancy.rawTenantId
			count: 2
			path: app/Http/Controllers/Client/LeadController.php
```

Delete the entire block.

### Step 4: Run PHPStan

```bash
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -5
```

Expected: `[OK] No errors`. **All 4 tenancy blocks now retired** — the baseline should be entirely free of `tenancy.rawTenantId` entries.

Verify:
```bash
grep -c "tenancy.rawTenantId" phpstan-baseline.neon
```

Expected: `0`.

### Step 5: Run targeted tests

```bash
php artisan test --filter='LeadManagementTest' 2>&1 | tail -5
```

Expected: PASS — covers both `index()` and `export()` paths.

### Step 6: Run the full suite

```bash
php artisan test 2>&1 | tail -3
```

Expected: 335 passing.

### Step 7: Pint clean

```bash
./vendor/bin/pint --test app/Http/Controllers/Client/LeadController.php 2>&1 | tail -3
```

Apply if needed.

### Step 8: Commit

```bash
git add app/Http/Controllers/Client/LeadController.php phpstan-baseline.neon
git commit -m "$(cat <<'EOF'
refactor(leads): convert Client/LeadController queries to forTenant

Index and export both swap Lead::where('tenant_id', $tenant->id) for
Lead::forTenant($tenant). Closes the last tenancy.rawTenantId baseline
block — Plan E retires all 25 remaining raw tenant_id violations.

Shrinks the Cluster A baseline by 1 block (2 violations). Baseline now
has zero `tenancy.rawTenantId` entries.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster E)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 5 — Pint, /simplify, Pint, /simplify, PR

**Expected commit count when this PR is ready to push:** 4 feature commits from Tasks 1–4, plus 0–2 `style(pint)` commits per Pint pass, plus 0+ commits from each `/simplify` pass. Typical 5–8 total.

### Step 1: First Pint pass

```bash
./vendor/bin/pint --test $(git diff main...HEAD --name-only --diff-filter=ACMR | grep -E '\.php$' | grep -v phpstan-baseline) 2>&1 | tail -3
```

If flagged, apply + commit as `style(pint): apply auto-fixes — Cluster E`.

### Step 2: First `/simplify` pass

Run `/simplify`. Three parallel reviewers (reuse / quality / efficiency).

What to expect: very little. The diff is mechanical — each conversion is `Model::where('tenant_id', $tenant->id)` → `Model::forTenant($tenant)`, same SQL, same semantics. Reviewers may flag:
- The `whereHas` → `whereIn` rewrite in `AnalyticsService::getTopQuestions` — confirm SQL equivalence is documented in the commit message.
- Any narrative comment referencing "Cluster E" / "Task N" in production code — flag for deletion.
- Whether the 8 `Admin/ClientController::show` queries could share a helper (e.g., a per-tenant stats builder) — almost certainly not worth extracting in this PR; skip unless reviewer makes a strong case.

Apply real fixes; skip stylistic noise.

### Step 3: Second Pint pass

```bash
./vendor/bin/pint --test $(git diff main...HEAD --name-only --diff-filter=ACMR | grep -E '\.php$' | grep -v phpstan-baseline) 2>&1 | tail -3
```

Apply + commit if needed.

### Step 4: Second `/simplify` pass

Run `/simplify` again to catch anything pass 1 introduced.

### Step 5: Push branch and create PR

```bash
git push -u origin HEAD
```

```bash
gh pr create --title "refactor(tenancy): baseline cleanup sweep — 25 tenant_id queries → forTenant (Cluster E)" --body "$(cat <<'EOF'
## Summary

Cluster E of the architecture-deepening initiative. Mechanical refactor that converts every remaining raw `where('tenant_id', ...)` query in the codebase to the canonical `forTenant($tenant)` scope (from the `BelongsToTenant` trait that Cluster A shipped).

**Baseline shrinkage: 43 → 18 violations, 22 → 18 blocks.** All 4 `tenancy.rawTenantId` entries retired:

| File | Violations | Pattern |
|---|---|---|
| `app/Services/Analytics/AnalyticsService.php` | 12 | 11 direct conversions + 1 `whereHas`→`whereIn` subquery rewrite |
| `app/Http/Controllers/Admin/ClientController.php` | 8 | All `show()` cross-tenant reads via `forTenant($client)` |
| `app/Http/Controllers/Api/V1/Widget/ChatController.php` | 3 | `whereKey()->forTenant()` lookups (same pattern as Plan C's `Widget/LeadController`) |
| `app/Http/Controllers/Client/LeadController.php` | 2 | `index()` + `export()` direct conversions |

## Deploy steps

No migrations; no env vars; no route changes; no behavior change. Standard merge → deploy.

**Rollback:** `git revert <merge-sha>` is sufficient.

## :warning: Behavior changes

**None.** Every conversion is a 1:1 SQL-equivalent rewrite. The `forTenant($tenant)` scope produces the same `WHERE tenant_id = ?` filter the raw form did. Test coverage on each touched file (Analytics, Admin client detail, Widget chat, Client lead management) catches any regression — all existing tests pass without modification.

The one non-trivial rewrite is `AnalyticsService::getTopQuestions`'s `whereHas('conversation', ...)` → `whereIn('conversation_id', Conversation::forTenant($tenant)->select('id'))`. Same SQL semantics (`WHERE conversation_id IN (SELECT id FROM conversations WHERE conversations.tenant_id = ?)`). Workaround for Larastan's closure-parameter inference gap, same pattern Plan B established for `RetrievalService`.

## Test plan

- [x] `GetTopQuestionsTest` + `AnalyticsDaysCapTest` — Analytics paths
- [x] `AdminClientTrashedTest` — admin client-detail path
- [x] `ChatSendMessageOrphanTest`, `ChatStreamMessageOrphanTest`, `WidgetApiTest`, `WidgetCorsTest`, `WidgetRateLimitTest`, `WidgetLeadCaptureTest` — widget chat paths
- [x] `LeadManagementTest` — client lead-management paths
- [x] Full Pest suite: 335 passing
- [x] `./vendor/bin/phpstan analyse` → `[OK] No errors`. Baseline 22 → 18 blocks, 43 → 18 violations.

## Out of scope (deferred to Plan F)

The remaining 18 baseline entries are PHPStan-fluency issues unrelated to tenant scoping:

- 7 covariance entries: `BelongsTo<Tenant, static>` vs `$this` on `tenant()` returns (per-model entries; fix on `BelongsToTenant` trait docblock)
- 3 `HasFactory` missing-generics on `Conversation`/`Message`/`Tenant`
- 2 `UsageTracker::countByPeriod` `HasMany<Model, Tenant>` covariance
- ~6 misc one-offs (`ChatService` alwaysTrue, `HandleInertiaRequests` offset checks, `WidgetController` collect template types, `Tenant::extendPlan` arity, `Admin/ClientController` `extendPlan` mismatch)

These need different mindset — PHPDoc generics, template annotations, type narrowing — so they're better off as a separate small PR after Plan E lands.

## Architecture

Cluster E of the 4+1-cluster architecture-deepening initiative. **Final tenancy-focused PR.** All four deepening clusters (A/B/C/D) plus the baseline-cleanup sweep are now done.

- Spec: `docs/superpowers/specs/2026-05-14-architecture-deepening-design.md`
- Plan: `docs/superpowers/plans/2026-05-15-baseline-cleanup-sweep.md`
- Prior PRs: #18 (A), #19 (B), #20 (C), #21 (D)

:robot: Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

### Step 6: Wait for CI green and merge

Watch checks. Fix any failures and re-push.

### Step 7: Update memory after merge

Save a project memory entry at `~/.claude/projects/-Users-sam-Dev-laravel-chatbot/memory/arch_cluster_e_shipped.md` capturing:
- PR # + merge SHA
- Final baseline state: 18 entries remaining, all non-tenancy
- That **all `tenancy.rawTenantId` violations are now gone** — the Larastan rule from Cluster A is fully enforcing the canonical scope across the codebase
- Plan F status: 18 PHPStan-fluency entries (covariance / generics / one-offs) still in the baseline; deferred to a follow-up PR

---

## Self-review summary

**Spec coverage:** the master spec at `docs/superpowers/specs/2026-05-14-architecture-deepening-design.md` calls Cluster E the "baseline cleanup sweep" and specifically names AnalyticsService's 10 sites (now 12 after this verification). Every named site is covered by Task 1.

**Scope discipline:** plan is intentionally narrow — tenancy-only. The 18 non-tenancy entries are flagged for Plan F. This keeps the PR mechanical and easy to review.

**No placeholders:** every Edit step shows the exact find + replace text. Every PHPStan baseline removal shows the exact YAML block. Every commit body is written out.

**Type consistency:** `forTenant($tenant)` (Tenant model) vs `forTenant($client)` (also a Tenant model; just the admin parameter naming) — both work because the trait's scope accepts `Tenant|int`. Tasks 1, 2, 4 use `$tenant`; Task 2 uses `$client` to match the admin route binding. Consistent within each file.

**Risk assessment:**
- AnalyticsService `whereHas → whereIn` rewrite: semantic-equivalence is the main risk. `GetTopQuestionsTest` exercises exactly this method and asserts on result counts + message contents. Should catch any regression.
- Other 24 conversions: pure substring substitution. SQL difference is `tenant_id = ?` vs `conversations.tenant_id = ?` (qualified). Identical execution plan.

**Why one new test (Task 2 only):** mechanical refactor with full existing coverage on Tasks 1, 3, 4. Task 2's `Admin/ClientController::show()` is the exception — no existing test directly hits its route, so PHPStan would catch syntax errors but not "queries return correctly-scoped results after refactor". The new `AdminClientShowTest` closes that gap. Tasks 1, 3, 4 stay on existing tests.

**Why no browser smoke:** no UI surface changes; all paths are backend query refactors that the Pest suite covers.

**Plan size:** 4 implementation tasks + Task 0 + Task 5 = 6 tasks total. Smallest cluster plan after Plan D. Should ship in one session.
