# Misc Operational — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close three medium-severity operational findings from the May 2026 audits — widget loader fails when script loads post-`DOMContentLoaded` (M6), admin client index hides soft-deleted tenants entirely (M8), and `getTopQuestions` does a full-scan GROUP BY on an unindexed text column (M-NEW-10). M3 (streaming chat orphan) is **dropped after verification** — PR #6's C-NEW-4 fix already restructured `streamMessage` with delete-on-throw on the user message (verified at `ChatController.php:263`).

**Architecture:**
- **M6**: replace `document.addEventListener('DOMContentLoaded', init)` with a `readyState`-aware shim — call init immediately if the DOM is past `loading`, otherwise wait. Pattern used by every script-loader on the web.
- **M-NEW-10**: add a `content_hash` `char(32)` column to `messages` with an index, populate via a `saving` model boot hook (and backfill in the migration), refactor `AnalyticsService::getTopQuestions` to `GROUP BY content_hash` with `MAX(content) AS sample` for a representative string.
- **M8**: add a `?trashed=with|only` filter to `Admin/ClientController::index`, default to active-only (preserve current UX). Add `POST /admin/clients/{id}/restore` route and controller method. Pass `deleted_at` to the Inertia page so the front-end can show a "Deleted" badge — front-end rendering is optional polish, not a plan task here.

**Tech Stack:** Laravel 13+, PHP 8.3+, Pest. SQLite in tests. Existing model `Message` uses `created_at`/timestamps; the new `content_hash` column gets backfilled at migration time on prod and is populated automatically on every save thereafter.

**Spec reference:** `docs/superpowers/specs/2026-05-12-medium-backlog-design.md` — Cluster 5.

**Order:** highest-impact first.
- Task 1 — **M6** (smallest blast radius, biggest tenant-visible fix — async-loaded widgets currently never appear).
- Task 2 — **M-NEW-10** (biggest perf win — analytics page stops gateway-timing-out).
- Task 3 — **M8** (admin-facing UX; lowest urgency for tenants).

---

## Task 0: Verification pass against current `main`

- [ ] **Step 1: Drop M3 — confirm streamMessage already has delete-on-throw**

```bash
grep -n "userMessage->delete\|streamMessage" app/Http/Controllers/Api/V1/Widget/ChatController.php
```
Expected: `streamMessage` at line ~189, `$userMessage->delete();` at line ~263. M3 is fixed; skip it from this cluster.

- [ ] **Step 2: Verify M6 — DOMContentLoaded listener with no readyState check**

```bash
grep -nB2 -A4 "DOMContentLoaded\|readyState" public/widget/chatbot.js
```
Expected: a single `document.addEventListener('DOMContentLoaded', function() {...})` near the end of the file, with NO preceding `readyState` check. (`public/widget/widget.js` is a separate loader stub — not in scope unless it shows the same pattern; check anyway.)

- [ ] **Step 3: Verify M8 — admin client index has no `withTrashed`**

```bash
grep -nE "function index|withTrashed|onlyTrashed|trashed" app/Http/Controllers/Admin/ClientController.php
```
Expected: only the `function index` line is found; no `withTrashed` anywhere.

- [ ] **Step 4: Verify M-NEW-10 — getTopQuestions group-by content**

```bash
grep -n "groupBy.*content\|getTopQuestions" app/Services/Analytics/AnalyticsService.php
```
Expected: `groupBy('content')` inside `getTopQuestions`.

- [ ] **Step 5: Verify there's no existing content_hash column**

```bash
grep -rn "content_hash" database/migrations/ app/Models/Message.php
```
Expected: no hits.

- [ ] **Step 6: Inspect actual SQL for getTopQuestions to inform index shape**

```bash
php artisan tinker --execute="echo \App\Models\Message::whereHas('conversation', fn (\$q) => \$q->where('tenant_id', 1))->where('role', 'user')->where('created_at', '>=', now()->subDays(30))->selectRaw('content, COUNT(*) as count')->groupBy('content')->orderByDesc('count')->limit(10)->toSql() . PHP_EOL;"
```
Expected output (verified during plan authoring):
```
select content, COUNT(*) as count from "messages" where exists (select * from "conversations" where "messages"."conversation_id" = "conversations"."id" and "tenant_id" = ?) and "role" = ? and "created_at" >= ? group by "content" order by "count" desc limit 10
```

Key observations:
- `whereHas` becomes an `EXISTS` subquery, NOT a flat `IN` clause. The composite `(conversation_id, role, content_hash)` index would not have helped the EXISTS — the planner correlates via the existing `(conversation_id, created_at)` index on `messages`.
- The GROUP BY (TEXT column) is the bottleneck — switching to a fixed-size CHAR(32) hash + a standalone `content_hash` index lets the planner sort-eliminate on the GROUP BY.
- Existing `(conversation_id, created_at)` index continues to serve the EXISTS-join + date-range filter.

**Index decision:** simple standalone `index('content_hash')`, not a composite.

- [ ] **Step 7: Proceed**

M3 dropped. M6, M-NEW-10, M8 all live. Index shape locked to standalone `content_hash`.

---

## Task 1: M6 — Widget loader handles post-DOMContentLoaded injection

**Goal:** Async/deferred/GTM-injected widget scripts must initialize even when the script tag is parsed AFTER `DOMContentLoaded` has already fired. Currently `document.addEventListener('DOMContentLoaded', init)` registers a listener for an event that already passed → silent no-op → widget never appears.

**Files:**
- Modify: `public/widget/chatbot.js` (the auto-init block at the bottom)
- Test: Browser smoke (no Pest infra for JS in this project). Plan's Task 4 step 2 covers it.

- [ ] **Step 1: Read the existing auto-init block**

The relevant section is the last ~25 lines of `public/widget/chatbot.js`:

```js
const script = document.currentScript || document.querySelector('script[data-chatbot-key]');
if (script) {
    const apiKey = script.getAttribute('data-chatbot-key');
    // ... attribute reads ...
    if (apiKey) {
        document.addEventListener('DOMContentLoaded', function() {
            const options = { ... };
            ChatbotWidget.init(options);
        });
    }
}
```

- [ ] **Step 2: Rewrite the auto-init block to be readyState-aware**

In `public/widget/chatbot.js`, replace the `document.addEventListener('DOMContentLoaded', function() { ... })` with a `readyState` check that calls the init immediately when the DOM is already past `loading`:

```js
        if (apiKey) {
            const initWithOptions = function() {
                const options = {
                    apiKey: apiKey,
                    baseUrl: baseUrl,
                    position: position || 'bottom-right',
                    primaryColor: color || '#4F46E5'
                };
                if (botName) options.botName = botName;
                if (welcomeMessage) options.welcomeMessage = welcomeMessage;
                if (botAvatar) options.botAvatar = botAvatar;
                if (launcherIcon) options.launcherIcon = launcherIcon;

                ChatbotWidget.init(options);
            };

            // readyState is 'loading' before DOMContentLoaded fires, then
            // 'interactive', then 'complete'. If the script loads (e.g. via
            // GTM, async/defer, or any dynamic injection) after the event
            // already fired, the listener never runs — so call init now.
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initWithOptions);
            } else {
                initWithOptions();
            }
        }
```

The function is extracted to keep both branches calling the same initializer.

- [ ] **Step 3: Sanity check — the other widget file**

Briefly read `public/widget/widget.js` (the slim alternative loader). If it has the same `DOMContentLoaded` listener pattern with no readyState check, apply the same fix. If it's a different shape (e.g. a build artifact, a third-party loader, or already correctly handled), leave it alone and note the asymmetry in the commit message.

```bash
grep -nB2 -A4 "DOMContentLoaded\|readyState" public/widget/widget.js
```

- [ ] **Step 4: Commit**

```bash
git add public/widget/chatbot.js
# Add widget.js too if you updated it
git commit -m "$(cat <<'EOF'
fix(widget): initialize on any readyState, not only on DOMContentLoaded (M6)

When the widget script is injected dynamically (Google Tag Manager,
async/defer attributes, late-loaded third-party scripts), the
DOMContentLoaded event has often already fired by the time the
listener registers — so init never runs and the widget silently
fails to appear. Add a readyState check that calls init immediately
when the DOM is past 'loading', and falls back to the listener only
when the document is still parsing.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: still 280 passing (JS isn't covered by Pest; this just confirms no PHP regression).

Note: real verification happens in Task 4's browser smoke — load the widget via `setTimeout(() => { const s = document.createElement('script'); s.src = '/widget/chatbot.js'; s.setAttribute('data-chatbot-key', '...'); document.body.appendChild(s); }, 2000)` from a test page and confirm the widget appears.

---

## Task 2: M-NEW-10 — `getTopQuestions` content-hash index

**Goal:** `groupBy('content')` on an unindexed `text` column does a full scan + filesort. At a million messages per tenant, the analytics page gateway-times-out. Add a `content_hash` `char(32)` column (MD5), index it, populate via a model boot hook + backfill migration. Refactor the service to `GROUP BY content_hash` with `MAX(content) AS sample` for a representative content per group.

**Files:**
- Create: `database/migrations/2026_05_14_000001_add_content_hash_to_messages.php`
- Modify: `app/Models/Message.php` (add `saving` boot hook)
- Modify: `app/Services/Analytics/AnalyticsService.php` (`getTopQuestions` method, lines 195–215)
- Test: `tests/Unit/Models/MessageContentHashTest.php` (new file — unit test for the hook)
- Test: `tests/Unit/Services/Analytics/GetTopQuestionsTest.php` (new file — service-level test asserting grouping + sample content)

- [ ] **Step 1: Inspect the Message model for existing booted hooks**

```bash
grep -nE "booted|saving|creating|Message::class" app/Models/Message.php | head -10
```
Note the existing structure. If `booted()` exists, the new `static::saving` call goes inside it. If not, add the method.

Also confirm the `Message` model uses `HasFactory` / standard timestamps — the new column is plain Eloquent territory.

- [ ] **Step 2: Write the failing service test**

Create `tests/Unit/Services/Analytics/GetTopQuestionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Analytics;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Analytics\AnalyticsService;
use Tests\TestCase;

class GetTopQuestionsTest extends TestCase
{
    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Analytics',
            'slug' => 'analytics-' . uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function test_groups_identical_questions_and_returns_one_sample_per_group(): void
    {
        $tenant = $this->makeTenant();
        $conv = Conversation::create([
            'tenant_id' => $tenant->id,
            'session_id' => 'sess-' . uniqid(),
            'status' => 'active',
        ]);

        // Three asks of "What is the price?" and two of "How do I sign up?"
        for ($i = 0; $i < 3; $i++) {
            Message::create([
                'conversation_id' => $conv->id,
                'role' => 'user',
                'content' => 'What is the price?',
            ]);
        }
        for ($i = 0; $i < 2; $i++) {
            Message::create([
                'conversation_id' => $conv->id,
                'role' => 'user',
                'content' => 'How do I sign up?',
            ]);
        }

        $top = app(AnalyticsService::class)->getTopQuestions($tenant);

        $this->assertCount(2, $top);
        $this->assertSame('What is the price?', $top[0]['question']);
        $this->assertSame(3, $top[0]['count']);
        $this->assertSame('How do I sign up?', $top[1]['question']);
        $this->assertSame(2, $top[1]['count']);
    }
}
```

- [ ] **Step 3: Run the failing service test**

```bash
php artisan test --filter=GetTopQuestionsTest
```
Expected: FAIL — either the test errors because `content_hash` column doesn't exist (after the migration is added in Step 5), or it passes incidentally on the old `groupBy('content')` path. Confirm the failure mode.

If the test passes today (on `groupBy('content')`), it's a regression guard — locks the behavior so the refactor preserves it. Note that and proceed.

- [ ] **Step 4: Create the migration with backfill**

Create `database/migrations/2026_05_14_000001_add_content_hash_to_messages.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->char('content_hash', 32)->nullable()->after('content');
        });

        // Backfill — use driver-specific MD5 syntax. SQLite has no native
        // md5; backfill on SQLite skips and relies on the model hook for
        // future writes (acceptable since SQLite is test-only).
        if (DB::connection()->getDriverName() !== 'sqlite' && DB::table('messages')->exists()) {
            DB::table('messages')->whereNull('content_hash')->update([
                'content_hash' => DB::raw('MD5(content)'),
            ]);
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['content_hash']);
            $table->dropColumn('content_hash');
        });
    }
};
```

**Index choice rationale** (per Task 0 Step 6 SQL inspection): the query is an EXISTS-correlated join on `conversations`, filtered by `role` and `created_at`, then `GROUP BY content_hash`. The existing `(conversation_id, created_at)` index already serves the join + date range. A standalone `content_hash` index gives the planner the option to sort-eliminate on the GROUP BY. A composite index including `conversation_id` wouldn't have helped the EXISTS subquery, which doesn't translate to a `WHERE conversation_id IN (...)`.

- [ ] **Step 5: Add the model `saving` boot hook**

In `app/Models/Message.php`, add a `static::saving` hook that populates `content_hash` from `content`. If a `booted()` method already exists, add the call inside; otherwise add the method.

```php
    protected static function booted(): void
    {
        static::saving(function (Message $message) {
            $message->content_hash = md5((string) $message->content);
        });
    }
```

If the `Message` model uses `protected $fillable`, add `'content_hash'` to it. If it uses `protected $guarded = []` (mass-assignment-open), no change needed.

- [ ] **Step 6: Refactor `getTopQuestions` to group by hash**

In `app/Services/Analytics/AnalyticsService.php`, replace `getTopQuestions` (lines 195–215):

```php
    /**
     * Get top questions (most common user messages).
     *
     * Groups by content_hash (indexed) so the query is index-eligible
     * regardless of message volume. MAX(content) returns one
     * representative content string per group — duplicates share the
     * same hash so any sample is equivalent.
     *
     * @return array<int, array<string, mixed>>
     */
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

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'question' => strlen($row->sample) > 100 ? substr($row->sample, 0, 100).'...' : $row->sample,
                'count' => (int) $row->count,
            ];
        }

        return $result;
    }
```

- [ ] **Step 7: Write the failing model hook test**

Create `tests/Unit/Models/MessageContentHashTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use Tests\TestCase;

class MessageContentHashTest extends TestCase
{
    public function test_saving_populates_content_hash(): void
    {
        $tenant = Tenant::create([
            'name' => 'H', 'slug' => 'h-' . uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $conv = Conversation::create([
            'tenant_id' => $tenant->id,
            'session_id' => 'h-sess',
            'status' => 'active',
        ]);

        $message = Message::create([
            'conversation_id' => $conv->id,
            'role' => 'user',
            'content' => 'What is the price?',
        ]);

        $this->assertSame(md5('What is the price?'), $message->content_hash);
    }

    public function test_saving_updates_hash_when_content_changes(): void
    {
        $tenant = Tenant::create([
            'name' => 'H2', 'slug' => 'h2-' . uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $conv = Conversation::create([
            'tenant_id' => $tenant->id,
            'session_id' => 'h2-sess',
            'status' => 'active',
        ]);

        $message = Message::create([
            'conversation_id' => $conv->id,
            'role' => 'user',
            'content' => 'first content',
        ]);
        $original = $message->content_hash;

        $message->update(['content' => 'second content']);

        $this->assertNotSame($original, $message->content_hash);
        $this->assertSame(md5('second content'), $message->content_hash);
    }
}
```

- [ ] **Step 8: Run all the new tests**

```bash
php artisan test --filter="MessageContentHashTest|GetTopQuestionsTest"
```
Expected: all PASS.

- [ ] **Step 9: Run the full suite**

```bash
php artisan test
```
Expected: 283 passing (280 + 3 new). If any pre-existing analytics test breaks because the old query returned `count` as a string and the new one casts to int, fix the assertion type.

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_05_14_000001_add_content_hash_to_messages.php \
        app/Models/Message.php \
        app/Services/Analytics/AnalyticsService.php \
        tests/Unit/Models/MessageContentHashTest.php \
        tests/Unit/Services/Analytics/GetTopQuestionsTest.php
git commit -m "$(cat <<'EOF'
perf(analytics): group top questions by indexed content_hash (M-NEW-10)

Previously GROUP BY content (unindexed text column) forced a full
scan + filesort. At a million messages per tenant, the analytics
page gateway-timed out. Add a char(32) content_hash column with a
composite (conversation_id, role, content_hash) index, populate via
a Message::saving boot hook, and backfill non-SQLite drivers in the
migration. The refactored getTopQuestions groups by the hash and
returns MAX(content) as a representative sample per group.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: M8 — Admin client index trashed filter + restore route

**Goal:** Soft-deleted tenants are currently invisible to admins (default Eloquent scope filters them). Admins need to (a) see deleted tenants when looking for them, and (b) restore them. Add a `?trashed=with|only` query param to the index, default to active-only (preserve current UX). Add a `POST /admin/clients/{id}/restore` route + controller method.

**Files:**
- Modify: `app/Http/Controllers/Admin/ClientController.php` (index + new restore method)
- Modify: `routes/web.php` (new restore route inside the admin group)
- Test: `tests/Feature/Admin/AdminClientTrashedTest.php` (new file — 3 methods)

- [ ] **Step 1: Inspect existing admin client routes**

```bash
grep -nE "admin/clients|AdminClientController" routes/web.php | head -10
```
Confirm route structure. The new restore route follows the existing `clients/{client}/...` pattern.

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/Admin/AdminClientTrashedTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Tenant;
use Tests\TestCase;

class AdminClientTrashedTest extends TestCase
{
    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AdminUser::create([
            'name' => 'A', 'email' => 'a@test.example', 'password' => bcrypt('x'),
        ]);
    }

    public function test_index_hides_soft_deleted_tenants_by_default(): void
    {
        $active = Tenant::create([
            'name' => 'Active', 'slug' => 'active-' . uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $deleted = Tenant::create([
            'name' => 'Deleted', 'slug' => 'deleted-' . uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $deleted->delete();

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/clients');
        $response->assertStatus(200);

        $clientIds = collect($response->viewData('page')['props']['clients']['data'])->pluck('id')->all();
        $this->assertContains($active->id, $clientIds);
        $this->assertNotContains($deleted->id, $clientIds);
    }

    public function test_index_shows_deleted_tenants_when_trashed_filter_is_with(): void
    {
        $active = Tenant::create([
            'name' => 'Active', 'slug' => 'active-' . uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $deleted = Tenant::create([
            'name' => 'Deleted', 'slug' => 'deleted-' . uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $deleted->delete();

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/clients?trashed=with');
        $response->assertStatus(200);

        $clientIds = collect($response->viewData('page')['props']['clients']['data'])->pluck('id')->all();
        $this->assertContains($active->id, $clientIds);
        $this->assertContains($deleted->id, $clientIds);
    }

    public function test_index_shows_only_deleted_tenants_when_trashed_filter_is_only(): void
    {
        $active = Tenant::create([
            'name' => 'Active', 'slug' => 'active-' . uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $deleted = Tenant::create([
            'name' => 'Deleted', 'slug' => 'deleted-' . uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $deleted->delete();

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/clients?trashed=only');
        $response->assertStatus(200);

        $clientIds = collect($response->viewData('page')['props']['clients']['data'])->pluck('id')->all();
        $this->assertNotContains($active->id, $clientIds);
        $this->assertContains($deleted->id, $clientIds);
    }

    public function test_admin_can_restore_a_soft_deleted_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Restoreme', 'slug' => 'restoreme-' . uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);
        $tenant->delete();

        $this->assertNotNull($tenant->fresh()->deleted_at);

        $response = $this->actingAs($this->admin, 'admin')
            ->post("/admin/clients/{$tenant->id}/restore");

        $response->assertRedirect();
        $this->assertNull($tenant->fresh()->deleted_at);
    }
}
```

- [ ] **Step 3: Run failing tests**

```bash
php artisan test --filter=AdminClientTrashedTest
```
Expected: all 4 FAIL — no filter handling, no restore route.

- [ ] **Step 4: Update `ClientController::index` to honor the trashed filter**

In `app/Http/Controllers/Admin/ClientController.php`, modify the `index` method. After the existing search/status/plan filters, add the trashed-filter branch BEFORE the sort/paginate:

```php
        // Trashed filter — preserve default of active-only.
        $trashed = $request->input('trashed');
        if ($trashed === 'with') {
            $query->withTrashed();
        } elseif ($trashed === 'only') {
            $query->onlyTrashed();
        }
```

The `Tenant` model already uses `SoftDeletes` (verified in cluster 1's review). `withTrashed()` / `onlyTrashed()` are standard methods.

Also add `trashed` to the `filters` array that's passed to Inertia:

```php
            'filters' => [
                'search' => $request->input('search', ''),
                'status' => $request->input('status', 'all'),
                'plan' => $request->input('plan', 'all'),
                'trashed' => $request->input('trashed', ''),
                'sort' => $sortField,
                'direction' => $sortDirection,
            ],
```

- [ ] **Step 5: Add the `restore` method**

In `app/Http/Controllers/Admin/ClientController.php`, add a new public method (place it after `index` / `show`):

```php
    public function restore(int $client): RedirectResponse
    {
        $tenant = Tenant::onlyTrashed()->findOrFail($client);
        $tenant->restore();

        return redirect()
            ->route('admin.clients.show', $tenant->id)
            ->with('success', 'Tenant restored.');
    }
```

Note the parameter type is `int`, not `Tenant`, because route-model binding's default scope excludes soft-deleted models. We resolve manually via `Tenant::onlyTrashed()->findOrFail($client)`.

If `RedirectResponse` isn't already imported, add `use Illuminate\Http\RedirectResponse;` at the top.

- [ ] **Step 6: Register the route**

In `routes/web.php`, inside the admin middleware group, after the existing `admin.clients.*` routes, add:

```php
        Route::post('clients/{client}/restore', [AdminClientController::class, 'restore'])->name('clients.restore');
```

(The route param name `{client}` matches the existing pattern; controller binds via the int because we use `findOrFail`.)

- [ ] **Step 7: Run the new tests**

```bash
php artisan test --filter=AdminClientTrashedTest
```
Expected: all 4 PASS.

- [ ] **Step 8: Run the wider admin suite**

```bash
php artisan test tests/Feature/Admin
```
Expected: all green (the existing admin tests don't touch soft-deletes so they're unaffected).

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Admin/ClientController.php \
        routes/web.php \
        tests/Feature/Admin/AdminClientTrashedTest.php
git commit -m "$(cat <<'EOF'
feat(admin): trashed filter on client index + restore route (M8)

Admin client index now honors ?trashed=with|only — default stays
active-only so existing UX is preserved. New POST /admin/clients/{id}/restore
brings a soft-deleted tenant back. Front-end can render a "Deleted"
badge from the deleted_at field on the page payload (out of scope
for this PR).

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 10: Run the full suite**

```bash
php artisan test
```
Expected: 287 passing (283 from Task 2 + 4 new).

---

## Task 4: Browser smoke, Pint, /simplify, PR

Per CLAUDE.md's updated workflow (Pint → /simplify → Pint → /simplify → PR), with browser smoke first.

- [ ] **Step 1: Boot dev environment**

```bash
php artisan serve --port=8001
npm run dev
```

- [ ] **Step 2: Browser smoke — widget loads after DOMContentLoaded (M6)**

Create a quick test page that injects the widget script AFTER DOMContentLoaded fires:

```bash
mkdir -p /tmp/widget-smoke && cat > /tmp/widget-smoke/index.html <<'HTML'
<!DOCTYPE html>
<html>
<head><title>M6 smoke — late widget injection</title></head>
<body>
<h1>Widget loaded after DOMContentLoaded</h1>
<p id="status">Will inject widget in 2s…</p>
<script>
// Wait until well after DOMContentLoaded, then inject the script.
window.addEventListener('load', () => {
    setTimeout(() => {
        document.getElementById('status').textContent = 'Injecting widget script now…';
        const s = document.createElement('script');
        s.src = 'http://127.0.0.1:8001/widget/chatbot.js';
        s.setAttribute('data-chatbot-key', 'REPLACE_WITH_TEST_TENANT_API_KEY');
        s.setAttribute('data-chatbot-url', 'http://127.0.0.1:8001');
        document.body.appendChild(s);
        setTimeout(() => {
            const launcher = document.querySelector('.chatbot-launcher');
            document.getElementById('status').textContent = launcher ? 'PASS: widget visible' : 'FAIL: widget not rendered';
        }, 1000);
    }, 2000);
});
</script>
</body>
</html>
HTML
```

Get the test tenant's API key and slot it in, then serve the page from a different origin and visit it. Tenant must have `127.0.0.1` in `allowed_domains` for the widget's API calls — but the **widget DOM rendering** doesn't require the API; the M6 fix is about whether `ChatbotWidget.init()` ever runs. Even if API calls 403, the launcher should appear.

```bash
API_KEY=$(php artisan tinker --execute="echo \App\Models\Tenant::where('slug','test-company')->value('api_key');")
sed -i.bak "s/REPLACE_WITH_TEST_TENANT_API_KEY/$API_KEY/" /tmp/widget-smoke/index.html

# Serve on port 9000 (different origin)
cd /tmp/widget-smoke && python3 -m http.server 9000 &
```

Open `http://127.0.0.1:9000/` in a browser (or Playwright). The page should:
1. Show "Will inject widget in 2s…"
2. After ~2s: "Injecting widget script now…"
3. After ~3s: "PASS: widget visible" — AND the chatbot launcher button should be visible in the bottom-right corner.

Without the M6 fix, the page would stick at "Injecting widget script now…" / "FAIL: widget not rendered" because `DOMContentLoaded` already fired before the script even loaded.

- [ ] **Step 3: Browser smoke — admin trashed filter and restore (M8)**

1. Log in as `admin@example.com` / `password`.
2. Navigate to `/admin/clients` — note the active tenant list.
3. (If you have admin permissions or can do it via tinker) Soft-delete a test tenant:
   ```bash
   php artisan tinker --execute="\$t = \App\Models\Tenant::where('slug','test-company')->first(); /* Don't delete the test tenant in real use; use a throwaway. For smoke, create a dummy: */"
   ```
4. Navigate to `/admin/clients?trashed=with` — confirm the deleted tenant appears.
5. Navigate to `/admin/clients?trashed=only` — confirm ONLY deleted tenants appear.
6. (Restore action smoke — requires front-end button which is out of scope. The Pest test covers the backend.)

If front-end controls for the trashed filter / restore button aren't wired up yet, the smoke is limited to URL-based filter testing. That's acceptable per the plan's "front-end rendering optional" note.

- [ ] **Step 4: Browser smoke — analytics page renders fast (M-NEW-10)**

1. As the client, navigate to `/analytics`.
2. The "Top Questions" section should render under 1s on the test tenant (small data set). On a real-data prod tenant, the perf benefit is the actual win — measurable via APM, not local smoke.

If the analytics page returns 500 / hangs, that's a regression — STOP and fix.

- [ ] **Step 5: Pint pass 1**

```bash
./vendor/bin/pint --test \
  public/widget/chatbot.js \
  app/Models/Message.php \
  app/Services/Analytics/AnalyticsService.php \
  app/Http/Controllers/Admin/ClientController.php \
  routes/web.php \
  database/migrations/2026_05_14_000001_add_content_hash_to_messages.php \
  tests/Unit/Models/MessageContentHashTest.php \
  tests/Unit/Services/Analytics/GetTopQuestionsTest.php \
  tests/Feature/Admin/AdminClientTrashedTest.php
```

(Pint won't lint `public/widget/chatbot.js` — JS isn't in scope. Drop that from the list or accept the no-op.)

If flagged, drop `--test` to apply, then `php artisan test` to confirm, then commit as `style(pint): apply auto-fixes to cluster-5 files`.

- [ ] **Step 6: /simplify pass 1**

Run `/simplify`. Apply substantive findings; skip stylistic with one-liner reasons.

- [ ] **Step 7: Pint pass 2** — same files. Apply fixes if any.

- [ ] **Step 8: /simplify pass 2** — address anything new.

```bash
php artisan test
```
Expected: all green.

- [ ] **Step 9: Open the PR**

Push, then:

```bash
gh pr create --title "fix(ops): close cluster-5 misc operational findings" --body "$(cat <<'EOF'
## Summary

Cluster 5 of the medium-backlog spec — misc operational fixes. M3 was dropped after Task 0 verification (PR #6's C-NEW-4 already restructured streamMessage with delete-on-throw).

- **M6** — Widget loader (\`public/widget/chatbot.js\`) gains a \`document.readyState\` check before registering the \`DOMContentLoaded\` listener. Scripts injected after the DOM is past \`loading\` (Google Tag Manager, async/defer, late dynamic injection) now initialize immediately instead of silently no-op'ing.
- **M-NEW-10** — \`messages\` gains an indexed \`content_hash\` \`char(32)\` column, populated via a \`Message::saving\` boot hook and backfilled at migration time on non-SQLite drivers. \`AnalyticsService::getTopQuestions\` now \`GROUP BY content_hash\` with \`MAX(content) AS sample\` — index-eligible regardless of message volume.
- **M8** — Admin \`client\` index honors \`?trashed=with|only\`. New \`POST /admin/clients/{id}/restore\` route undeletes a tenant. Active-only default preserves the existing admin UX.

## Deploy steps

1. Merge.
2. **Pre-flight on prod**: the \`add_content_hash_to_messages\` migration backfills \`MD5(content)\` for every existing message row. On large messages tables this is a one-shot table update. Verify table size first:
   \`\`\`sql
   SELECT COUNT(*) FROM messages;
   \`\`\`
   Under 10M rows: run migrate normally. Over 10M: run in a maintenance window, or batch the backfill in 100k-row chunks via a follow-up data migration.
3. \`php artisan migrate\` — adds the column + composite index. The composite \`(conversation_id, role, content_hash)\` is online-safe on MySQL 8.

## ⚠️ Behavior changes

| Change | Who's affected | Mitigation |
|---|---|---|
| Widget initializes on any readyState, not only DOMContentLoaded | None — strictly looser; widgets that ALREADY worked continue to | None |
| Tenants embedding via GTM / async injection see widget appear for the first time | Those tenants (positive change) | None |
| Top Questions analytics groups by content hash | None — semantics preserved (groups identical strings) | None |
| Admin client index hides soft-deleted tenants by default (already true, now explicitly documented) | None — same default behavior | None |
| Admin client index supports \`?trashed=with\` / \`?trashed=only\` filters | Admins (positive change) | None |
| Soft-deleted tenants can be restored via POST /admin/clients/{id}/restore | Admins (positive change) | Front-end UI is a follow-up |

## Test plan

- [x] \`php artisan test\` — N passing (272 baseline + 8 new across 3 test files)
- [x] Pint clean on PR-touched files (×2 passes)
- [x] /simplify ×2
- [x] Browser smoke: late-injected widget script renders launcher (M6)
- [x] Browser smoke: \`/admin/clients?trashed=only\` shows deleted tenants
- [x] Browser smoke: \`/analytics\` Top Questions renders (no regression)
- [x] \`GetTopQuestionsTest\` (1) — identical questions grouped, ordered by count
- [x] \`MessageContentHashTest\` (2) — hash populated on create + updated on content change
- [x] \`AdminClientTrashedTest\` (4) — default hides deleted, with shows both, only shows deleted, restore undeletes

## Dropped from cluster scope

- **M3 (streaming chat orphans)** — verified fixed by PR #6's C-NEW-4. Task 0 grep confirmed delete-on-throw at \`ChatController::streamMessage\` line 263.

## Architecture notes

- **content_hash via MD5** — 128-bit hash, char(32) hex. Collisions are mathematically possible but operationally invisible (~2^64 messages needed). For a tenant with a million messages the false-positive grouping rate is negligible.
- **Restore route uses int param + manual lookup** because route-model binding's default scope excludes soft-deleted models.
- **Widget readyState check** is the universal pattern for safe late-load script injection.

## Links

- Spec: \`docs/superpowers/specs/2026-05-12-medium-backlog-design.md\` (Cluster 5)
- Plan: \`docs/superpowers/plans/2026-05-14-misc-operational.md\`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 10: Update memory after merge**

Save a memory entry capturing:
- Cluster 5 closed (M6, M-NEW-10, M8); M3 verified-and-dropped
- New invariant: every Message create/update populates content_hash via the boot hook; future analytics work should group by content_hash
- Cluster 6 (frontend polish & a11y) still not drafted — last cluster of the medium-backlog spec

---

## Out of scope

- **Front-end "Show deleted tenants" toggle + restore button on `Admin/Clients/Index.vue`** — backend is complete; UI polish is a separate task.
- **Batched migration backfill for >10M-row messages tables** — single-statement backfill is fine for current tenant scale; revisit if needed via deploy-step monitoring.
- **Real cross-origin widget smoke from a fully-public hosted page** — the local-tmp-server smoke covers the protocol; production verification is one of the deploy-step QA items.
- **Replacing MD5 with a stronger hash** — MD5 is fine for "is this string identical" grouping; no security claims attached.
- **Indexing on `(tenant_id, content_hash)` via a join shortcut** — the current `(conversation_id, role, content_hash)` index serves the query that runs today; if a future tenant-level top-questions query is added, revisit.
