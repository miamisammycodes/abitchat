# Usage Tracking Cache — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close three medium-severity findings around the usage-cache layer — missed invalidations on non-token creation paths (M-NEW-4), month-boundary cache bleed via a period-blind key (M-NEW-5), and slow non-sargable `whereYear`/`whereMonth` scans on `countByPeriod` (M-NEW-6).

**Architecture:** Centralize cache invalidation via Eloquent `created` observers on `Conversation`, `Lead`, and `KnowledgeItem`, mirroring the existing pattern from `Tenant::booted` that busts `tenant:{id}:with_plan`. Partition the cache key by period (`tenant:{id}:usage:{YYYY-MM}`) so last month's bucket can't leak into the first 60s of the new month. Rewrite `countByPeriod` to use `whereBetween('created_at', [$start, $end])` so the existing `(tenant_id, created_at)` indexes are usable.

**Tech Stack:** Laravel 13+, PHP 8.3+, Pest/PHPUnit, MySQL 8 in prod, SQLite in tests. The `usage_records` table already has the `period` column from the May 3 restructure migration. `Tests\TestCase` uses `RefreshDatabase`; tests pass `trial_ends_at` so `check.limits` middleware lets them through.

**Spec reference:** `docs/superpowers/specs/2026-05-12-medium-backlog-design.md` — Cluster 2.

**Order rationale:** M-NEW-5 first (cache key change is the unblocker — model observers in M-NEW-4 must use the new key; refactor the key once). M-NEW-6 second (independent, mechanical). M-NEW-4 last (depends on the cache key being settled).

---

## Task 0: Verification pass against current `main`

- [ ] **Step 1: Verify M-NEW-4 — non-token creation paths don't bust the cache**

Run:
```bash
grep -rn "Cache::forget.*usage\|tenant:.*:usage" --include='*.php' app/ 2>/dev/null
```
Expected: hits only in `app/Http/Controllers/Api/V1/Widget/ChatController.php` (4 sites) and `app/Services/Usage/UsageTracker.php` (the private `forgetCache`). **Crucially absent** from `app/Http/Controllers/Api/V1/Widget/LeadController.php` (which creates leads at line 90) and from `app/Http/Controllers/Client/KnowledgeBaseController.php` (which creates knowledge items at line 81).

- [ ] **Step 2: Verify M-NEW-5 — cache key omits period**

Run:
```bash
grep -n "tenant:{$tenant->id}:usage\|cacheKey" app/Services/Usage/UsageTracker.php
```
Expected: `cacheKey()` at line 154–157 returns `"tenant:{$tenant->id}:usage"` with no period segment.

- [ ] **Step 3: Verify M-NEW-6 — non-sargable countByPeriod**

Run:
```bash
grep -n "whereYear\|whereMonth\|countByPeriod" app/Services/Usage/UsageTracker.php
```
Expected: `countByPeriod` at line 140–147 uses `whereYear('created_at', ...)` and `whereMonth('created_at', ...)`.

- [ ] **Step 4: Proceed to Task 1**

No commit. All three findings live.

---

## Task 1: M-NEW-5 — Period-scoped cache key

**Goal:** Append `:{YYYY-MM}` to the usage cache key so the cached value cannot bleed across a month boundary. The 60s TTL alone is insufficient — a tenant who hits their cap on May 31 23:59:30 has a cached "at limit" state that, on June 1 at 00:00:00, may briefly block API calls that should now be allowed under the new month's fresh quota (or vice versa, with stale "under limit" state from May briefly allowing past-quota calls on June 1).

**Files:**
- Modify: `app/Services/Usage/UsageTracker.php` (the `cacheKey` method, lines 154–157)
- Test: `tests/Unit/Services/Usage/UsageTrackerTest.php` (add one method)

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Services/Usage/UsageTrackerTest.php` inside the existing class:

```php
    public function test_monthly_usage_does_not_bleed_across_month_boundary(): void
    {
        Carbon::setTestNow('2026-05-31 23:59:30');
        $this->tracker->recordTokens($this->tenant, null, 50, 50, 100);
        $this->assertSame(100, $this->tracker->monthlyUsage($this->tenant)['tokens']);

        // Roll into June. The May cache must NOT be returned for June.
        Carbon::setTestNow('2026-06-01 00:00:30');
        $this->assertSame(
            0,
            $this->tracker->monthlyUsage($this->tenant)['tokens'],
            'June must start with zero usage; May cached value must not bleed across the period boundary.'
        );
        Carbon::setTestNow();
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_monthly_usage_does_not_bleed_across_month_boundary`
Expected: FAIL — `monthlyUsage` returns 100 in June because the period-blind cache key returned the May-cached value.

- [ ] **Step 3: Partition the cache key by period**

In `app/Services/Usage/UsageTracker.php`, replace the existing `cacheKey` method (lines 154–157):

```php
    private function cacheKey(Tenant $tenant): string
    {
        return "tenant:{$tenant->id}:usage:" . self::currentPeriod();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_monthly_usage_does_not_bleed_across_month_boundary`
Expected: PASS — June reads a different key, gets a cache miss, populates with the June period query, returns 0.

- [ ] **Step 5: Run the full UsageTracker suite**

Run: `php artisan test tests/Unit/Services/Usage/UsageTrackerTest.php`
Expected: all green. The existing `test_record_tokens_busts_monthly_usage_cache` continues passing because `forgetCache()` calls `cacheKey()` — same key, same period, same behavior.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Usage/UsageTracker.php tests/Unit/Services/Usage/UsageTrackerTest.php
git commit -m "$(cat <<'EOF'
fix(usage): partition usage cache by period to stop month-boundary bleed (M-NEW-5)

Cache key gains a ':{YYYY-MM}' suffix so May's totals cannot survive
into the first 60s of June. The TTL alone was insufficient at the
boundary — tenants hit the new month with stale "at limit" or
"under limit" state.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: all green. If any pre-existing test asserted the literal cache key string `tenant:N:usage`, update it — the new format includes the period.

---

## Task 2: M-NEW-6 — Sargable `countByPeriod`

**Goal:** Replace `whereYear`/`whereMonth` (which wrap `created_at` in functions and disable the index) with a date-range `whereBetween` so MySQL can use the `(tenant_id, created_at)` and `(tenant_id, status)` indexes.

**Files:**
- Modify: `app/Services/Usage/UsageTracker.php` (the `countByPeriod` method, lines 140–147)
- Test: `tests/Unit/Services/Usage/UsageTrackerTest.php` (add two methods)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Services/Usage/UsageTrackerTest.php` inside the existing class:

```php
    public function test_count_by_period_includes_first_second_of_month(): void
    {
        Carbon::setTestNow('2026-05-01 00:00:00');
        Conversation::create([
            'tenant_id' => $this->tenant->id,
            'visitor_id' => 'edge-start',
            'session_id' => 'sess-edge-start',
            'started_at' => now(),
        ]);
        Carbon::setTestNow();

        $this->assertSame(
            1,
            $this->tracker->usageInPeriod($this->tenant, 'conversations', '2026-05'),
            'Conversations at the first second of the month must be included.'
        );
    }

    public function test_count_by_period_excludes_first_second_of_next_month(): void
    {
        Carbon::setTestNow('2026-06-01 00:00:00');
        Conversation::create([
            'tenant_id' => $this->tenant->id,
            'visitor_id' => 'edge-next',
            'session_id' => 'sess-edge-next',
            'started_at' => now(),
        ]);
        Carbon::setTestNow();

        $this->assertSame(
            0,
            $this->tracker->usageInPeriod($this->tenant, 'conversations', '2026-05'),
            'Conversations at 2026-06-01 00:00:00 must NOT be in the May 2026 bucket.'
        );
    }
```

- [ ] **Step 2: Run tests to verify behavior**

Run: `php artisan test --filter="test_count_by_period_includes_first_second_of_month|test_count_by_period_excludes_first_second_of_next_month"`
Expected: BOTH PASS today — `whereYear`/`whereMonth` are correct, just slow. These are regression guards for the refactor. (This is the same intent as `test_conversation_count_is_year_aware`, which guards the *year* edge.)

- [ ] **Step 3: Rewrite `countByPeriod` with a range query**

In `app/Services/Usage/UsageTracker.php`, replace the existing `countByPeriod` method (lines 139–147):

```php
    /** @param HasMany<\Illuminate\Database\Eloquent\Model, Tenant> $relation */
    private function countByPeriod(HasMany $relation, string $period): int
    {
        [$year, $month] = explode('-', $period);
        $start = \Carbon\Carbon::create((int) $year, (int) $month, 1, 0, 0, 0);
        $end = $start->copy()->addMonth();

        return $relation
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->count();
    }
```

Half-open interval `[start, nextMonthStart)` means the first instant of the next month is excluded; the first instant of the current month is included. Matches the previous `whereYear`/`whereMonth` semantics exactly.

- [ ] **Step 4: Run tests to verify they still pass**

Run: `php artisan test --filter="test_count_by_period|test_conversation_count_is_year_aware"`
Expected: all green — the two new edge tests, plus the pre-existing year-aware regression.

- [ ] **Step 5: Run the full UsageTracker suite**

Run: `php artisan test tests/Unit/Services/Usage/UsageTrackerTest.php`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Usage/UsageTracker.php tests/Unit/Services/Usage/UsageTrackerTest.php
git commit -m "$(cat <<'EOF'
perf(usage): make countByPeriod sargable via date-range whereBetween (M-NEW-6)

whereYear/whereMonth wrap created_at in functions and prevent the
(tenant_id, created_at) indexes from being used. Switch to a half-open
range [periodStart, nextMonthStart) so the planner can index-scan on
tenants with millions of conversation/lead rows. Edge-of-month tests
guard the inclusive/exclusive bounds.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: all green.

---

## Task 3: M-NEW-4 — Centralize cache invalidation via model observers

**Goal:** Replace the scattered manual `Cache::forget("tenant:{$id}:usage")` calls in `ChatController` with Eloquent `created` observers on `Conversation`, `Lead`, and `KnowledgeItem`. Any future caller that creates one of these via Eloquent (controller, service, job, console command) automatically busts the cache — the responsibility lives with the model, not the caller.

**Files:**
- Modify: `app/Services/Usage/UsageTracker.php` (make `forgetCache` public)
- Modify: `app/Models/Conversation.php` (add `booted` hook)
- Modify: `app/Models/Lead.php` (add `booted` hook)
- Modify: `app/Models/KnowledgeItem.php` (add `booted` hook)
- Modify: `app/Http/Controllers/Api/V1/Widget/ChatController.php` (remove redundant manual busts at lines 88, 366; route the defensive partial-write busts at 168, 267 through `UsageTracker::forgetCache`)
- Test: `tests/Unit/Services/Usage/UsageTrackerTest.php` (add three methods)

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Services/Usage/UsageTrackerTest.php` inside the existing class. Add the required imports near the top if missing — `App\Models\Lead`, `App\Models\KnowledgeItem`.

```php
    public function test_creating_a_conversation_busts_the_usage_cache(): void
    {
        $this->assertSame(0, $this->tracker->monthlyUsage($this->tenant)['conversations']);

        Conversation::create([
            'tenant_id' => $this->tenant->id,
            'visitor_id' => 'v-cache',
            'session_id' => 'sess-cache',
            'started_at' => now(),
        ]);

        $this->assertSame(
            1,
            $this->tracker->monthlyUsage($this->tenant)['conversations'],
            'Creating a Conversation must bust the usage cache; the next read should see 1, not the cached 0.'
        );
    }

    public function test_creating_a_lead_busts_the_usage_cache(): void
    {
        $this->assertSame(0, $this->tracker->monthlyUsage($this->tenant)['leads']);

        \App\Models\Lead::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Cache Buster',
            'email' => 'buster@example.com',
            'status' => 'new',
            'score' => 0,
        ]);

        $this->assertSame(
            1,
            $this->tracker->monthlyUsage($this->tenant)['leads'],
            'Creating a Lead must bust the usage cache.'
        );
    }

    public function test_creating_a_knowledge_item_busts_the_usage_cache(): void
    {
        $this->assertSame(0, $this->tracker->monthlyUsage($this->tenant)['knowledge_items']);

        \App\Models\KnowledgeItem::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'text',
            'title' => 'Cache Buster',
            'content' => 'x',
            'status' => 'ready',
        ]);

        $this->assertSame(
            1,
            $this->tracker->monthlyUsage($this->tenant)['knowledge_items'],
            'Creating a KnowledgeItem must bust the usage cache.'
        );
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="test_creating_a_conversation_busts_the_usage_cache|test_creating_a_lead_busts_the_usage_cache|test_creating_a_knowledge_item_busts_the_usage_cache"`
Expected: all three FAIL — the second `monthlyUsage` call reads the cached `0` because no observer busted the cache. If any test errors on a fillable mismatch (e.g., `score` not in `Lead`'s fillable, or `status` not in `KnowledgeItem`'s), inspect the model and adjust the test payload to match — DO NOT modify the model's fillable just to make the test instantiate. The point of the test is the cache bust, not the field set.

- [ ] **Step 3: Make `UsageTracker::forgetCache` public**

In `app/Services/Usage/UsageTracker.php`, change the visibility on line 149:

```php
    public function forgetCache(Tenant $tenant): void
    {
        Cache::forget($this->cacheKey($tenant));
    }
```

- [ ] **Step 4: Add the `created` observer on Conversation**

In `app/Models/Conversation.php`, add (or extend) the `booted` method. If a `booted` method already exists, add the `static::created` call inside it. If not, add the full method. Also add the required imports at the top of the file — `App\Services\Usage\UsageTracker` and `Illuminate\Support\Facades\Cache` (if not present).

```php
    protected static function booted(): void
    {
        static::created(function (Conversation $conversation) {
            if ($conversation->tenant) {
                app(\App\Services\Usage\UsageTracker::class)->forgetCache($conversation->tenant);
            }
        });
    }
```

- [ ] **Step 5: Add the `created` observer on Lead**

In `app/Models/Lead.php`, add the same pattern. Note: in Plan 1 (cluster 1), `LeadService::captureFromConversation` was wrapped in `DB::transaction`. The `created` event fires *before* the outer commit, so the cache bust happens pre-commit. A reader who hits `monthlyUsage` between the bust and the commit will repopulate from a still-uncommitted row count — i.e., they see the cache populated with the count *as of the read*, which is stable; the next read after commit gets the updated row. Worst case is a brief 60s window where one cache miss returns one stale count. Acceptable for billing.

```php
    protected static function booted(): void
    {
        static::created(function (Lead $lead) {
            if ($lead->tenant) {
                app(\App\Services\Usage\UsageTracker::class)->forgetCache($lead->tenant);
            }
        });
    }
```

If `booted` already exists, fold the `static::created` call into the existing method.

- [ ] **Step 6: Add the `created` observer on KnowledgeItem**

In `app/Models/KnowledgeItem.php`, add the same pattern:

```php
    protected static function booted(): void
    {
        static::created(function (KnowledgeItem $item) {
            if ($item->tenant) {
                app(\App\Services\Usage\UsageTracker::class)->forgetCache($item->tenant);
            }
        });
    }
```

If `booted` already exists, fold the `static::created` call into the existing method.

- [ ] **Step 7: Run the three new tests to verify they pass**

Run: `php artisan test --filter="test_creating_a_conversation_busts_the_usage_cache|test_creating_a_lead_busts_the_usage_cache|test_creating_a_knowledge_item_busts_the_usage_cache"`
Expected: all three PASS.

- [ ] **Step 8: Remove redundant manual cache busts in ChatController and route defensive ones through UsageTracker**

In `app/Http/Controllers/Api/V1/Widget/ChatController.php`:

1. Delete the manual bust at line 88 (after creating a conversation in `startConversation`). The observer on `Conversation` now handles it.

2. Delete the manual bust at line 366 (after `LeadService::captureFromConversation` returns a non-null lead). The observer on `Lead` now handles it. If a `Lead::find/update` path runs instead of `Lead::create`, the observer still fires for any new lead created; updates don't change usage counts.

3. Route the partial-write defensive busts at lines 168 and 267 through `UsageTracker::forgetCache` instead of hardcoded `Cache::forget`, so they pick up the period-aware key from Task 1. Inject `UsageTracker` via the constructor if not already.

Inspect `ChatController`'s constructor first:
```bash
grep -n "__construct\|UsageTracker\|LeadService" app/Http/Controllers/Api/V1/Widget/ChatController.php | head -10
```

If `UsageTracker` is not already constructor-injected, add it. Otherwise reference the existing property.

The two defensive sites at 168 and 267 become (showing line 168 as the example; line 267 follows the same pattern):

```php
                // generateResponse may have written partial UsageRecord rows
                // before throwing — bust the cache regardless of outcome.
                $this->usageTracker->forgetCache($tenant);
```

If `Cache` and the literal-string bust were the only reason `Illuminate\Support\Facades\Cache` was imported into this file, drop the import too. Check with:
```bash
grep -n "Cache::" app/Http/Controllers/Api/V1/Widget/ChatController.php
```

- [ ] **Step 9: Run the wider widget + usage suites**

Run: `php artisan test tests/Unit/Services/Usage/UsageTrackerTest.php tests/Feature/Widget tests/Feature/WidgetApiTest.php tests/Feature/WidgetLeadCaptureTest.php`
Expected: all green.

- [ ] **Step 10: Commit**

```bash
git add app/Services/Usage/UsageTracker.php \
        app/Models/Conversation.php app/Models/Lead.php app/Models/KnowledgeItem.php \
        app/Http/Controllers/Api/V1/Widget/ChatController.php \
        tests/Unit/Services/Usage/UsageTrackerTest.php
git commit -m "$(cat <<'EOF'
fix(usage): centralize cache invalidation via model observers (M-NEW-4)

Conversation, Lead, and KnowledgeItem now bust the tenant usage cache
from their static::created hook. Manual Cache::forget calls in
ChatController are removed where the observer covers them; the
remaining defensive busts on the partial-write error path now route
through UsageTracker::forgetCache so they pick up the period-aware
cache key from M-NEW-5.

Coverage gained: Lead creation via WidgetLeadController and
KnowledgeItem creation via KnowledgeBaseController, both of which
previously left stale cache values for up to 60s after a tenant
hit their cap.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 11: Run the full suite**

Run: `php artisan test`
Expected: all green.

---

## Task 4: Browser smoke, simplify, PR

- [ ] **Step 1: Boot the dev environment**

```bash
php artisan serve --port=8001
npm run dev
```

- [ ] **Step 2: Browser smoke — usage cache invalidation on knowledge upload**

1. Log in as `test@example.com` / `password`.
2. Navigate to `/billing` — note the "Knowledge Items: X / Y" usage row.
3. Navigate to `/knowledge`, create a new knowledge item (text type, small content).
4. Return to `/billing` — refresh. The usage row should show `X+1`, not `X`.

Verify:
```bash
php artisan tinker --execute="echo app(\App\Services\Usage\UsageTracker::class)->monthlyUsage(\App\Models\Tenant::where('slug','test-company')->first())['knowledge_items'];"
```

- [ ] **Step 3: Browser smoke — usage cache invalidation on lead capture**

1. Open `/widget/test.html`, send a message containing an email address (e.g., "test me at user@example.com").
2. As the client, navigate to `/billing` and confirm the "Leads" usage row incremented.

- [ ] **Step 4: Browser smoke — month boundary (simulated)**

Pure backend; no UI surface. Skip and rely on `test_monthly_usage_does_not_bleed_across_month_boundary`.

- [ ] **Step 5: Pint pass 1**

Run `./vendor/bin/pint --test` on the PR-touched files:
```bash
./vendor/bin/pint --test app/Services/Usage/UsageTracker.php \
  app/Models/Conversation.php app/Models/Lead.php app/Models/KnowledgeItem.php \
  app/Http/Controllers/Api/V1/Widget/ChatController.php \
  tests/Unit/Services/Usage/UsageTrackerTest.php
```
If anything is flagged, drop `--test` to apply fixes, run `php artisan test` to confirm green, then commit as `style(pint): apply auto-fixes to cluster-2 files`. Scope to these files only — don't sweep unrelated pre-existing style debt.

- [ ] **Step 6: `/simplify` pass 1**

Run `/simplify`. Apply substantive fixes. Skip stylistic noise with a one-line reason.

- [ ] **Step 7: Pint pass 2**

`/simplify` may have introduced new code that needs style normalization. Repeat the Pint cycle from Step 5.

- [ ] **Step 8: `/simplify` pass 2**

Run `/simplify` again. Address any newly-introduced issues. Run:
```bash
php artisan test
```
Expected: all green.

- [ ] **Step 9: Open the PR**

Confirm one last time:
```bash
./vendor/bin/pint --test app/Services/Usage/UsageTracker.php app/Models/Conversation.php app/Models/Lead.php app/Models/KnowledgeItem.php app/Http/Controllers/Api/V1/Widget/ChatController.php tests/Unit/Services/Usage/UsageTrackerTest.php
php artisan test
```
Both must be clean before pushing.

```bash
git push -u origin HEAD
gh pr create --title "fix(usage): close cluster-2 cache invalidation & query findings" --body "$(cat <<'EOF'
## Summary

Cluster 2 of the medium-backlog spec — usage tracking cache.

- **M-NEW-5** — Cache key gains a `:{YYYY-MM}` suffix so May's totals can't bleed into the first 60s of June.
- **M-NEW-6** — `countByPeriod` rewritten as a half-open `whereBetween` range so the `(tenant_id, created_at)` indexes are usable. Edge-of-month tests guard the inclusive/exclusive bounds.
- **M-NEW-4** — Eloquent `created` observers on `Conversation`, `Lead`, `KnowledgeItem` centralize cache invalidation. Scattered manual `Cache::forget` calls in `ChatController` removed where covered; defensive partial-write busts route through `UsageTracker::forgetCache` so they pick up the new period-aware key. Coverage gained: `WidgetLeadController` lead creates and `KnowledgeBaseController` knowledge-item creates, which previously did not bust the cache.

## Deploy steps

1. Merge.
2. No migrations.
3. Cache key changed (`:YYYY-MM` suffix). Existing `tenant:N:usage` entries in Redis become orphans and expire in their original 60s TTL — no manual flush needed.

## ⚠️ Behavior changes

| Change | Who's affected | Mitigation |
|---|---|---|
| Usage cache key gains `:YYYY-MM` suffix | None — existing entries orphan and expire in 60s | None needed |
| `countByPeriod` now uses a date range | None — semantics preserved (half-open `[start, end)`) | None needed |
| Model events fire `UsageTracker::forgetCache` on Conversation/Lead/KnowledgeItem creation | Test fixtures that create thousands of these in a tight loop will see modest extra cache traffic | None — `Cache::forget` is cheap |

## Test plan

- [ ] `php artisan test` — full suite green
- [ ] Browser smoke: create knowledge item → `/billing` usage row increments
- [ ] Browser smoke: widget message with email → `/billing` leads row increments
- [ ] Unit test `test_monthly_usage_does_not_bleed_across_month_boundary` passes (covers the May→June boundary)

## Architecture notes

Centralization mirrors the existing pattern in `Tenant::booted` that busts `tenant:{id}:with_plan`. Model events fire after the row exists in DB but before the transaction commits — the cache repopulates on the next read with the new row visible.

## Links

- Spec: `docs/superpowers/specs/2026-05-12-medium-backlog-design.md` (Cluster 2)
- Plan: `docs/superpowers/plans/2026-05-13-usage-cache.md`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 10: Update memory after merge**

Save a memory entry capturing:
- Cluster 2 of medium-backlog closed (M-NEW-4/5/6).
- The cache key is now period-scoped — any new code that touches `tenant:N:usage` must use `UsageTracker::forgetCache` or compose the key with the current period.
- Plan 3 (rate limiting + CORS) ready to execute against fresh `main`.

---

## Out of scope

- Refactoring `UsageRecord` writes to use observers instead of `UsageRecord::create` — those go through `UsageTracker::recordTokens` which already handles cache invalidation. Defer.
- Adding observers on `update`/`delete` events. Usage counts are monotonic-increasing within a period; deletes are operational outliers and the 60s TTL absorbs them.
- Backfilling indexes on `conversations.created_at` or `leads.created_at`. Both already have composite indexes from the original migrations (`tenant_id, status` etc.); the planner picks them up once the predicate is sargable. Re-evaluate via `EXPLAIN` in prod after deploy if needed.
