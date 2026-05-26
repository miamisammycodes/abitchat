# Re-crawl Feedback Follow-up Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close all 6 findings from the PR review of commit `22d962a` so the Re-crawl flow has comprehensive feedback for every failure mode (validation, network, auth, server, queue) and is regression-locked by tests.

**Architecture:** Five small, independent commits. Two harden the recrawl flow (controller try/catch + JS network-error catch); one closes a regression-prevention gap in tests; two are minor UX polish (stale-error clearing + self-nav link). All scoped to existing files, no new files, no migrations.

**Tech Stack:** Laravel 13 + Inertia.js 2.x + Vue 3 Composition API + Pest tests.

---

## File Structure

**Modified (5 files, no new files):**
- `app/Http/Controllers/Client/WebsiteIndexingController.php` — Task 2 (wrap `CrawlWebsiteJob::dispatch()` in try/catch)
- `resources/js/Pages/Client/Widget/Index.vue` — Tasks 1 + 4 (network-error catch on `router.post`; clear `recrawlError` in `saveIndexing()`)
- `resources/js/Components/IndexingStatusBanner.vue` — Task 5 (hide self-nav Retry on widget-settings)
- `tests/Feature/Client/WebsiteIndexingControllerTest.php` — Task 3 (assert flash-success keys; add Task 2 regression test)
- `resources/js/Pages/Client/KnowledgeBase/Index.vue` — no change (already covered by Task 5 in IndexingStatusBanner itself)

---

## Task 1: Catch network failures via Inertia exception event

**Why:** Inertia's `onError` callback only fires for HTTP 422 validation responses. Network failures (offline, server unreachable, axios timeout) emit Inertia's `exception` event but don't trigger `onError`. Without explicit handling, `recrawlError` stays null, `onFinish` fires (spinner clears), and the user sees the button snap back with zero feedback — the same silent-failure pattern commit `22d962a` set out to kill.

**Verified facts about `@inertiajs/vue3 v2.3.18` (this project's exact version):**
- `router.post()` calls `visit()` which **returns `undefined`** (not a Promise). `.catch()` on it would throw `TypeError`.
- `inertia:exception` event IS fired for axios errors at `node_modules/@inertiajs/core/dist/index.esm.js:2242`.
- `router.on('exception', callback)` returns a removal function for cleanup.
- There is **no `onException` per-request callback** in v2.x — only the global event.

**Decision rationale:**
- 500/419 are handled by Inertia's built-in error modal (ugly but visible) — accept, out of scope.
- 403 stale-permission case: button is `v-if`-gated on `manage_tenant_settings`, so very rare. The generic message covers if it slips through.
- Network failure: use page-scoped `router.on('exception', ...)` listener gated on `recrawling.value` to surface inline feedback exactly when a recrawl is in flight.

**Files:**
- Modify: `resources/js/Pages/Client/Widget/Index.vue` (imports + setup block)

- [ ] **Step 1: Read the current setup block to confirm shape**

Run: `sed -n '1,5p;101,118p' resources/js/Pages/Client/Widget/Index.vue`

Expected: imports include `ref` and `router` from `@inertiajs/vue3`; `recrawling`/`recrawlError` refs exist; `recrawlNow()` defined with no `.catch()`.

- [ ] **Step 2: Add `onMounted` / `onBeforeUnmount` to the Vue import**

Find line 2 (or wherever the `vue` import is):

```js
import { ref, computed } from 'vue'
```

Replace with:

```js
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
```

- [ ] **Step 3: Register a page-scoped exception listener and add `errors.queue` to the error chain**

Replace the entire block starting at `const recrawling = ref(false)` (around line 105) through the closing `}` of `recrawlNow()` with:

```js
const recrawling = ref(false)
const recrawlError = ref(null)
let removeExceptionListener = null

onMounted(() => {
  // Inertia fires `exception` for axios-level failures (network, timeout).
  // onError below covers 422 validation; this covers the network gap.
  removeExceptionListener = router.on('exception', (event) => {
    if (recrawling.value) {
      recrawlError.value = 'Could not contact the server. Check your connection and try again.'
      event.preventDefault()
    }
  })
})

onBeforeUnmount(() => {
  if (removeExceptionListener) {
    removeExceptionListener()
    removeExceptionListener = null
  }
})

function recrawlNow() {
  recrawlError.value = null
  router.post(route('widget.indexing.recrawl'), {}, {
    preserveScroll: true,
    onStart: () => { recrawling.value = true },
    onFinish: () => { recrawling.value = false },
    onError: (errors) => {
      recrawlError.value = errors.cooldown || errors.website_url || errors.queue || 'Re-crawl failed. Please try again.'
    },
  })
}
```

Changes vs. main:
- New `removeExceptionListener` local
- New `onMounted` registers `router.on('exception', ...)` gated on `recrawling.value`
- New `onBeforeUnmount` cleans up the listener (critical — global listeners leak across SPA nav otherwise)
- `errors.queue` added to the precedence chain (Task 2 will introduce that key server-side)

- [ ] **Step 4: Rebuild assets**

Run: `npm run build`
Expected: `✓ built in <2s` with no errors.

- [ ] **Step 5: Smoke-test in browser**

1. Open `http://chatbot.test/widget-settings`
2. Open Chrome DevTools → Network tab → set throttling to "Offline"
3. Click "Re-crawl now"
4. Expect inline red text appears: `"Could not contact the server. Check your connection and try again."`
5. Expect button text returns to "Re-crawl now" (not stuck on "Queuing…") — confirms `onFinish` still fires
6. Toggle Network back to "Online", reload page, click Re-crawl again — works normally (no stale listener)

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Client/Widget/Index.vue
git commit -m "$(cat <<'EOF'
fix(widget): show error when recrawl fails network-side

Inertia v2.3 onError only fires for HTTP 422. Network failures fire
the `inertia:exception` event but no callback — leaving recrawlError
null, onFinish firing, button snapping back with zero feedback.
Same silent-failure pattern PR 22d962a was meant to kill, different
error class.

Register a page-scoped router.on('exception') listener gated on
recrawling.value so the inline feedback only triggers when a recrawl
is in flight. Cleanup in onBeforeUnmount to avoid SPA-nav leaks.

Also add `errors.queue` to the precedence chain in anticipation of
the upcoming queue-failure try/catch in the controller.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Wrap CrawlWebsiteJob::dispatch() in try/catch

**Why:** Pre-existing silent failure. If the queue store is unreachable (Redis down, DB queue table locked), `CrawlWebsiteJob::dispatch()` throws. The exception bubbles to Laravel's exception handler → 500 response → Inertia's debug modal. Convert this into a graceful validation-style error keyed `queue` (Task 1's handler already reads `errors.queue`).

**Files:**
- Modify: `app/Http/Controllers/Client/WebsiteIndexingController.php:60`
- Test: `tests/Feature/Client/WebsiteIndexingControllerTest.php` (add one test)

- [ ] **Step 1: Write the failing test**

Add this test method to `tests/Feature/Client/WebsiteIndexingControllerTest.php` right after `test_manual_recrawl_dispatches_job`:

```php
public function test_manual_recrawl_returns_queue_error_when_dispatch_fails(): void
{
    $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $tenant->id]);

    // Swap the Bus with a Mockery mock that throws on dispatch — simulates
    // queue store unreachable (Redis down, DB queue table locked, etc.).
    // Do NOT pair this with Bus::fake() — fake() installs BusFake, and
    // shouldReceive on a swapped facade behaves inconsistently.
    \Illuminate\Support\Facades\Bus::shouldReceive('dispatch')
        ->once()
        ->andThrow(new \RuntimeException('Queue store unreachable'));

    $response = $this->actingAs($user)->post('/widget-settings/website-indexing/recrawl');

    $response->assertSessionHasErrors('queue');
    $response->assertSessionDoesntHaveErrors(['cooldown', 'website_url']);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=test_manual_recrawl_returns_queue_error_when_dispatch_fails`
Expected: FAIL with `RuntimeException: Queue store unreachable` bubbling up — proving the controller has no try/catch around `CrawlWebsiteJob::dispatch()`.

If the test PASSES instead (no exception bubbled), the mock isn't intercepting the right call site. `CrawlWebsiteJob::dispatch()` returns `PendingDispatch` which destructs synchronously and calls `Bus::dispatch()`. If the mock isn't being hit, fall back to:

```php
$this->instance(
    \Illuminate\Contracts\Bus\Dispatcher::class,
    \Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class)
        ->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue store unreachable'))
        ->getMock()
);
```

— this binds the mock directly to the container interface so `PendingDispatch::commit()` hits it.

- [ ] **Step 3: Wrap dispatch in try/catch**

In `app/Http/Controllers/Client/WebsiteIndexingController.php`, locate this block (around line 60):

```php
        CrawlWebsiteJob::dispatch($tenant, CrawlMode::Manual);

        return back()->with('success', 'Re-crawl queued.');
```

Replace with:

```php
        try {
            CrawlWebsiteJob::dispatch($tenant, CrawlMode::Manual);
        } catch (\Throwable $e) {
            \Log::error('[Crawl] (NO $) Queue dispatch failed', [
                'tenant_id' => $tenant->id,
                'exception' => $e->getMessage(),
            ]);

            return back()->withErrors(['queue' => 'Could not queue the re-crawl right now. Please try again in a moment.']);
        }

        return back()->with('success', 'Re-crawl queued.');
```

Note: the `\Log::error` follows CLAUDE.md's `(NO $)` convention (local operation, no external $ cost).

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=test_manual_recrawl_returns_queue_error_when_dispatch_fails`
Expected: PASS.

- [ ] **Step 5: Run the full WebsiteIndexing suite to verify no regressions**

Run: `php artisan test --filter=WebsiteIndexing`
Expected: 14 tests pass (13 existing + 1 new).

- [ ] **Step 6: Pint check**

Run: `./vendor/bin/pint --test app/Http/Controllers/Client/WebsiteIndexingController.php`
Expected: `{"result":"pass"}`.

If failed: run `./vendor/bin/pint app/Http/Controllers/Client/WebsiteIndexingController.php` to fix, then re-run tests.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Client/WebsiteIndexingController.php tests/Feature/Client/WebsiteIndexingControllerTest.php
git commit -m "$(cat <<'EOF'
fix(widget): convert queue-dispatch failure into recoverable error

CrawlWebsiteJob::dispatch() was fire-and-forget. If the queue store
is unreachable (Redis down, DB queue table locked), the exception
bubbled to a 500. Now wrap in try/catch and return an `errors.queue`
validation-style response so the Vue handler can surface a graceful
"try again" message.

The recrawlNow() handler already reads errors.queue (added in the
prior commit).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Lock the flash-key contract with assertions

**Why:** Existing controller tests assert dispatch + no-errors but never check the flash key. A future revert from `'success'` back to `'status'` would silently re-break the user-visible feedback (the original `22d962a` bug) and ship green. Add one-line `assertSessionHas('success', ...)` to the two existing tests that exercise the success paths. Pattern already used in `tests/Feature/Admin/ApproveInactivePlanTest.php:93` and `tests/Feature/Client/ActivateTrialErrorsTest.php:55`.

**Files:**
- Modify: `tests/Feature/Client/WebsiteIndexingControllerTest.php:23-50` and `91-107`

- [ ] **Step 1: Read the two test methods to confirm shape**

Run: `sed -n '20,55p' tests/Feature/Client/WebsiteIndexingControllerTest.php`
Run: `sed -n '90,110p' tests/Feature/Client/WebsiteIndexingControllerTest.php`

Confirm these test methods exist: `test_update_changes_website_url` (around line 22) and `test_manual_recrawl_allowed_after_cooldown` (around line 91).

Note: `test_manual_recrawl_dispatches_job` (around line 39) is also a success path — assert it there too.

- [ ] **Step 2: Add flash-key assertion to test_update_changes_website_url**

In `tests/Feature/Client/WebsiteIndexingControllerTest.php`, locate the block:

```php
        $response->assertSessionHasNoErrors();
        $this->assertSame('https://newsite.com', $tenant->fresh()->website_url);
        $this->assertTrue($tenant->fresh()->auto_recrawl);
    }
```

Replace with:

```php
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', 'Website indexing settings saved.');
        $this->assertSame('https://newsite.com', $tenant->fresh()->website_url);
        $this->assertTrue($tenant->fresh()->auto_recrawl);
    }
```

- [ ] **Step 3: Add flash-key assertion to test_manual_recrawl_dispatches_job**

In the same file, locate:

```php
        $response->assertSessionHasNoErrors();
        Bus::assertDispatched(CrawlWebsiteJob::class, fn (CrawlWebsiteJob $job) => $job->mode === CrawlMode::Manual);
    }
```

Replace with:

```php
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', 'Re-crawl queued.');
        Bus::assertDispatched(CrawlWebsiteJob::class, fn (CrawlWebsiteJob $job) => $job->mode === CrawlMode::Manual);
    }
```

- [ ] **Step 4: Add flash-key assertion to test_manual_recrawl_allowed_after_cooldown**

In the same file, locate the assertion section of `test_manual_recrawl_allowed_after_cooldown` (around line 105):

```php
        $response->assertSessionHasNoErrors();
```

Replace with:

```php
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', 'Re-crawl queued.');
```

- [ ] **Step 5: Run the full suite to verify all pass**

Run: `php artisan test --filter=WebsiteIndexing`
Expected: 14 tests pass.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/Client/WebsiteIndexingControllerTest.php
git commit -m "$(cat <<'EOF'
test(widget): lock flash-success keys to prevent silent regressions

PR 22d962a fixed a silent-failure bug caused by a flash-key mismatch
('status' vs 'success'). Existing tests only asserted no-errors and
dispatch, so a future revert would have passed silently.

Add assertSessionHas('success', ...) to the three success-path tests
to make the flash-key contract explicit and regression-proof.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Clear recrawlError when user clicks Save

**Why:** After a cooldown error displays under the buttons, if the user clicks the **Save** button (the other button in the same form), the stale "Please wait — your last crawl…" message lingers — confusing because Save has nothing to do with re-crawling. Clear it at the start of `saveIndexing()` to match the symmetry of `recrawlNow()`.

**Files:**
- Modify: `resources/js/Pages/Client/Widget/Index.vue:101-103`

- [ ] **Step 1: Locate saveIndexing**

Run: `grep -n "function saveIndexing" resources/js/Pages/Client/Widget/Index.vue`
Expected: one match around line 101.

- [ ] **Step 2: Add the clear**

Find this block in `resources/js/Pages/Client/Widget/Index.vue`:

```js
function saveIndexing() {
  indexingForm.patch(route('widget.indexing.update'), { preserveScroll: true })
}
```

Replace with:

```js
function saveIndexing() {
  recrawlError.value = null
  indexingForm.patch(route('widget.indexing.update'), { preserveScroll: true })
}
```

- [ ] **Step 3: Rebuild assets**

Run: `npm run build`
Expected: clean build.

- [ ] **Step 4: Smoke test**

1. Open `http://chatbot.test/widget-settings`
2. Click "Re-crawl now" while in cooldown → see red error appear
3. Click "Save" → verify red error disappears (Save's own success flash takes over)

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Client/Widget/Index.vue
git commit -m "$(cat <<'EOF'
fix(widget): clear stale recrawl error when user clicks Save

Cooldown error under the buttons used to linger after the user
clicked the unrelated Save button. Clear recrawlError at the start
of saveIndexing() to match the symmetry of recrawlNow().

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Hide IndexingStatusBanner Retry link on self-route

**Why:** The banner's failed-state link points to `/widget-settings`. Now that the banner is mounted on that page (from PR `22d962a`), clicking Retry is a self-navigation no-op — misleading affordance. Skip rendering the link when `usePage().url` matches the banner's link target. The link still works on Dashboard and Knowledge pages where it's actually useful.

**Files:**
- Modify: `resources/js/Components/IndexingStatusBanner.vue:128-146`

- [ ] **Step 1: Read the current template**

Run: `sed -n '125,150p' resources/js/Components/IndexingStatusBanner.vue`

Confirm the current `<template>` matches the structure with `<Link v-if="banner.link" ...>` near the end.

- [ ] **Step 2: Add a computed helper to detect self-route**

In `resources/js/Components/IndexingStatusBanner.vue`, find this line (around line 117):

```js
const toneClasses = {
```

Insert ABOVE it:

```js
const showLink = computed(() => {
  if (!banner.value?.link) return false
  // Hide the link when it would just navigate to the page we're already on.
  // page.url is path-only (e.g. "/widget-settings"); banner.link.href may include a query.
  const currentPath = page.url.split('?')[0]
  const linkPath = banner.value.link.href.split('?')[0]
  return currentPath !== linkPath
})

```

(Keep the blank line before `const toneClasses = {`.)

- [ ] **Step 3: Update the template to gate the Link on showLink**

Find this line in the same file (around line 144):

```vue
      <Link v-if="banner.link" :href="banner.link.href" class="text-sm font-medium underline">{{ banner.link.label }}</Link>
```

Replace with:

```vue
      <Link v-if="showLink" :href="banner.link.href" class="text-sm font-medium underline">{{ banner.link.label }}</Link>
```

- [ ] **Step 4: Rebuild assets**

Run: `npm run build`
Expected: clean build.

- [ ] **Step 5: Smoke test**

1. Open `http://chatbot.test/widget-settings` — the amber "Indexed N pages — plan limit reached" banner SHOULD show the "Upgrade" link (target is `/billing`, not self — proves the gating's positive case).
2. The `showLink` logic is simple enough (single path comparison) that we trust the computed. Failure-state coverage will land naturally the first time a real crawl fails in dev or staging — no need to fabricate it via temporary edits.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/IndexingStatusBanner.vue
git commit -m "$(cat <<'EOF'
fix(widget): hide IndexingStatusBanner Retry link on self-route

The banner's failed-state "Retry" link points to /widget-settings.
Since PR 22d962a mounted the banner ON /widget-settings, clicking
Retry was a no-op self-navigation. Gate the link render on whether
the target path differs from the current page path; the link still
shows correctly on Dashboard and Knowledge Base.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Final verification

- [ ] **Run the full PHP test suite**

Run: `php artisan test`
Expected: All tests pass (881 → 882 with the new queue-failure test).

- [ ] **Pint check on touched PHP file**

Run: `./vendor/bin/pint --test app/Http/Controllers/Client/WebsiteIndexingController.php tests/Feature/Client/WebsiteIndexingControllerTest.php`
Expected: `{"result":"pass"}`.

- [ ] **Verify no PHPStan regressions**

Run: `./vendor/bin/phpstan analyse --no-progress 2>&1 | tail -3`
Expected: `[OK] No errors` (baseline is 0).

- [ ] **Push all 5 commits to main**

Run: `git push origin main`

---

## Self-Review Notes

**Coverage of all 6 findings:**

| # | Finding | Closed by |
|---|---|---|
| 1 | Non-validation errors (500/419/network) — `onError` doesn't fire | Task 1 (`router.on('exception')` page-scoped listener for network failures; Inertia's built-in modal handles 500/419 — accepted as out of scope) |
| 2 | 403 from authorize() shows generic message | Task 1's generic fallback; button is `v-if`-gated on the same capability so this is rare. Acceptable. |
| 3 | Flash-key not asserted in tests | Task 3 |
| 4 | Stale `recrawlError` after Save click | Task 4 |
| 5 | Self-nav Retry link on /widget-settings | Task 5 |
| 6 | `CrawlWebsiteJob::dispatch()` no try/catch | Task 2 |

**Revisions from initial advisor review (advisor pass 1):**
- **Task 1:** Initial draft used `.catch()` on `router.post()`. Verified `router.post()` returns `undefined` in `@inertiajs/vue3 v2.3.18` — that approach would have thrown `TypeError` and broken the click handler entirely. Replaced with `router.on('exception', ...)` page-scoped listener (Inertia DOES fire `inertia:exception` for axios failures — confirmed in `node_modules/@inertiajs/core/dist/index.esm.js:71,2242`). Listener is gated on `recrawling.value` so it only fires for in-flight recrawls; cleanup in `onBeforeUnmount` prevents SPA-nav leaks.
- **Task 2:** Initial draft mixed `Bus::fake()` with `Bus::shouldReceive()` — unreliable pairing (`fake()` installs `BusFake`, then `shouldReceive` swaps to Mockery). Switched to `Bus::shouldReceive('dispatch')` alone, with a documented fallback to `$this->instance(Dispatcher::class, ...)` if the first approach doesn't intercept the right call site.
- **Task 5:** Dropped the "edit `IndexingStatusBanner.vue` momentarily to force error state" smoke step — fragile, easy to leak into the next commit. The positive-case test (partial-budget Upgrade link stays visible) plus the trivial `showLink` logic gives adequate confidence.

**Type consistency check:**
- `errors.queue` introduced in Task 1's Vue chain, consumed by Task 2's controller `withErrors(['queue' => ...])`. Matches.
- `showLink` computed in Task 5 references `banner.value?.link.href` — `banner` is already a `computed` ref in `IndexingStatusBanner.vue:78-117`. Matches.
- `recrawlError` ref already defined in `Widget/Index.vue:106`. Task 4 just reads/writes the same ref. Matches.
- `removeExceptionListener` is a local module-scope `let` in Task 1; declared once, mutated by `onMounted` and `onBeforeUnmount`. Matches the standard Vue 3 listener cleanup pattern.

**Placeholder scan:** None. All code blocks contain executable content with exact paths and commands.

**Independence check:** Tasks 3, 4, 5 are independent of Tasks 1-2 (could ship in any order). Tasks 1 and 2 are coupled — Task 1's Vue chain reads `errors.queue` which only exists after Task 2. If Task 1 ships first, `errors.queue` evaluates to `undefined` and falls through to the generic "Re-crawl failed" message — safe no-op. Recommended order is the documented order (1 → 2 → 3 → 4 → 5) since the test in Task 2 is the natural pairing for Task 2's controller change.

**Known limitations (documented, not blocking):**
- HTTP 500 / 419 (CSRF expired) are NOT explicitly handled — Inertia shows its built-in debug modal. Future polish could add a global `router.on('error', ...)` toast at the layout level, but that's a project-wide UX decision out of scope here.
- The Task 1 fix is page-scoped to `Widget/Index.vue`. Other pages with critical button actions (Stripe webhook tests, manual transaction approval, etc.) still rely on Inertia's default modal for network failures. A future global handler in `app.js` would generalize this — tracked as a separate idea, not in this plan.
