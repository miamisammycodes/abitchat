# Plan D — `UsageTracker::canRecordUsage` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the "may this tenant use more of type X?" decision from middleware (`CheckUsageLimits.php`) into the canonical `UsageTracker`. Future grace periods / soft caps / overage allowances will now have a natural place to live.

**Architecture:** Add `UsageTracker::canRecordUsage(Tenant, string $type): bool`. It returns `false` when `remaining()` is finite and `≤ 0`, `true` otherwise (unlimited, unknown type, or remaining > 0). Middleware swaps its inline `$remaining = remaining(...); if ($remaining === 0)` pair for a single `! canRecordUsage(...)` call — one line shorter, one fewer local variable, but the real win is naming the concept. HTTP-layer concerns (tenant resolution, isActive check, has-plan-or-trial check, message formatting) stay in middleware.

**Tech Stack:** Laravel 13, PHP 8.3, PHPUnit. Pure refactor — no migrations, no UI, no routes.

**Spec:** `docs/superpowers/specs/2026-05-14-architecture-deepening-design.md` (Cluster D).

---

## Background — exact semantics of `canRecordUsage`

| `remaining($tenant, $type)` returns | `canRecordUsage($tenant, $type)` returns | Rationale |
|---|---|---|
| `null` (limit is `-1` or type missing) | `true` | Unlimited / unconfigured = can record |
| `0` (limit `0` or limit `N` with used ≥ `N`) | `false` | Quota hit = blocked |
| `> 0` | `true` | Quota remaining |
| `< 0` (currently impossible — `remaining()` clamps to ≥ 0) | `false` | Defensive: if the clamp is ever removed, over-consumed tenants stay blocked |

The `<= 0` check (vs. the middleware's current `=== 0`) is the spec's stated semantic correctness fix. No production case is known where it changes outcomes today (`remaining()` clamps in `UsageTracker.php:135`), but it future-proofs the check.

**Opportunistic baseline cleanup** while we're touching `UsageTracker.php`: one raw `where('tenant_id', ...)` in `usageInPeriod` (the `TYPE_TOKENS` branch at line ~74) converts to `UsageRecord::forTenant($tenant)`. Drops the file's 1-violation tenancy baseline entry (44 → 43 total violations). The two pre-existing covariance baseline entries for `countByPeriod` (lines ~144) remain — they're a separate PHPStan generics issue, out of scope.

---

## File Structure

**Modified files:**
- `app/Services/Usage/UsageTracker.php` — add `canRecordUsage` method; opportunistically convert the `TYPE_TOKENS` `where('tenant_id', ...)` query to `forTenant($tenant)`
- `app/Http/Middleware/CheckUsageLimits.php` — replace the inline `remaining(...) === 0` test with `! $this->tracker->canRecordUsage(...)`
- `phpstan-baseline.neon` — remove the 1 `UsageTracker.php` tenancy entry

**New test file:**
- `tests/Unit/Services/Usage/UsageTrackerCanRecordUsageTest.php` — unit tests for the new method

**Out of scope** (spec-locked):
- `UsageDecision` result type (Fork F1)
- New `UsageGate` module (Fork F3)
- Grace period rules / soft caps / overage allowances
- The two pre-existing PHPStan covariance baseline entries for `countByPeriod`

---

## Task 0 — Verifications (no code; probe reality)

**Files:** none modified.

### Step 1: Verify `UsageTracker::remaining` still has the assumed shape

```bash
sed -n '119,136p' app/Services/Usage/UsageTracker.php
```

Expected: a `public function remaining(Tenant $tenant, string $type): ?int` that
- returns `null` when limit is `null` or `-1`
- returns `0` when limit is `0`
- returns `max(0, $limit - $used)` otherwise

If the body has shifted (e.g., no longer clamps with `max(0, ...)`), STOP — the plan's semantics depend on this.

### Step 2: Verify `usageInPeriod` still has the raw `where('tenant_id', ...)` we plan to convert

```bash
sed -n '71,83p' app/Services/Usage/UsageTracker.php
```

Expected: `TYPE_TOKENS` branch reads `(int) UsageRecord::where('tenant_id', $tenant->id)->where('type', self::TYPE_TOKENS)->where('period', $period)->sum('quantity')`. The other three branches use Eloquent relations (no `tenant_id` literal) — leave them alone.

### Step 3: Verify `CheckUsageLimits::handle` still has the inline `=== 0` check

```bash
sed -n '46,58p' app/Http/Middleware/CheckUsageLimits.php
```

Expected:
```
$remaining = $this->tracker->remaining($tenant, $type);
if ($remaining === 0) {
    $messages = [
        'conversations' => 'You have reached your monthly conversation limit.',
        ...
```

If the check has already been refactored, STOP.

### Step 4: Verify the 1 baseline entry this PR will retire

```bash
grep -B1 -A4 "tenancy.rawTenantId" phpstan-baseline.neon | grep -E "count:|path:" | grep -E "Usage/UsageTracker"
```

Expected:
```
			count: 1
			path: app/Services/Usage/UsageTracker.php
```

If absent (the count is on a different file path, or the block is missing entirely), STOP — the baseline has drifted.

Confirm `reportUnmatchedIgnoredErrors: true` is still set:
```bash
grep -n "reportUnmatchedIgnoredErrors" phpstan.neon
```

Expected: a line containing `reportUnmatchedIgnoredErrors: true`.

### Step 5: Confirm test suite + PHPStan are green at HEAD

```bash
php artisan test 2>&1 | tail -3
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -3
```

Expected: full suite passes (329 expected after Cluster C); PHPStan `[OK] No errors`.

### Step 6: Confirm `UsageRecord` has the `BelongsToTenant` trait (and therefore `forTenant`)

```bash
grep -n "BelongsToTenant\|forTenant\|tenant_id" app/Models/UsageRecord.php
```

Expected: `use App\Models\Concerns\BelongsToTenant;` import + `use BelongsToTenant` in the trait list. If absent, the `forTenant($tenant)` conversion in Step 1 of Task 1 won't work — STOP and add the trait first (it was supposed to be applied in Cluster A's Task 2).

### Step 7: Confirm `CheckUsageLimits::handle` is the only `remaining()` caller doing a threshold check

The plan replaces the middleware's `=== 0` test with `! canRecordUsage(...)`. If any *other* caller in the codebase also tests `remaining() === 0` (or `<= 0`), they'd be left inconsistent — silently passing the old strict check while the canonical decision now uses the new defensive semantics. Survey:

```bash
grep -rn "->remaining(" app/ tests/ 2>/dev/null | grep -v "UsageTracker.php\|UsageTrackerRemainingTest"
grep -rn "remaining" app/Http/Middleware/ app/Services/ app/Http/Controllers/ 2>/dev/null | grep -v "UsageTracker.php"
```

Expected: the only result is `CheckUsageLimits.php:46–47`. Read-only references that just display the number (e.g., on a dashboard) are fine — only *threshold comparisons* against 0 are at risk.

If the survey turns up another threshold-comparing caller (e.g., a controller or service that does `if ($tracker->remaining(...) === 0)`), STOP and decide:
- Migrate that caller to `canRecordUsage` in this PR (simplest), OR
- Explicitly note in the PR description that the caller stays on the old check and is a Cluster E sweep candidate.

Don't ship inconsistency by accident.

### Step 8: Decide whether to proceed

If every verification matched expectations, proceed to Task 1. If anything mismatched, **stop and discuss with the user before proceeding**. Do not modify this plan file mid-execution.

---

## Task 1 — Add `canRecordUsage` to `UsageTracker` + unit tests; convert the `TYPE_TOKENS` query to `forTenant`

**Files:**
- Modify: `app/Services/Usage/UsageTracker.php`
- Create: `tests/Unit/Services/Usage/UsageTrackerCanRecordUsageTest.php`
- Modify: `phpstan-baseline.neon` (remove 1 entry)

### Step 1: Write the failing test

Create `tests/Unit/Services/Usage/UsageTrackerCanRecordUsageTest.php` verbatim:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Usage;

use App\Models\Conversation;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Usage\UsageTracker;
use Tests\TestCase;

class UsageTrackerCanRecordUsageTest extends TestCase
{
    private function makeTenantWithPlan(array $limits): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Quota Co',
            'slug' => 'quota-co-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => null,
        ]);

        $plan = Plan::create([
            'name' => 'Test Plan',
            'slug' => 'test-plan-'.uniqid(),
            'price' => 100,
            'billing_period' => 'monthly',
            'is_active' => true,
            'conversations_limit' => $limits['conversations_limit'] ?? 100,
            'leads_limit' => $limits['leads_limit'] ?? 50,
            'tokens_limit' => $limits['tokens_limit'] ?? 10000,
            'knowledge_items_limit' => $limits['knowledge_items_limit'] ?? 5,
        ]);

        $tenant->update([
            'plan_id' => $plan->id,
            'plan_expires_at' => now()->addMonth(),
        ]);

        return $tenant->fresh();
    }

    private function consumeConversations(Tenant $tenant, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Conversation::create([
                'tenant_id' => $tenant->id,
                'session_id' => 'sess-'.uniqid(),
                'status' => 'active',
            ]);
        }
        // Bust the per-tenant usage cache so the next remaining() call hits DB.
        app(UsageTracker::class)->forgetCache($tenant);
    }

    public function test_returns_true_when_limit_is_unlimited(): void
    {
        $tenant = $this->makeTenantWithPlan(['conversations_limit' => -1]);

        $this->assertTrue(
            app(UsageTracker::class)->canRecordUsage($tenant, UsageTracker::TYPE_CONVERSATIONS),
            'Unlimited (-1) must allow recording'
        );
    }

    public function test_returns_false_when_limit_is_zero(): void
    {
        $tenant = $this->makeTenantWithPlan(['conversations_limit' => 0]);

        $this->assertFalse(
            app(UsageTracker::class)->canRecordUsage($tenant, UsageTracker::TYPE_CONVERSATIONS),
            'A hard limit of 0 must block all recording'
        );
    }

    public function test_returns_true_when_under_limit(): void
    {
        $tenant = $this->makeTenantWithPlan(['conversations_limit' => 10]);
        $this->consumeConversations($tenant, 5);

        $this->assertTrue(
            app(UsageTracker::class)->canRecordUsage($tenant, UsageTracker::TYPE_CONVERSATIONS),
            'remaining = 5 must allow recording'
        );
    }

    public function test_returns_false_at_exactly_the_limit(): void
    {
        $tenant = $this->makeTenantWithPlan(['conversations_limit' => 3]);
        $this->consumeConversations($tenant, 3);

        $this->assertFalse(
            app(UsageTracker::class)->canRecordUsage($tenant, UsageTracker::TYPE_CONVERSATIONS),
            'remaining = 0 (used == limit) must block recording'
        );
    }

    public function test_returns_false_when_over_consumed(): void
    {
        // Edge case: even though remaining() clamps to >= 0 today, the
        // canRecordUsage semantic is "<= 0 blocks" — if the clamp is ever
        // removed and remaining returns a negative number, the gate must
        // still block. With current clamping, used > limit yields remaining=0
        // which is also blocked, so this test asserts the practical outcome.
        $tenant = $this->makeTenantWithPlan(['conversations_limit' => 2]);
        $this->consumeConversations($tenant, 5);

        $this->assertFalse(
            app(UsageTracker::class)->canRecordUsage($tenant, UsageTracker::TYPE_CONVERSATIONS),
            'Over-consumed tenants must be blocked'
        );
    }

    public function test_returns_true_for_unknown_type(): void
    {
        $tenant = $this->makeTenantWithPlan([]);

        $this->assertTrue(
            app(UsageTracker::class)->canRecordUsage($tenant, 'unknown_type'),
            'Unknown types treat as unlimited (true)'
        );
    }
}
```

### Step 2: Run the test to verify it fails

```bash
php artisan test --filter=UsageTrackerCanRecordUsageTest 2>&1 | tail -10
```

Expected: FAIL — `Method App\Services\Usage\UsageTracker::canRecordUsage() does not exist` (or similar Mockery / runtime error).

### Step 3: Implement `canRecordUsage` + convert the `TYPE_TOKENS` query

Open `app/Services/Usage/UsageTracker.php`. Apply two edits.

**Edit A** — convert the `TYPE_TOKENS` query in `usageInPeriod` from raw `where('tenant_id', ...)` to `forTenant($tenant)`. Find the block (around lines 73–77):

```php
return match ($type) {
    self::TYPE_TOKENS => (int) UsageRecord::where('tenant_id', $tenant->id)
        ->where('type', self::TYPE_TOKENS)
        ->where('period', $period)
        ->sum('quantity'),
```

Replace with:

```php
return match ($type) {
    self::TYPE_TOKENS => (int) UsageRecord::forTenant($tenant)
        ->where('type', self::TYPE_TOKENS)
        ->where('period', $period)
        ->sum('quantity'),
```

**Edit B** — add the `canRecordUsage` method immediately after the existing `remaining()` method (around line 136). Insert:

```php
    /**
     * May this tenant record more usage of the given type?
     *
     * Returns true when the type is unlimited / unknown OR when remaining > 0.
     * Returns false when remaining is finite AND ≤ 0. The `<= 0` check (vs a
     * strict `=== 0`) is defensive: today `remaining()` clamps to ≥ 0, but if
     * an over-consumed tenant somehow lands a negative remainder, the gate
     * must still block. Future grace periods / soft caps / overage allowances
     * would slot in here.
     */
    public function canRecordUsage(Tenant $tenant, string $type): bool
    {
        $remaining = $this->remaining($tenant, $type);

        return $remaining === null || $remaining > 0;
    }
```

The implementation is the spec's contract restated in positive form: `remaining === null || remaining > 0` is equivalent to `! ($remaining !== null && $remaining <= 0)`. Negative remainders fail the `> 0` check, satisfying the defensive case without an explicit `<= 0` branch.

### Step 4: Run the test to verify it passes

```bash
php artisan test --filter=UsageTrackerCanRecordUsageTest 2>&1 | tail -10
```

Expected: PASS — all 6 tests green.

### Step 5: Run the full test suite

```bash
php artisan test 2>&1 | tail -5
```

Expected: 329 + 6 = 335 passing. `UsageTrackerRemainingTest` (existing) and `UsageTrackerTest` continue to pass — `remaining()` behavior is unchanged.

### Step 6: Remove the baseline entry for `UsageTracker.php`

Open `phpstan-baseline.neon`. Locate:

```yaml
		-
			message: '#^Raw where\(''tenant_id'', \.\.\.\) bypasses tenant scoping\. Use forTenant\(\$tenant\) instead\.$#'
			identifier: tenancy.rawTenantId
			count: 1
			path: app/Services/Usage/UsageTracker.php
```

Delete the entire block.

Leave the other two `UsageTracker.php` entries (covariance argument-type errors in `countByPeriod`) alone — they're separate, out of scope.

### Step 7: Run PHPStan

```bash
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -5
```

Expected: `[OK] No errors`. If PHPStan complains `Ignored error pattern ... was not matched`, the deletion was incomplete; re-grep the baseline.

### Step 8: Pint clean

```bash
./vendor/bin/pint --test app/Services/Usage/UsageTracker.php tests/Unit/Services/Usage/UsageTrackerCanRecordUsageTest.php 2>&1 | tail -3
```

Apply if needed.

### Step 9: Commit

```bash
git add app/Services/Usage/UsageTracker.php tests/Unit/Services/Usage/UsageTrackerCanRecordUsageTest.php phpstan-baseline.neon
git commit -m "$(cat <<'EOF'
feat(usage): add UsageTracker::canRecordUsage; convert tokens query to forTenant

canRecordUsage(Tenant, string $type): bool moves the "may this tenant
record more of type X" decision into the canonical owner. Returns true
for unlimited / unknown types; returns false when remaining() is finite
and ≤ 0. The `<= 0` semantics (vs. an `=== 0` check) is defensive — if
remaining() ever stops clamping to ≥ 0, over-consumed tenants still
block. Grace periods / soft caps / overage allowances would slot in
here when product asks.

usageInPeriod's TYPE_TOKENS branch opportunistically converts from
UsageRecord::where('tenant_id', ...) to UsageRecord::forTenant($tenant)
— drops 1 baseline entry. The other 3 branches use Eloquent relations
already.

The middleware (CheckUsageLimits) still calls remaining(...) === 0
directly at this commit. The next task swaps it to ! canRecordUsage(...).

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster D)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 2 — Wire `CheckUsageLimits` middleware to `canRecordUsage`

**Files:**
- Modify: `app/Http/Middleware/CheckUsageLimits.php`

### Step 1: Rewrite the limit-check block in `CheckUsageLimits::handle`

Open `app/Http/Middleware/CheckUsageLimits.php`. Locate the current block (around lines 46–58):

```php
        $remaining = $this->tracker->remaining($tenant, $type);
        if ($remaining === 0) {
            $messages = [
                'conversations' => 'You have reached your monthly conversation limit.',
                'knowledge_items' => 'You have reached your knowledge items limit.',
                'leads' => 'You have reached your monthly leads limit.',
                'tokens' => 'You have reached your monthly token limit.',
            ];
            $message = ($messages[$type] ?? 'You have reached your usage limit.')
                .' Please upgrade your plan.';

            return $this->reject($isJson, $message, 'LIMIT_REACHED', 403, ['limit_type' => $type]);
        }
```

Replace with:

```php
        if (! $this->tracker->canRecordUsage($tenant, $type)) {
            $messages = [
                'conversations' => 'You have reached your monthly conversation limit.',
                'knowledge_items' => 'You have reached your knowledge items limit.',
                'leads' => 'You have reached your monthly leads limit.',
                'tokens' => 'You have reached your monthly token limit.',
            ];
            $message = ($messages[$type] ?? 'You have reached your usage limit.')
                .' Please upgrade your plan.';

            return $this->reject($isJson, $message, 'LIMIT_REACHED', 403, ['limit_type' => $type]);
        }
```

Net change: the `$remaining = $this->tracker->remaining(...)` assignment + the `$remaining === 0` test collapse from 2 lines into 1 line (the `! canRecordUsage(...)` if-condition). One fewer local variable. Type→message map, JSON-vs-redirect formatting, status code, all preserved.

### Step 2: Run the full suite

```bash
php artisan test 2>&1 | tail -5
```

Expected: 335 passing. The relevant end-to-end test is `tests/Feature/KnowledgeBaseTest.php::test_create_blocked_when_knowledge_items_limit_reached` — it sets a plan with `knowledge_items_limit = 1`, creates one item, then posts a second item creation request and asserts the middleware redirects to `client.billing.plans`. This now exercises the `canRecordUsage` path; should pass unchanged.

If it fails, inspect — but the behavior contract is unchanged for any tenant whose `remaining()` is currently `0`: both old (`=== 0`) and new (`canRecordUsage → false`) reject equivalently.

### Step 3: PHPStan

```bash
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -3
```

Expected: `[OK] No errors`.

### Step 4: Pint clean

```bash
./vendor/bin/pint --test app/Http/Middleware/CheckUsageLimits.php 2>&1 | tail -3
```

Apply if needed.

### Step 5: Commit

```bash
git add app/Http/Middleware/CheckUsageLimits.php
git commit -m "$(cat <<'EOF'
refactor(usage): CheckUsageLimits middleware delegates to canRecordUsage

Replaces the inline `$remaining = $this->tracker->remaining(...); if
($remaining === 0)` block with `! $this->tracker->canRecordUsage(...)`.
Net ~3 lines simpler. The canonical UsageTracker now owns the decision;
future grace periods / soft caps will live in canRecordUsage.

Behavior unchanged for any tenant whose remaining() is currently 0:
both the old `=== 0` and the new `canRecordUsage → false` reject
equivalently. The new check also blocks negative remainders (currently
unreachable via remaining()'s clamp, but future-proofed if the clamp
is ever removed).

HTTP-layer concerns stay in the middleware: tenant resolution (auth +
api_key + cache), isActive() check, hasPlan() OR isOnTrial() check,
type→message map, JSON-vs-redirect formatting.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster D)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 3 — Pint, /simplify, Pint, /simplify, PR

**Expected commit count when this PR is ready to push:** 2 feature commits from Tasks 1–2, plus 0–2 `style(pint)` commits per Pint pass, plus 0+ commits from each `/simplify` pass. Typical total 3–6 commits.

### Step 1: First Pint pass

```bash
./vendor/bin/pint --test $(git diff main...HEAD --name-only --diff-filter=ACMR | grep -E '\.php$' | grep -v phpstan-baseline) 2>&1 | tail -3
```

If anything is flagged:
```bash
./vendor/bin/pint $(git diff main...HEAD --name-only --diff-filter=ACMR | grep -E '\.php$' | grep -v phpstan-baseline)
php artisan test
git add -p
git commit -m "style(pint): apply auto-fixes — Cluster D"
```

### Step 2: First `/simplify` pass

Run `/simplify`. It dispatches three parallel reviewers (reuse / quality / efficiency). Apply real fixes; skip stylistic noise with a one-line reason.

Watch specifically for:
- The `canRecordUsage` body — is `$remaining === null || $remaining > 0` clearer than `! ($remaining !== null && $remaining <= 0)`? (The plan chose positive form for readability; defend or apply suggested alternatives.)
- Any narrative comment referencing "Cluster D" or "Task N" in production code.
- The middleware's `$messages` array — is it now dead-weight at the same indent level as the helper, or fine where it is?

### Step 3: Second Pint pass

```bash
./vendor/bin/pint --test $(git diff main...HEAD --name-only --diff-filter=ACMR | grep -E '\.php$' | grep -v phpstan-baseline) 2>&1 | tail -3
```

If `/simplify` introduced new code that needs normalization, fix-and-commit.

### Step 4: Second `/simplify` pass

Run `/simplify` again. First-pass cleanups can introduce new minor issues.

### Step 5: Push branch and create PR

```bash
git push -u origin HEAD
```

```bash
gh pr create --title "feat(usage): canRecordUsage in UsageTracker; middleware delegates (Cluster D)" --body "$(cat <<'EOF'
## Summary

Cluster D of the architecture-deepening backlog. Small honest deepening — moves the "may this tenant record more of type X" decision from `CheckUsageLimits` middleware into the canonical `UsageTracker`. The middleware's `$remaining = remaining(...); if ($remaining === 0)` pair collapses into one `! canRecordUsage(...)` call. Future grace periods / soft caps / overage allowances now have a natural home.

- **`UsageTracker::canRecordUsage(Tenant, string $type): bool`** — new public method. Returns `true` when the type is unlimited / unknown OR when `remaining() > 0`; returns `false` when `remaining()` is finite AND `≤ 0`.
- **`CheckUsageLimits::handle`** — replaces inline `$remaining = $tracker->remaining(...); if ($remaining === 0)` with `! $this->tracker->canRecordUsage(...)`.
- **Opportunistic baseline cleanup:** `UsageTracker::usageInPeriod`'s `TYPE_TOKENS` branch converts from `UsageRecord::where('tenant_id', $tenant->id)` to `UsageRecord::forTenant($tenant)`. **Baseline shrinks from 44 → 43 violations (23 → 22 blocks).**

## Deploy steps

No migrations; no env vars; no route changes. Standard merge → deploy.

**Rollback:** `git revert <merge-sha>` is sufficient.

## :warning: Behavior changes

- **Threshold tightened from `=== 0` to "`<= 0` or null-aware true".** Practically equivalent today because `remaining()` clamps to ≥ 0, so `=== 0` and the new `! canRecordUsage(...)` both reject identically. The defensive `<= 0` semantics future-proofs the gate: if the clamp is ever removed or bypassed, an over-consumed tenant (negative remainder) still blocks correctly. No known production case where this changes outcomes.
- **HTTP layer unchanged.** Tenant resolution (auth + api_key + cache), `isActive()` check, `hasPlan() OR isOnTrial()` check, type→message map, JSON-vs-redirect response formatting all stay in the middleware.

## Test plan

- [x] `UsageTrackerCanRecordUsageTest` — 6 unit tests covering all branches (unlimited, zero limit, under limit, at limit, over-consumed, unknown type)
- [x] `UsageTrackerRemainingTest` and `UsageTrackerTest` continue to pass — `remaining()` behavior unchanged
- [x] `KnowledgeBaseTest::test_create_blocked_when_knowledge_items_limit_reached` exercises the middleware's blocked-path end-to-end; verifies the swap to `canRecordUsage` preserves rejection behavior
- [x] Full Pest suite green
- [x] `./vendor/bin/phpstan analyse` → `[OK] No errors`; baseline shrunk from 44 → 43 violations (1 block removed: `UsageTracker.php` tenancy entry)

## Architecture

Cluster D of the 4-cluster architecture-deepening initiative. Standalone PR; doesn't share files with Cluster A/B/C.

- Spec: `docs/superpowers/specs/2026-05-14-architecture-deepening-design.md`
- Plan: `docs/superpowers/plans/2026-05-15-usage-tracker.md`
- Prior clusters: PR #18 (Cluster A — tenant scoping), PR #19 (Cluster B — knowledge pipeline), PR #20 (Cluster C — lead scoring)

After this merges, all four cluster-level deepenings are complete. The remaining work is **Cluster E**: a baseline-cleanup sweep that converts the ~22 remaining baseline entries (chiefly `AnalyticsService`'s 12-entry block, plus `Admin/ClientController` 8, `Widget/ChatController` 3, `Client/LeadController` 2, the 2 pre-existing `UsageTracker::countByPeriod` covariance entries, and a few smaller blocks) to `forTenant()` mechanically.

:robot: Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

### Step 6: Wait for CI green and merge

Watch the PR's checks. Fix any failures and re-push. Merge once green.

### Step 7: Update memory after merge

Save a project memory entry at `~/.claude/projects/-Users-sam-Dev-laravel-chatbot/memory/arch_cluster_d_shipped.md` capturing:
- PR # + merge SHA
- Baseline shrinkage (44 → 43)
- That Cluster E is the only remaining work (baseline cleanup sweep)
- Pattern worth remembering: "future grace periods slot in here" — `canRecordUsage` is now the documented insertion point.

---

## Self-review summary

**Spec coverage check (Cluster D section of `docs/superpowers/specs/2026-05-14-architecture-deepening-design.md`):**

| Spec row | Implemented in |
|---|---|
| Approach F2 (no UsageDecision, no UsageGate) | Whole plan — no new types or modules |
| New method `canRecordUsage(Tenant, string): bool` returns `false` when `remaining !== null && remaining <= 0` | Task 1 Step 3 Edit B |
| Tightens `=== 0` to `<= 0` (defensive correctness) | Task 1 Step 3 implementation comment + Task 2 Step 1 swap |
| Middleware swap: `remaining(...) === 0` → `! canRecordUsage(...)` | Task 2 |
| HTTP-layer concerns stay in middleware | Task 2 (only the limit-check block changes; resolveTenant + reject + isActive + plan checks untouched) |

**Out-of-scope confirmed not addressed:**
- Grace periods / soft caps / overage allowances themselves
- `UsageDecision` result type
- `UsageGate` module
- The pre-existing covariance baseline entries for `countByPeriod`

**Placeholder scan:** no `TBD`, no `TODO`, no "implement later". Every code block is complete.

**Type consistency:** `canRecordUsage(Tenant $tenant, string $type): bool` is used identically in Task 1 (definition + tests) and Task 2 (middleware call). `remaining()` reference is unchanged.

**Baseline-shrink discipline:** Task 1 Step 6 removes exactly the 1 entry. `reportUnmatchedIgnoredErrors: true` will fail CI if removed prematurely or kept past the rewrite.

**Why no browser smoke task:** Pure backend refactor with no UI surface. The middleware behavior is verified end-to-end by `KnowledgeBaseTest::test_create_blocked_when_knowledge_items_limit_reached` (Pest feature test) — that's the appropriate level. Browser smoke would only add interactive UI confirmation, which doesn't exist here.

**Plan size note:** Cluster D is intentionally tiny — 2 files changed, ~15 lines net diff in production code, 1 new test file. The smallest of the four clusters. The bulk of the value is the architectural clarification ("UsageTracker owns the can-I-use-more decision") not lines of code.
