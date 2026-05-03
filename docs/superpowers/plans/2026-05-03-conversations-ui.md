# Conversations UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the client-facing Conversations dashboard pages (list + detail + archive + export) so tenants can review what their chatbot has been saying. Drop four unused columns from the `conversations` table while we're already in this area.

**Architecture:** Inertia + Vue 3 pages backed by a single `Client/ConversationController` with five actions. Filtering and pagination handled server-side via Eloquent scopes on the `Conversation` model. Tenant scoping enforced explicitly per-query (`->where('tenant_id', auth()->user()->tenant_id)`), matching the existing `LeadController` pattern. Export streams a `.txt` via `response()->streamDownload()`. No new packages.

**Tech Stack:** Laravel 13 (Eloquent, Inertia adapter), Vue 3 (Composition API, `<script setup>`), Tailwind CSS v4, Postgres + pgvector (existing), PHPUnit 13.

**Spec:** [`docs/superpowers/specs/2026-05-03-conversations-ui-design.md`](../specs/2026-05-03-conversations-ui-design.md)

---

## File inventory (locked in spec)

**New:**
- `database/migrations/2026_05_03_140000_drop_unused_columns_from_conversations_table.php`
- `database/factories/ConversationFactory.php`
- `database/factories/MessageFactory.php`
- `app/Http/Controllers/Client/ConversationController.php`
- `resources/js/Pages/Client/Conversations/Index.vue`
- `resources/js/Pages/Client/Conversations/Show.vue`
- `tests/Unit/Models/ConversationTest.php`
- `tests/Feature/Client/ConversationsIndexTest.php`
- `tests/Feature/Client/ConversationsShowTest.php`

**Modified:**
- `routes/web.php`
- `app/Models/Conversation.php`
- `resources/js/Layouts/ClientLayout.vue`
- `ROADMAP.md`

---

## Task 1: Drop unused columns from `conversations`

**Files:**
- Create: `database/migrations/2026_05_03_140000_drop_unused_columns_from_conversations_table.php`

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_05_03_140000_drop_unused_columns_from_conversations_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['visitor_id', 'started_at', 'ended_at', 'lead_score']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('visitor_id', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('lead_score')->default(0);
        });
    }
};
```

- [ ] **Step 2: Run the migration on dev Postgres**

Run: `php artisan migrate`
Expected: `2026_05_03_140000_drop_unused_columns_from_conversations_table ........... XX.XXms DONE`

- [ ] **Step 3: Verify columns are gone**

Run:
```
php artisan tinker --execute="echo json_encode(\Schema::getColumnListing('conversations'));"
```
Expected: array containing `id, tenant_id, lead_id, session_id, status, metadata, created_at, updated_at` and **NOT** containing `visitor_id`, `started_at`, `ended_at`, `lead_score`.

- [ ] **Step 4: Confirm existing tests still pass**

Run: `php artisan test`
Expected: 127 passed (same as before this plan started).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_05_03_140000_drop_unused_columns_from_conversations_table.php
git commit -m "$(cat <<'EOF'
refactor: drop four unused columns from conversations table

visitor_id, started_at, ended_at, lead_score were defined in the original
M2.5 schema but no production code path writes them. The widget controller
writes metadata.started_at (a JSON key) instead. All four columns are NULL
across existing rows, so no backfill is needed.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Add Conversation + Message factories

**Files:**
- Create: `database/factories/ConversationFactory.php`
- Create: `database/factories/MessageFactory.php`
- Modify: `app/Models/Conversation.php` — add `use HasFactory;`
- Modify: `app/Models/Message.php` — add `use HasFactory;`

- [ ] **Step 1: Add `HasFactory` trait to `Conversation` model**

In `app/Models/Conversation.php`, add the import and trait:

```php
use Illuminate\Database\Eloquent\Factories\HasFactory;
```

And inside the class:

```php
class Conversation extends Model
{
    use HasFactory;
    // ... existing code
}
```

- [ ] **Step 2: Add `HasFactory` trait to `Message` model**

Same pattern in `app/Models/Message.php`.

- [ ] **Step 3: Write `ConversationFactory`**

Create `database/factories/ConversationFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'session_id' => $this->faker->uuid(),
            'status' => 'active',
            'metadata' => [
                'user_agent' => $this->faker->userAgent(),
                'ip' => $this->faker->ipv4(),
            ],
        ];
    }

    public function closed(): self
    {
        return $this->state(['status' => 'closed']);
    }

    public function archived(): self
    {
        return $this->state(['status' => 'archived']);
    }
}
```

- [ ] **Step 4: Write `MessageFactory`**

Create `database/factories/MessageFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => 'user',
            'content' => $this->faker->sentence(),
            'tokens_used' => 0,
        ];
    }

    public function fromAssistant(): self
    {
        return $this->state([
            'role' => 'assistant',
            'tokens_used' => $this->faker->numberBetween(50, 500),
        ]);
    }
}
```

- [ ] **Step 5: Verify factories work**

Run:
```
php artisan tinker --execute="
\$c = \App\Models\Conversation::factory()->make();
echo json_encode(['conv_ok' => (bool) \$c->session_id]).PHP_EOL;
\$m = \App\Models\Message::factory()->fromAssistant()->make();
echo json_encode(['msg_role' => \$m->role, 'tokens' => \$m->tokens_used > 0]).PHP_EOL;
"
```
Expected: `{"conv_ok":true}` and `{"msg_role":"assistant","tokens":true}`

- [ ] **Step 6: Confirm existing tests still pass**

Run: `php artisan test`
Expected: 127 passed.

- [ ] **Step 7: Commit**

```bash
git add database/factories/ConversationFactory.php database/factories/MessageFactory.php app/Models/Conversation.php app/Models/Message.php
git commit -m "$(cat <<'EOF'
test: add Conversation and Message factories

Both models now have factories with semantic states (closed, archived,
fromAssistant). Used by upcoming Conversations UI tests.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Conversation model — scopes, relation, fillable trim (TDD)

**Files:**
- Test: `tests/Unit/Models/ConversationTest.php`
- Modify: `app/Models/Conversation.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Models/ConversationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    public function test_for_tenant_scope_filters_to_one_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        Conversation::factory()->for($a)->count(2)->create();
        Conversation::factory()->for($b)->count(3)->create();

        $this->assertSame(2, Conversation::forTenant($a)->count());
        $this->assertSame(3, Conversation::forTenant($b)->count());
    }

    public function test_with_status_default_excludes_archived(): void
    {
        $tenant = Tenant::factory()->create();
        Conversation::factory()->for($tenant)->create(['status' => 'active']);
        Conversation::factory()->for($tenant)->closed()->create();
        Conversation::factory()->for($tenant)->archived()->create();

        $this->assertSame(2, Conversation::forTenant($tenant)->withStatus(null)->count());
    }

    public function test_with_status_all_includes_archived(): void
    {
        $tenant = Tenant::factory()->create();
        Conversation::factory()->for($tenant)->create(['status' => 'active']);
        Conversation::factory()->for($tenant)->archived()->create();

        $this->assertSame(2, Conversation::forTenant($tenant)->withStatus('all')->count());
    }

    public function test_with_status_specific_filters_exact_match(): void
    {
        $tenant = Tenant::factory()->create();
        Conversation::factory()->for($tenant)->create(['status' => 'active']);
        Conversation::factory()->for($tenant)->closed()->create();
        Conversation::factory()->for($tenant)->archived()->create();

        $this->assertSame(1, Conversation::forTenant($tenant)->withStatus('archived')->count());
        $this->assertSame(1, Conversation::forTenant($tenant)->withStatus('closed')->count());
    }

    public function test_created_between_inclusive_at_both_ends(): void
    {
        $tenant = Tenant::factory()->create();
        Carbon::setTestNow('2026-04-01 00:00:00');
        $a = Conversation::factory()->for($tenant)->create();
        Carbon::setTestNow('2026-04-15 12:00:00');
        $b = Conversation::factory()->for($tenant)->create();
        Carbon::setTestNow('2026-05-01 23:59:59');
        $c = Conversation::factory()->for($tenant)->create();
        Carbon::setTestNow();

        $ids = Conversation::forTenant($tenant)
            ->createdBetween('2026-04-15', '2026-05-01')
            ->pluck('id')
            ->toArray();

        $this->assertSame([$b->id, $c->id], $ids);
    }

    public function test_latest_message_returns_most_recent(): void
    {
        $conv = Conversation::factory()->create();
        $m1 = Message::factory()->for($conv)->create(['created_at' => now()->subMinutes(5)]);
        $m2 = Message::factory()->for($conv)->create(['created_at' => now()->subMinute()]);
        $m3 = Message::factory()->for($conv)->create(['created_at' => now()]);

        $this->assertSame($m3->id, $conv->latestMessage()->first()->id);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/Models/ConversationTest.php`
Expected: All 6 tests FAIL with `Call to undefined method ... forTenant` / `withStatus` / `createdBetween` / `latestMessage`.

- [ ] **Step 3: Implement the scopes + relation on `Conversation`**

Replace `app/Models/Conversation.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'session_id',
        'status',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasOne<Message, $this> */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function close(): void
    {
        $this->update(['status' => 'closed']);
    }

    public function archive(): void
    {
        $this->update(['status' => 'archived']);
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeForTenant(Builder $query, Tenant $tenant): Builder
    {
        return $query->where('tenant_id', $tenant->id);
    }

    /**
     * `null` (default): active OR closed (excludes archived).
     * `'all'`: no filter (includes archived).
     * Any other string: exact-match on status.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeWithStatus(Builder $query, ?string $status): Builder
    {
        if ($status === 'all') {
            return $query;
        }
        if ($status === null || $status === '') {
            return $query->whereIn('status', ['active', 'closed']);
        }
        return $query->where('status', $status);
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeCreatedBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }
        return $query;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Unit/Models/ConversationTest.php`
Expected: 6 PASS.

- [ ] **Step 5: Run the full suite to confirm no regression**

Run: `php artisan test`
Expected: 133 passed (was 127; +6 new).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Conversation.php tests/Unit/Models/ConversationTest.php
git commit -m "$(cat <<'EOF'
feat: add Conversation scopes and latestMessage relation

forTenant, withStatus, createdBetween scopes back the Conversations index
filters. latestMessage relation lets the index avoid N+1 when showing the
last message preview.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Routes + empty controller stub

**Files:**
- Create: `app/Http/Controllers/Client/ConversationController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Create the controller stub**

Create `app/Http/Controllers/Client/ConversationController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConversationController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        abort(501);
    }

    public function show(Request $request, Conversation $conversation): InertiaResponse
    {
        abort(501);
    }

    public function archive(Request $request, Conversation $conversation): Response
    {
        abort(501);
    }

    public function unarchive(Request $request, Conversation $conversation): Response
    {
        abort(501);
    }

    public function export(Request $request, Conversation $conversation): StreamedResponse
    {
        abort(501);
    }
}
```

- [ ] **Step 2: Add the routes**

In `routes/web.php`, find the `Route::prefix('leads')->name('client.leads.')->group(...)` block and add this immediately after its closing `});`:

```php
    Route::prefix('conversations')->name('client.conversations.')->group(function () {
        Route::get('/', [ConversationController::class, 'index'])->name('index');
        Route::get('/{conversation}', [ConversationController::class, 'show'])->name('show');
        Route::get('/{conversation}/export', [ConversationController::class, 'export'])->name('export');
        Route::put('/{conversation}/archive', [ConversationController::class, 'archive'])->name('archive');
        Route::put('/{conversation}/unarchive', [ConversationController::class, 'unarchive'])->name('unarchive');
    });
```

Add the controller import at the top of `routes/web.php` alongside the other `Client/*` imports:

```php
use App\Http\Controllers\Client\ConversationController;
```

- [ ] **Step 3: Verify routes are registered**

Run: `php artisan route:list --path=conversations`
Expected: 5 rows printed for `GET /conversations`, `GET /conversations/{conversation}`, `GET /conversations/{conversation}/export`, `PUT /conversations/{conversation}/archive`, `PUT /conversations/{conversation}/unarchive`. All under the `auth` middleware.

- [ ] **Step 4: Confirm existing tests still pass**

Run: `php artisan test`
Expected: 133 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Client/ConversationController.php routes/web.php
git commit -m "$(cat <<'EOF'
feat: scaffold ConversationController and routes

Five routes registered under the auth middleware group. Controller
methods abort(501) until implemented in the next tasks.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Index action (TDD)

**Files:**
- Test: `tests/Feature/Client/ConversationsIndexTest.php`
- Modify: `app/Http/Controllers/Client/ConversationController.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Client/ConversationsIndexTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ConversationsIndexTest extends TestCase
{
    public function test_lists_only_current_tenants_conversations(): void
    {
        $this->actingAsTenantUser();
        $other = Tenant::factory()->create();

        Conversation::factory()->for($this->tenant)->count(3)->create();
        Conversation::factory()->for($other)->count(5)->create();

        $this->get('/conversations')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Client/Conversations/Index')
                ->has('conversations.data', 3)
            );
    }

    public function test_paginates_at_25_per_page(): void
    {
        $this->actingAsTenantUser();
        Conversation::factory()->for($this->tenant)->count(30)->create();

        $this->get('/conversations')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('conversations.data', 25)
                ->where('conversations.per_page', 25)
                ->where('conversations.total', 30)
            );
    }

    public function test_default_filter_excludes_archived(): void
    {
        $this->actingAsTenantUser();
        Conversation::factory()->for($this->tenant)->create(['status' => 'active']);
        Conversation::factory()->for($this->tenant)->closed()->create();
        Conversation::factory()->for($this->tenant)->archived()->create();

        $this->get('/conversations')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations.data', 2));
    }

    public function test_status_all_includes_archived(): void
    {
        $this->actingAsTenantUser();
        Conversation::factory()->for($this->tenant)->create(['status' => 'active']);
        Conversation::factory()->for($this->tenant)->archived()->create();

        $this->get('/conversations?status=all')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations.data', 2));
    }

    public function test_status_archived_returns_only_archived(): void
    {
        $this->actingAsTenantUser();
        Conversation::factory()->for($this->tenant)->create(['status' => 'active']);
        Conversation::factory()->for($this->tenant)->archived()->create();

        $this->get('/conversations?status=archived')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations.data', 1));
    }

    public function test_date_range_filter(): void
    {
        $this->actingAsTenantUser();
        Carbon::setTestNow('2026-04-01 12:00:00');
        Conversation::factory()->for($this->tenant)->create();
        Carbon::setTestNow('2026-04-15 12:00:00');
        Conversation::factory()->for($this->tenant)->create();
        Carbon::setTestNow('2026-05-15 12:00:00');
        Conversation::factory()->for($this->tenant)->create();
        Carbon::setTestNow();

        $this->get('/conversations?from=2026-04-10&to=2026-05-01')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations.data', 1));
    }

    public function test_has_lead_filter_returns_only_linked(): void
    {
        $this->actingAsTenantUser();
        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sam', 'email' => 'sam@example.com',
        ]);
        Conversation::factory()->for($this->tenant)->create(['lead_id' => $lead->id]);
        Conversation::factory()->for($this->tenant)->count(2)->create();

        $this->get('/conversations?has_lead=1')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations.data', 1));
    }

    public function test_index_includes_message_count_and_latest_message_preview(): void
    {
        $this->actingAsTenantUser();
        $conv = Conversation::factory()->for($this->tenant)->create();
        Message::factory()->for($conv)->count(2)->create();
        Message::factory()->for($conv)->fromAssistant()->create([
            'content' => 'Latest reply from the bot',
        ]);

        $this->get('/conversations')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('conversations.data.0.messages_count', 3)
                ->where('conversations.data.0.latest_message.content', 'Latest reply from the bot')
            );
    }

    public function test_unauthenticated_redirects_to_login(): void
    {
        $this->get('/conversations')->assertRedirect('/login');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Client/ConversationsIndexTest.php`
Expected: All 9 tests FAIL with HTTP 501 (the abort from the stub).

- [ ] **Step 3: Implement the `index` action**

Replace the `index` method in `app/Http/Controllers/Client/ConversationController.php`:

```php
public function index(Request $request): InertiaResponse
{
    /** @var \App\Models\User $user */
    $user = $request->user();
    $tenant = $user->tenant;

    $conversations = Conversation::forTenant($tenant)
        ->withStatus($request->string('status')->toString() ?: null)
        ->createdBetween(
            $request->string('from')->toString() ?: null,
            $request->string('to')->toString() ?: null,
        )
        ->when($request->boolean('has_lead'), fn ($q) => $q->whereNotNull('lead_id'))
        ->withCount('messages')
        ->with([
            'latestMessage:id,conversation_id,content,created_at',
            'lead:id,name,email',
        ])
        ->latest('created_at')
        ->paginate(25)
        ->withQueryString();

    return Inertia::render('Client/Conversations/Index', [
        'conversations' => $conversations,
        'filters' => [
            'status' => $request->string('status')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'has_lead' => $request->boolean('has_lead'),
        ],
    ]);
}
```

Add `use Inertia\Inertia;` at the top of the file.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Client/ConversationsIndexTest.php`
Expected: 9 PASS.

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: 142 passed (was 133; +9).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Client/ConversationController.php tests/Feature/Client/ConversationsIndexTest.php
git commit -m "$(cat <<'EOF'
feat: implement Conversations index controller with filters

Server-side pagination at 25/page; status, date range, and has_lead
filters are query-string driven so the URL is shareable. Eager-loads
latest message and linked lead to avoid N+1.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Show action (TDD)

**Files:**
- Test: `tests/Feature/Client/ConversationsShowTest.php`
- Modify: `app/Http/Controllers/Client/ConversationController.php`

- [ ] **Step 1: Write the failing tests for `show`**

Create `tests/Feature/Client/ConversationsShowTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ConversationsShowTest extends TestCase
{
    public function test_show_renders_conversation_with_messages_in_order(): void
    {
        $this->actingAsTenantUser();
        $conv = Conversation::factory()->for($this->tenant)->create();
        $first = Message::factory()->for($conv)->create([
            'content' => 'first', 'created_at' => now()->subMinutes(5),
        ]);
        $second = Message::factory()->for($conv)->fromAssistant()->create([
            'content' => 'second', 'created_at' => now()->subMinutes(4),
        ]);

        $this->get("/conversations/{$conv->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Client/Conversations/Show')
                ->where('conversation.id', $conv->id)
                ->has('conversation.messages', 2)
                ->where('conversation.messages.0.content', 'first')
                ->where('conversation.messages.1.content', 'second')
            );
    }

    public function test_show_includes_lead_when_linked(): void
    {
        $this->actingAsTenantUser();
        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sam', 'email' => 'sam@example.com', 'score' => 75,
        ]);
        $conv = Conversation::factory()->for($this->tenant)->create(['lead_id' => $lead->id]);

        $this->get("/conversations/{$conv->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('conversation.lead.name', 'Sam')
                ->where('conversation.lead.email', 'sam@example.com')
            );
    }

    public function test_show_lead_is_null_when_not_linked(): void
    {
        $this->actingAsTenantUser();
        $conv = Conversation::factory()->for($this->tenant)->create();

        $this->get("/conversations/{$conv->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('conversation.lead', null));
    }

    public function test_show_404s_on_other_tenants_conversation(): void
    {
        $this->actingAsTenantUser();
        $other = Tenant::factory()->create();
        $conv = Conversation::factory()->for($other)->create();

        $this->get("/conversations/{$conv->id}")->assertNotFound();
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Feature/Client/ConversationsShowTest.php`
Expected: 4 FAIL with HTTP 501.

- [ ] **Step 3: Implement `show`**

Replace the `show` method in `ConversationController`:

```php
public function show(Request $request, Conversation $conversation): InertiaResponse
{
    /** @var \App\Models\User $user */
    $user = $request->user();
    abort_if($conversation->tenant_id !== $user->tenant_id, 404);

    $conversation->load([
        'messages' => fn ($q) => $q->orderBy('created_at'),
        'lead:id,name,email,score',
    ]);

    return Inertia::render('Client/Conversations/Show', [
        'conversation' => $conversation,
    ]);
}
```

- [ ] **Step 4: Run to verify they pass**

Run: `php artisan test tests/Feature/Client/ConversationsShowTest.php`
Expected: 4 PASS.

- [ ] **Step 5: Run full suite**

Run: `php artisan test`
Expected: 146 passed (was 142; +4).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Client/ConversationController.php tests/Feature/Client/ConversationsShowTest.php
git commit -m "$(cat <<'EOF'
feat: implement Conversations show controller

Loads ordered messages and the linked lead. Returns 404 on cross-tenant
access (rather than 403) to avoid leaking the existence of another
tenant's resources.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Archive / unarchive actions (TDD)

**Files:**
- Modify: `tests/Feature/Client/ConversationsShowTest.php`
- Modify: `app/Http/Controllers/Client/ConversationController.php`

- [ ] **Step 1: Add the failing tests**

Append to `tests/Feature/Client/ConversationsShowTest.php` inside the class:

```php
public function test_archive_flips_status_to_archived(): void
{
    $this->actingAsTenantUser();
    $conv = Conversation::factory()->for($this->tenant)->create(['status' => 'active']);

    $this->put("/conversations/{$conv->id}/archive")
        ->assertRedirect('/conversations');

    $this->assertSame('archived', $conv->fresh()->status);
}

public function test_unarchive_flips_archived_back_to_active(): void
{
    $this->actingAsTenantUser();
    $conv = Conversation::factory()->for($this->tenant)->archived()->create();

    $this->put("/conversations/{$conv->id}/unarchive")
        ->assertRedirect('/conversations');

    $this->assertSame('active', $conv->fresh()->status);
}

public function test_archive_404s_on_cross_tenant(): void
{
    $this->actingAsTenantUser();
    $other = Tenant::factory()->create();
    $conv = Conversation::factory()->for($other)->create();

    $this->put("/conversations/{$conv->id}/archive")->assertNotFound();
    $this->assertSame('active', $conv->fresh()->status);
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Feature/Client/ConversationsShowTest.php`
Expected: 3 new tests FAIL with HTTP 501.

- [ ] **Step 3: Implement `archive` and `unarchive`**

Replace the `archive` and `unarchive` methods in `ConversationController`:

```php
public function archive(Request $request, Conversation $conversation): \Illuminate\Http\RedirectResponse
{
    /** @var \App\Models\User $user */
    $user = $request->user();
    abort_if($conversation->tenant_id !== $user->tenant_id, 404);

    $conversation->update(['status' => 'archived']);

    return redirect()->route('client.conversations.index')
        ->with('success', 'Conversation archived.');
}

public function unarchive(Request $request, Conversation $conversation): \Illuminate\Http\RedirectResponse
{
    /** @var \App\Models\User $user */
    $user = $request->user();
    abort_if($conversation->tenant_id !== $user->tenant_id, 404);

    $conversation->update(['status' => 'active']);

    return redirect()->route('client.conversations.index')
        ->with('success', 'Conversation restored.');
}
```

Update the imports/return types: change the method signatures' return type from `Response` to `\Illuminate\Http\RedirectResponse`. Remove the now-unused `Illuminate\Http\Response` import if nothing else uses it.

- [ ] **Step 4: Run to verify they pass**

Run: `php artisan test tests/Feature/Client/ConversationsShowTest.php`
Expected: 7 PASS (4 from Task 6 + 3 new).

- [ ] **Step 5: Run full suite**

Run: `php artisan test`
Expected: 149 passed (was 146; +3).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Client/ConversationController.php tests/Feature/Client/ConversationsShowTest.php
git commit -m "$(cat <<'EOF'
feat: implement archive and unarchive actions

PUT verbs to match the Leads pattern. Both redirect back to the index
with a flash message. 404 on cross-tenant access.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: Export action (TDD)

**Files:**
- Modify: `tests/Feature/Client/ConversationsShowTest.php`
- Modify: `app/Http/Controllers/Client/ConversationController.php`

- [ ] **Step 1: Add the failing tests**

Append to `tests/Feature/Client/ConversationsShowTest.php` inside the class:

```php
public function test_export_streams_txt_with_correct_filename(): void
{
    $this->actingAsTenantUser();
    $conv = Conversation::factory()->for($this->tenant)->create();
    Message::factory()->for($conv)->create([
        'content' => 'Hi there', 'created_at' => '2026-05-03 10:32:14',
    ]);

    $response = $this->get("/conversations/{$conv->id}/export");
    $response->assertOk();
    $response->assertHeader('content-type', 'text/plain; charset=UTF-8');
    $this->assertStringContainsString(
        "attachment; filename=conversation-{$conv->id}.txt",
        $response->headers->get('content-disposition'),
    );
}

public function test_export_contains_visitor_and_assistant_lines_in_order(): void
{
    $this->actingAsTenantUser();
    $conv = Conversation::factory()->for($this->tenant)->create();
    Message::factory()->for($conv)->create([
        'content' => 'What services?', 'created_at' => '2026-05-03 10:32:14',
    ]);
    Message::factory()->for($conv)->fromAssistant()->create([
        'content' => 'We build websites', 'created_at' => '2026-05-03 10:32:18',
    ]);

    $body = $this->get("/conversations/{$conv->id}/export")->streamedContent();

    $this->assertStringContainsString('[10:32:14] Visitor: What services?', $body);
    $this->assertStringContainsString('[10:32:18] Assistant: We build websites', $body);
    $this->assertLessThan(
        strpos($body, 'We build websites'),
        strpos($body, 'What services?'),
    );
}

public function test_export_404s_on_cross_tenant(): void
{
    $this->actingAsTenantUser();
    $other = Tenant::factory()->create();
    $conv = Conversation::factory()->for($other)->create();

    $this->get("/conversations/{$conv->id}/export")->assertNotFound();
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test tests/Feature/Client/ConversationsShowTest.php`
Expected: 3 new tests FAIL with HTTP 501.

- [ ] **Step 3: Implement `export`**

Replace the `export` method in `ConversationController`:

```php
public function export(Request $request, Conversation $conversation): StreamedResponse
{
    /** @var \App\Models\User $user */
    $user = $request->user();
    abort_if($conversation->tenant_id !== $user->tenant_id, 404);

    $filename = "conversation-{$conversation->id}.txt";

    return response()->streamDownload(function () use ($conversation): void {
        echo "Conversation #{$conversation->id}\n";
        echo 'Started: '.$conversation->created_at->format('Y-m-d H:i:s')."\n";
        echo "Status: {$conversation->status}\n\n";

        foreach ($conversation->messages()->orderBy('created_at')->get() as $message) {
            $role = $message->role === 'assistant' ? 'Assistant' : 'Visitor';
            $time = $message->created_at->format('H:i:s');
            echo "[{$time}] {$role}: {$message->content}\n";
        }
    }, $filename, ['Content-Type' => 'text/plain; charset=UTF-8']);
}
```

- [ ] **Step 4: Run to verify they pass**

Run: `php artisan test tests/Feature/Client/ConversationsShowTest.php`
Expected: 10 PASS (7 from Tasks 6+7 + 3 new).

- [ ] **Step 5: Run full suite**

Run: `php artisan test`
Expected: 152 passed (was 149; +3).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Client/ConversationController.php tests/Feature/Client/ConversationsShowTest.php
git commit -m "$(cat <<'EOF'
feat: implement transcript export to plain text

Streams the .txt directly without a temp file via response()->streamDownload.
Filename is conversation-{id}.txt. Format is bracketed timestamps with
Visitor/Assistant prefixes; copy-pasteable into a CRM note.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: Vue Index page + sidebar nav

**Files:**
- Create: `resources/js/Pages/Client/Conversations/Index.vue`
- Modify: `resources/js/Layouts/ClientLayout.vue`

- [ ] **Step 1: Add the sidebar nav entry**

In `resources/js/Layouts/ClientLayout.vue`, find the `navigation` array (around line 49) and add a Conversations entry between Widget and Knowledge Base. Also add `MessageCircle` to the lucide imports at the top of `<script setup>`:

```js
// existing imports may already include some of these — add MessageCircle if absent
import { LayoutDashboard, MessageSquare, MessageCircle, BookOpen, Users, BarChart3, CreditCard } from 'lucide-vue-next'
```

```js
const navigation = [
  { name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
  { name: 'Widget', href: '/widget-settings', icon: MessageSquare },
  { name: 'Conversations', href: '/conversations', icon: MessageCircle },
  { name: 'Knowledge Base', href: '/knowledge', icon: BookOpen },
  { name: 'Leads', href: '/leads', icon: Users },
  { name: 'Analytics', href: '/analytics', icon: BarChart3 },
  { name: 'Billing', href: '/billing', icon: CreditCard },
]
```

- [ ] **Step 2: Create the Index page**

Create `resources/js/Pages/Client/Conversations/Index.vue`:

```vue
<script setup>
import { ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { MessageCircle, Search } from 'lucide-vue-next'
import ClientLayout from '@/Layouts/ClientLayout.vue'

const props = defineProps({
  conversations: Object,
  filters: Object,
})

const status = ref(props.filters?.status ?? '')
const from = ref(props.filters?.from ?? '')
const to = ref(props.filters?.to ?? '')
const hasLead = ref(Boolean(props.filters?.has_lead))

function applyFilters() {
  router.get('/conversations', {
    status: status.value || undefined,
    from: from.value || undefined,
    to: to.value || undefined,
    has_lead: hasLead.value ? 1 : undefined,
  }, { preserveState: true, preserveScroll: true })
}

function statusBadgeClass(s) {
  return {
    active: 'bg-green-500/10 text-green-700 dark:text-green-400',
    closed: 'bg-gray-500/10 text-gray-700 dark:text-gray-400',
    archived: 'bg-orange-500/10 text-orange-700 dark:text-orange-400',
  }[s] || 'bg-gray-500/10 text-gray-700'
}

function relativeTime(iso) {
  const ms = Date.now() - new Date(iso).getTime()
  const min = Math.round(ms / 60000)
  if (min < 1) return 'just now'
  if (min < 60) return `${min}m ago`
  const hr = Math.round(min / 60)
  if (hr < 24) return `${hr}h ago`
  const d = Math.round(hr / 24)
  return `${d}d ago`
}

function truncate(text, n = 80) {
  if (!text) return ''
  return text.length > n ? text.slice(0, n) + '…' : text
}
</script>

<template>
  <Head title="Conversations" />
  <ClientLayout>
    <div class="px-4 py-6 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Conversations</h1>
      </div>

      <!-- Filter strip -->
      <div class="mt-6 flex flex-wrap items-end gap-4 rounded-lg border bg-card p-4">
        <div>
          <label class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Status</label>
          <select v-model="status" @change="applyFilters" class="mt-1 block rounded-md border bg-background px-3 py-2 text-sm">
            <option value="">Active + Closed</option>
            <option value="active">Active</option>
            <option value="closed">Closed</option>
            <option value="archived">Archived</option>
            <option value="all">All</option>
          </select>
        </div>
        <div>
          <label class="text-xs font-medium uppercase tracking-wide text-muted-foreground">From</label>
          <input v-model="from" @change="applyFilters" type="date" class="mt-1 block rounded-md border bg-background px-3 py-2 text-sm" />
        </div>
        <div>
          <label class="text-xs font-medium uppercase tracking-wide text-muted-foreground">To</label>
          <input v-model="to" @change="applyFilters" type="date" class="mt-1 block rounded-md border bg-background px-3 py-2 text-sm" />
        </div>
        <label class="ml-2 flex cursor-pointer items-center gap-2 text-sm">
          <input v-model="hasLead" @change="applyFilters" type="checkbox" class="rounded border" />
          Has lead
        </label>
      </div>

      <!-- Empty state -->
      <div v-if="conversations.data.length === 0" class="mt-12 flex flex-col items-center text-center">
        <MessageCircle class="h-12 w-12 text-muted-foreground" />
        <p class="mt-4 text-sm text-muted-foreground">No conversations yet — add the widget to your site to start collecting them.</p>
        <Link href="/widget-settings" class="mt-2 text-sm text-primary underline">Go to widget settings</Link>
      </div>

      <!-- Table -->
      <div v-else class="mt-6 overflow-hidden rounded-lg border bg-card">
        <table class="min-w-full divide-y">
          <thead class="bg-muted/40">
            <tr class="text-left text-xs font-medium uppercase tracking-wide text-muted-foreground">
              <th class="px-4 py-3">When</th>
              <th class="px-4 py-3">Last message</th>
              <th class="px-4 py-3">Status</th>
              <th class="px-4 py-3">Lead</th>
              <th class="px-4 py-3 text-right">Messages</th>
            </tr>
          </thead>
          <tbody class="divide-y">
            <tr
              v-for="conv in conversations.data"
              :key="conv.id"
              @click="router.visit(`/conversations/${conv.id}`)"
              class="cursor-pointer hover:bg-muted/40"
            >
              <td class="whitespace-nowrap px-4 py-3 text-sm" :title="conv.created_at">{{ relativeTime(conv.created_at) }}</td>
              <td class="px-4 py-3 text-sm text-muted-foreground">{{ truncate(conv.latest_message?.content) || '—' }}</td>
              <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadgeClass(conv.status)">{{ conv.status }}</span></td>
              <td class="px-4 py-3 text-sm">
                <span v-if="conv.lead" class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">✓ captured</span>
                <span v-else class="text-muted-foreground">—</span>
              </td>
              <td class="px-4 py-3 text-right text-sm">{{ conv.messages_count }}</td>
            </tr>
          </tbody>
        </table>

        <!-- Pagination footer -->
        <div class="flex items-center justify-between border-t bg-muted/20 px-4 py-3 text-sm">
          <span class="text-muted-foreground">
            Showing {{ conversations.from }}–{{ conversations.to }} of {{ conversations.total }}
          </span>
          <div class="flex gap-1">
            <Link
              v-for="link in conversations.links"
              :key="link.label"
              :href="link.url || ''"
              v-html="link.label"
              :class="[
                'rounded px-3 py-1 text-sm',
                link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted',
                !link.url && 'pointer-events-none text-muted-foreground/50',
              ]"
              preserve-state
              preserve-scroll
            />
          </div>
        </div>
      </div>
    </div>
  </ClientLayout>
</template>
```

- [ ] **Step 3: Build assets**

Run: `npm run build`
Expected: Vite build completes with no errors. Output includes `Conversations/Index.vue`.

- [ ] **Step 4: Manual smoke test**

Start the server if not running: `php artisan serve --port=8001` (already running per the dev session above).
In a browser at `http://127.0.0.1:8001/login`, log in as `test@example.com` / `password`. Click "Conversations" in the sidebar. Verify:
- Page renders with the filter strip
- Existing conversations from dev DB appear in the table
- Clicking a row navigates to `/conversations/{id}` (will 501 / abort until Task 10 — that's fine)
- Filters change the URL query string and the visible rows

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Client/Conversations/Index.vue resources/js/Layouts/ClientLayout.vue public/build
git commit -m "$(cat <<'EOF'
feat: add Conversations index page + sidebar nav

Filter strip with status / date range / has-lead. Server-paginated
table at 25/page with row-click navigation and a tooltip-on-hover for
absolute timestamps.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: Vue Show page (transcript + sidebar)

**Files:**
- Create: `resources/js/Pages/Client/Conversations/Show.vue`

- [ ] **Step 1: Create the Show page**

Create `resources/js/Pages/Client/Conversations/Show.vue`:

```vue
<script setup>
import { computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { ArrowLeft, Download, Archive, ArchiveRestore, ExternalLink } from 'lucide-vue-next'
import ClientLayout from '@/Layouts/ClientLayout.vue'

const props = defineProps({
  conversation: Object,
})

function statusBadgeClass(s) {
  return {
    active: 'bg-green-500/10 text-green-700 dark:text-green-400',
    closed: 'bg-gray-500/10 text-gray-700 dark:text-gray-400',
    archived: 'bg-orange-500/10 text-orange-700 dark:text-orange-400',
  }[s] || 'bg-gray-500/10 text-gray-700'
}

function leadScoreLabel(score) {
  if (score >= 70) return { label: 'Hot', cls: 'text-red-600 dark:text-red-400' }
  if (score >= 40) return { label: 'Warm', cls: 'text-orange-600 dark:text-orange-400' }
  return { label: 'Cold', cls: 'text-blue-600 dark:text-blue-400' }
}

const leadBadge = computed(() =>
  props.conversation.lead ? leadScoreLabel(props.conversation.lead.score ?? 0) : null,
)

function formatTime(iso) {
  return new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

function formatDateTime(iso) {
  return new Date(iso).toLocaleString('en-GB', {
    year: 'numeric', month: 'short', day: '2-digit',
    hour: '2-digit', minute: '2-digit',
  })
}

function truncateMid(s, n = 16) {
  if (!s || s.length <= n) return s
  const half = Math.floor(n / 2)
  return s.slice(0, half) + '…' + s.slice(-half)
}

function archive() {
  if (!confirm('Archive this conversation?')) return
  router.put(`/conversations/${props.conversation.id}/archive`)
}

function unarchive() {
  if (!confirm('Restore this conversation?')) return
  router.put(`/conversations/${props.conversation.id}/unarchive`)
}
</script>

<template>
  <Head :title="`Conversation #${conversation.id}`" />
  <ClientLayout>
    <div class="px-4 py-6 sm:px-6 lg:px-8">
      <Link href="/conversations" class="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft class="h-4 w-4" />
        Back to conversations
      </Link>

      <div class="mt-4 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Conversation #{{ conversation.id }}</h1>
        <span class="rounded-full px-3 py-1 text-xs font-medium" :class="statusBadgeClass(conversation.status)">{{ conversation.status }}</span>
      </div>

      <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[1fr_22rem]">
        <!-- Transcript column -->
        <div class="rounded-lg border bg-card p-6">
          <div v-if="conversation.messages.length === 0" class="py-12 text-center text-sm text-muted-foreground">
            No messages in this conversation.
          </div>
          <div v-else class="space-y-4">
            <div
              v-for="m in conversation.messages"
              :key="m.id"
              class="flex"
              :class="m.role === 'assistant' ? 'justify-end' : 'justify-start'"
            >
              <div class="max-w-[75%]">
                <div
                  class="rounded-2xl px-4 py-2 text-sm"
                  :class="m.role === 'assistant' ? 'bg-primary/10 text-foreground' : 'bg-muted text-foreground'"
                >
                  <p class="whitespace-pre-wrap">{{ m.content }}</p>
                </div>
                <p class="mt-1 px-1 text-xs text-muted-foreground" :class="m.role === 'assistant' ? 'text-right' : 'text-left'" :title="m.created_at">
                  {{ formatTime(m.created_at) }}
                </p>
              </div>
            </div>
          </div>
        </div>

        <!-- Right sidebar -->
        <div class="space-y-4">
          <!-- Metadata card -->
          <div class="rounded-lg border bg-card p-4">
            <h3 class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Metadata</h3>
            <dl class="mt-3 space-y-2 text-sm">
              <div>
                <dt class="text-muted-foreground">Started</dt>
                <dd>{{ formatDateTime(conversation.created_at) }}</dd>
              </div>
              <div>
                <dt class="text-muted-foreground">Session</dt>
                <dd class="font-mono text-xs" :title="conversation.session_id">{{ truncateMid(conversation.session_id, 24) }}</dd>
              </div>
              <div v-if="conversation.metadata?.ip">
                <dt class="text-muted-foreground">IP</dt>
                <dd>{{ conversation.metadata.ip }}</dd>
              </div>
              <div v-if="conversation.metadata?.user_agent">
                <dt class="text-muted-foreground">User agent</dt>
                <dd class="break-words text-xs">{{ conversation.metadata.user_agent }}</dd>
              </div>
            </dl>
          </div>

          <!-- Lead card -->
          <div v-if="conversation.lead" class="rounded-lg border bg-card p-4">
            <h3 class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Lead</h3>
            <div class="mt-3 space-y-1 text-sm">
              <p class="font-medium">{{ conversation.lead.name || '(unnamed)' }}</p>
              <p class="text-muted-foreground">{{ conversation.lead.email }}</p>
              <p>
                Score: {{ conversation.lead.score ?? 0 }}
                <span v-if="leadBadge" class="ml-1 font-medium" :class="leadBadge.cls">{{ leadBadge.label }}</span>
              </p>
              <Link :href="`/leads/${conversation.lead.id}`" class="mt-2 inline-flex items-center gap-1 text-sm text-primary hover:underline">
                View lead <ExternalLink class="h-3 w-3" />
              </Link>
            </div>
          </div>

          <!-- Actions card -->
          <div class="rounded-lg border bg-card p-4">
            <h3 class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Actions</h3>
            <div class="mt-3 space-y-2">
              <a
                :href="`/conversations/${conversation.id}/export`"
                class="flex w-full items-center justify-center gap-2 rounded-md border px-3 py-2 text-sm hover:bg-muted"
              >
                <Download class="h-4 w-4" />
                Export transcript
              </a>
              <button
                v-if="conversation.status !== 'archived'"
                @click="archive"
                class="flex w-full items-center justify-center gap-2 rounded-md border px-3 py-2 text-sm hover:bg-muted"
              >
                <Archive class="h-4 w-4" />
                Archive
              </button>
              <button
                v-else
                @click="unarchive"
                class="flex w-full items-center justify-center gap-2 rounded-md border px-3 py-2 text-sm hover:bg-muted"
              >
                <ArchiveRestore class="h-4 w-4" />
                Unarchive
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </ClientLayout>
</template>
```

- [ ] **Step 2: Build assets**

Run: `npm run build`
Expected: Vite build completes with no errors.

- [ ] **Step 3: Manual smoke test**

In the browser, click any conversation row in `/conversations`. Verify:
- Transcript renders with visitor messages on left, assistant on right
- Right sidebar shows Metadata, optional Lead card, Actions
- "Export transcript" downloads a `.txt` file
- "Archive" button shows confirm dialog → on accept, status flips and you redirect back to the index
- The archived conversation is hidden from the default index, visible under `?status=archived`
- "Unarchive" works on archived conversations

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Client/Conversations/Show.vue public/build
git commit -m "$(cat <<'EOF'
feat: add Conversations show page with transcript and sidebar

Transcript bubbles (visitor left, assistant right) with whitespace-pre
content. Sticky right sidebar shows metadata, optional lead card with
a deep link to the lead detail page, and the export/archive actions.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: Final verification + ROADMAP flip

**Files:**
- Modify: `ROADMAP.md`

- [ ] **Step 1: Update ROADMAP**

Find the M8.3 line in `ROADMAP.md` (under "Phase 8: Client Dashboard" → "M8.3-M8.9 Dashboard Pages") and change:

```
- M8.3: ❌ Conversation list & detail — **not built** (no controller, route, or Vue page)
```

to:

```
- M8.3: ✅ Conversation list & detail (`/conversations` + `/conversations/{id}`)
```

Leave the section's `**Status**: Partial` line unchanged — M8.9 (Team management) is still not built, so the section is still partial.

- [ ] **Step 2: Run the full test suite one more time**

Run: `php artisan test`
Expected: 152 passed (= 127 baseline + 6 model + 9 index + 4 show + 3 archive + 3 export).

- [ ] **Step 3: Confirm route count**

Run: `php artisan route:list --path=conversations`
Expected: exactly 5 rows for the conversation routes.

- [ ] **Step 4: End-to-end browser walk**

In a logged-in browser session at `http://127.0.0.1:8001`:
1. Click "Conversations" in the sidebar — list page loads with rows
2. Apply each filter independently (Status / From-To / Has lead) and verify rows narrow
3. Click a row — detail page loads with transcript + sidebar
4. Click "Export transcript" — `.txt` downloads with the right filename and timestamps
5. Click "Archive" → confirm dialog → after accept, conversation disappears from default list
6. Change Status filter to Archived — the archived conversation appears
7. Click into it, click "Unarchive" → back to active

- [ ] **Step 5: Commit**

```bash
git add ROADMAP.md
git commit -m "$(cat <<'EOF'
docs: mark M8.3 Conversation list & detail as shipped in ROADMAP

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Done

- 11 commits, one per task
- 152 PHPUnit tests passing (+25 net)
- M8.3 shipped end-to-end with archive + export
- 4 dead columns dropped from `conversations`

Out-of-scope items the spec explicitly deferred:
- Per-message tokens / retrieved chunks display
- Free-text search across message content
- List-level CSV export
- `ConversationTurn` deepening (architecture candidate #1)
- Tenant-scope global enforcement (candidate #3)
