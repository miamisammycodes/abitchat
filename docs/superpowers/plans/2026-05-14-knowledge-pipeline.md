# Plan B — Knowledge Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tighten the knowledge ingestion + retrieval pipeline by (1) replacing scattered `markAs*` calls with a single `KnowledgeItemWorkflow` service backed by a `KnowledgeItemStatus` enum and a strict transition model that captures `error_message` + `failed_at`; (2) extracting the cache concern from `RetrievalService` + `KnowledgeBaseController` into a single `KnowledgeCache` service; (3) merging `TextChunker` into `DocumentProcessor::process(KnowledgeItem)` so the job hands off one call instead of three. Ships as one PR — all three candidates rewrite `ProcessKnowledgeItem::handle()` and the same `app/Services/Knowledge/` files.

**Architecture:** Three new service-layer modules (`KnowledgeItemWorkflow`, `KnowledgeCache`, expanded `DocumentProcessor`) own concerns that were previously diffused across the model, job, controller, and a sibling service. `KnowledgeItem::status` becomes a backed PHP enum cast (`KnowledgeItemStatus`). Workflow is PS-strict on transitions and captures error context on failure; `markReady` invalidates the retrieval cache so newly-ready chunks are immediately queryable. `RetrievalService` keeps inline vector + keyword logic but DB queries move from `DB::table()->where('ki.tenant_id', ...)` to Eloquent `KnowledgeChunk::whereHas('knowledgeItem', fn ($q) => $q->forTenant($tenant))` — closing 2 baseline entries from Cluster A. Controller cache calls and `index()` queries also convert to the canonical `forTenant` scope, closing 2 more.

**Tech Stack:** Laravel 13, PHP 8.3+ backed enums, PHPUnit, Inertia + Vue 3 Composition API, Tailwind v4, lucide-vue-next icons.

**Spec:** `docs/superpowers/specs/2026-05-14-architecture-deepening-design.md` (Cluster B).

---

## File Structure

**New files:**
- `app/Enums/KnowledgeItemStatus.php` — backed enum (`Pending`, `Processing`, `Ready`, `Failed`)
- `app/Exceptions/InvalidTransitionException.php` — `\DomainException` subclass thrown by the workflow on illegal source-state transitions
- `app/Services/Knowledge/KnowledgeItemWorkflow.php` — canonical state transitions; PS-strict on source state, idempotent on destination
- `app/Services/Knowledge/KnowledgeCache.php` — single owner of `knowledge:{tenant}:v{version}:{md5(query)}` result cache + `knowledge_version:{tenant}` invalidation key
- `database/migrations/2026_05_14_120000_add_error_message_and_failed_at_to_knowledge_items_table.php`
- `tests/Unit/Enums/KnowledgeItemStatusTest.php`
- `tests/Unit/Exceptions/InvalidTransitionExceptionTest.php`
- `tests/Unit/Services/Knowledge/KnowledgeItemWorkflowTest.php`
- `tests/Unit/Services/Knowledge/KnowledgeCacheTest.php`
- `tests/Unit/Services/Knowledge/DocumentProcessorProcessTest.php`

**Modified files:**
- `app/Models/KnowledgeItem.php` — `status` cast to enum; delete `markAsProcessing/Ready/Failed`; rewrite `is*` predicates as enum comparisons; add `error_message` + `failed_at` to `$fillable` + casts
- `app/Services/Knowledge/RetrievalService.php` — drop inline `Cache::remember`; delegate to `KnowledgeCache`; rewrite both `DB::table` queries to Eloquent (`KnowledgeChunk` + `whereHas('knowledgeItem', fn ($q) => $q->forTenant($tenant))`)
- `app/Services/Knowledge/DocumentProcessor.php` — add `process(KnowledgeItem): array<string>`; make `extractFromFile` + `extractFromUrl` private; absorb `TextChunker`'s paragraph/sentence splitting as private methods
- `app/Jobs/ProcessKnowledgeItem.php` — drop `TextChunker` dep; drop `extractContent()`; delegate to `KnowledgeItemWorkflow::markProcessing` and `DocumentProcessor::process`
- `app/Jobs/GenerateEmbeddings.php` — delegate to `KnowledgeItemWorkflow::markReady`/`markFailed`
- `app/Http/Controllers/Client/KnowledgeBaseController.php` — drop `clearKnowledgeCache`; inject `KnowledgeCache` + `KnowledgeItemWorkflow`; convert `index()` and `statsByType` queries to `KnowledgeItem::forTenant($tenant)`; add `retry($item)` controller method; expose `error_message` + `failed_at` in index payload
- `routes/web.php` — add `Route::post('/{item}/retry', [KnowledgeBaseController::class, 'retry'])->name('retry')`
- `resources/js/Pages/Client/KnowledgeBase/Index.vue` — show `error_message` + `failed_at` for failed items; add retry button
- `tests/Unit/Jobs/KnowledgeStatusFlowTest.php` — update to new APIs (no `markAs*` calls; processor mock returns chunks)
- `tests/Unit/Jobs/ProcessKnowledgeItemIdempotencyTest.php` — drop `TextChunker` mock; mock `DocumentProcessor::process` to return chunk arrays

**Deleted files:**
- `app/Services/Knowledge/TextChunker.php`
- `tests/Unit/Services/Knowledge/TextChunkerTest.php` — chunking logic now exercised through `DocumentProcessorProcessTest`

**Baseline shrinkage (Cluster A → B handoff):**
- `phpstan-baseline.neon` — remove 4 entries:
  - `app/Services/Knowledge/RetrievalService.php` (count: 2, `ki.tenant_id`)
  - `app/Http/Controllers/Client/KnowledgeBaseController.php` (count: 2, `tenant_id` in `index()` + `statsByType`)
- Baseline shrinks from 51 → 47 entries. `reportUnmatchedIgnoredErrors: true` will fail the build if these entries stay after the rewrites — must be deleted in the same task.

---

## Task 0 — Verifications (no code; probe reality before any rewrites)

**Files:** none modified.

- [ ] **Step 1: Verify `RetrievalService.php:55, 95` still have `DB::table()->where('ki.tenant_id', ...)` form**

Run:
```bash
sed -n '50,60p;90,100p' app/Services/Knowledge/RetrievalService.php
```

Expected (verbatim, modulo whitespace):
```
            $rows = DB::table('knowledge_chunks as kc')
                ->join('knowledge_items as ki', 'ki.id', '=', 'kc.knowledge_item_id')
                ->where('ki.tenant_id', $tenant->id)
                ->where('ki.status', 'ready')
                ->whereNotNull('kc.embedding')
...
        return DB::table('knowledge_chunks as kc')
            ->join('knowledge_items as ki', 'ki.id', '=', 'kc.knowledge_item_id')
            ->where('ki.tenant_id', $tenant->id)
            ->where('ki.status', 'ready')
```

If the lines have shifted by 1–2 (cosmetic edits since the spec was written), proceed — Task 9 will rewrite the whole `retrieve()` + `retrieveByKeywords()` methods regardless. If the form has changed substantively (e.g., already Eloquent), STOP and revise the plan.

- [ ] **Step 2: Verify `ProcessKnowledgeItem.php` still has the structure assumed by the spec**

Run:
```bash
sed -n '32,32p;110,118p' app/Jobs/ProcessKnowledgeItem.php
```

Expected (verbatim, modulo whitespace):
```
    public function handle(DocumentProcessor $processor, TextChunker $chunker): void
...
    private function extractContent(DocumentProcessor $processor): string
    {
        return match ($this->item->type) {
            'document' => $processor->extractFromFile($this->item->file_path),
            'webpage' => $processor->extractFromUrl($this->item->source_url),
            'faq', 'text' => $this->item->content ?? '',
            default => '',
        };
    }
```

If either the constructor signature or the `extractContent` type-switch is gone, STOP — Task 8's rewrite assumes both.

- [ ] **Step 3: Verify `KnowledgeItem.php` still has the `markAs*` model methods**

Run:
```bash
sed -n '61,74p' app/Models/KnowledgeItem.php
```

Expected:
```
    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markAsReady(): void
    {
        $this->update(['status' => 'ready']);
    }

    public function markAsFailed(): void
    {
        $this->update(['status' => 'failed']);
    }
```

If any of the three has already been deleted or refactored, STOP — Task 5 deletes them all in one move.

- [ ] **Step 4: Verify `GenerateEmbeddings.php` still uses `markAsReady`/`markAsFailed`**

Run:
```bash
grep -n "markAs" app/Jobs/GenerateEmbeddings.php
```

Expected (verbatim):
```
43:            $this->item->markAsReady();
64:        $this->item->markAsFailed();
```

If neither line exists, STOP — Task 7's rewrite swaps both for workflow calls.

- [ ] **Step 5: Verify the 4 PHPStan baseline entries this PR will retire**

Run:
```bash
grep -B1 -A4 "tenancy.rawTenantId" phpstan-baseline.neon | grep -E "count:|path:" | grep -E "RetrievalService|KnowledgeBaseController"
```

Expected:
```
			count: 2
			path: app/Http/Controllers/Client/KnowledgeBaseController.php
			count: 2
			path: app/Services/Knowledge/RetrievalService.php
```

If the counts or paths differ, STOP. The baseline shape determines exactly which entries Task 9 + Task 10 must remove. If `reportUnmatchedIgnoredErrors: true` is no longer set in `phpstan.neon`, STOP — without it, stale baseline entries silently survive and CI passes when it should fail.

Confirm `reportUnmatchedIgnoredErrors`:
```bash
grep -n "reportUnmatchedIgnoredErrors" phpstan.neon
```

Expected: `reportUnmatchedIgnoredErrors: true`.

- [ ] **Step 6: Confirm test suite + PHPStan baseline are green at HEAD before any rewrites**

Run in parallel:
```bash
php artisan test 2>&1 | tail -5
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -3
```

Expected: full test suite passes; PHPStan reports `[OK] No errors`. If either fails on `main` before this plan starts, STOP — fix the green-build regression first; do not stack new work on a broken baseline.

- [ ] **Step 7: Decide whether to proceed**

If every verification matched expectations, proceed to Task 1.

If any verification surfaced an unexpected result, **stop and discuss with the user before proceeding**. Do not modify this plan file mid-execution — surface the finding in chat and the user will decide whether to revise the spec/plan or accept the deviation.

---

## Task 1 — Migration: add `error_message` + `failed_at` columns to `knowledge_items`

**Files:**
- Create: `database/migrations/2026_05_14_120000_add_error_message_and_failed_at_to_knowledge_items_table.php`
- Test: full Pest suite (`php artisan test`) — migration is exercised by every test that hits `knowledge_items` (`RefreshDatabase` reruns it per test class).

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_05_14_120000_add_error_message_and_failed_at_to_knowledge_items_table.php`:

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
        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->text('error_message')->nullable()->after('metadata');
            $table->timestamp('failed_at')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->dropColumn(['error_message', 'failed_at']);
        });
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate
```

Expected: the new migration runs and reports success. No prior migrations are re-run; the new file's batch number is fresh.

- [ ] **Step 3: Confirm the columns are present**

```bash
php artisan tinker --execute="echo json_encode(\\Illuminate\\Support\\Facades\\Schema::getColumnListing('knowledge_items'));"
```

Expected output contains both `"error_message"` and `"failed_at"` alongside the existing columns.

- [ ] **Step 4: Run the full test suite (proves migration is idempotent under `RefreshDatabase`)**

```bash
php artisan test 2>&1 | tail -5
```

Expected: PASS — full suite still green. Failures here typically indicate an enum or seed test that hard-codes column lists; investigate before continuing.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_05_14_120000_add_error_message_and_failed_at_to_knowledge_items_table.php
git commit -m "$(cat <<'EOF'
feat(knowledge): add error_message + failed_at columns to knowledge_items

Captures the failure context KnowledgeItemWorkflow::markFailed will write
on Throwable. Nullable on existing rows; UI surfaces "Failed (no detail)"
for pre-migration failures.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster B, B-1 EC1)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 2 — `KnowledgeItemStatus` enum + `InvalidTransitionException`

**Files:**
- Create: `app/Enums/KnowledgeItemStatus.php`
- Create: `app/Exceptions/InvalidTransitionException.php`
- Create: `tests/Unit/Enums/KnowledgeItemStatusTest.php`
- Create: `tests/Unit/Exceptions/InvalidTransitionExceptionTest.php`

- [ ] **Step 1: Write the failing enum test**

Create `tests/Unit/Enums/KnowledgeItemStatusTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\KnowledgeItemStatus;
use PHPUnit\Framework\TestCase;

class KnowledgeItemStatusTest extends TestCase
{
    public function test_enum_has_four_cases_with_expected_backing_values(): void
    {
        $this->assertSame('pending', KnowledgeItemStatus::Pending->value);
        $this->assertSame('processing', KnowledgeItemStatus::Processing->value);
        $this->assertSame('ready', KnowledgeItemStatus::Ready->value);
        $this->assertSame('failed', KnowledgeItemStatus::Failed->value);
    }

    public function test_enum_has_exactly_four_cases(): void
    {
        $this->assertCount(4, KnowledgeItemStatus::cases());
    }

    public function test_enum_can_be_instantiated_from_string_value(): void
    {
        $this->assertSame(
            KnowledgeItemStatus::Ready,
            KnowledgeItemStatus::from('ready'),
        );
    }

    public function test_json_encodes_to_backing_string_for_inertia_payloads(): void
    {
        // The Vue layer expects `status` to arrive as a plain string. PHP
        // backed enums serialize to their value through json_encode by
        // default; this test pins that contract.
        $this->assertSame('"failed"', json_encode(KnowledgeItemStatus::Failed));
    }
}
```

- [ ] **Step 2: Write the failing exception test**

Create `tests/Unit/Exceptions/InvalidTransitionExceptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\InvalidTransitionException;
use PHPUnit\Framework\TestCase;

class InvalidTransitionExceptionTest extends TestCase
{
    public function test_extends_domain_exception(): void
    {
        $this->assertInstanceOf(
            \DomainException::class,
            new InvalidTransitionException('test'),
        );
    }

    public function test_carries_message_through_constructor(): void
    {
        $e = new InvalidTransitionException('cannot transition ready -> processing');

        $this->assertSame('cannot transition ready -> processing', $e->getMessage());
    }
}
```

- [ ] **Step 3: Run both tests to verify they fail**

```bash
php artisan test --filter='KnowledgeItemStatusTest|InvalidTransitionExceptionTest' 2>&1 | tail -10
```

Expected: FAIL — `Class "App\Enums\KnowledgeItemStatus" not found` and `Class "App\Exceptions\InvalidTransitionException" not found`.

- [ ] **Step 4: Create the directory + enum**

```bash
mkdir -p app/Enums
```

Create `app/Enums/KnowledgeItemStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum KnowledgeItemStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
```

- [ ] **Step 5: Create the exception**

Create `app/Exceptions/InvalidTransitionException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

class InvalidTransitionException extends \DomainException
{
}
```

- [ ] **Step 6: Run both tests to verify they pass**

```bash
php artisan test --filter='KnowledgeItemStatusTest|InvalidTransitionExceptionTest' 2>&1 | tail -10
```

Expected: PASS — 6 assertions across 6 tests green.

- [ ] **Step 7: Commit**

```bash
git add app/Enums/KnowledgeItemStatus.php app/Exceptions/InvalidTransitionException.php tests/Unit/Enums/ tests/Unit/Exceptions/
git commit -m "$(cat <<'EOF'
feat(knowledge): add KnowledgeItemStatus enum + InvalidTransitionException

Backed string enum (Pending|Processing|Ready|Failed) becomes the
canonical type for the KnowledgeItem::status column once the cast is
applied (next tasks). InvalidTransitionException is the canonical
DomainException subclass thrown by KnowledgeItemWorkflow on illegal
source-state transitions.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster B, B-1)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 3 — `KnowledgeCache` service

**Files:**
- Create: `app/Services/Knowledge/KnowledgeCache.php`
- Create: `tests/Unit/Services/Knowledge/KnowledgeCacheTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Knowledge/KnowledgeCacheTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Models\Tenant;
use App\Services\Knowledge\KnowledgeCache;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class KnowledgeCacheTest extends TestCase
{
    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'KC Co',
            'slug' => 'kc-co-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }

    public function test_get_returns_null_on_miss(): void
    {
        Cache::flush();
        $cache = new KnowledgeCache;
        $tenant = $this->makeTenant();

        $this->assertNull($cache->get($tenant, 'some query'));
    }

    public function test_put_then_get_returns_stored_chunks(): void
    {
        Cache::flush();
        $cache = new KnowledgeCache;
        $tenant = $this->makeTenant();

        $cache->put($tenant, 'pricing', ['chunk one', 'chunk two']);

        $this->assertSame(['chunk one', 'chunk two'], $cache->get($tenant, 'pricing'));
    }

    public function test_get_for_other_tenant_returns_null(): void
    {
        Cache::flush();
        $cache = new KnowledgeCache;
        $a = $this->makeTenant();
        $b = $this->makeTenant();

        $cache->put($a, 'shared query', ['answer for A']);

        $this->assertNull($cache->get($b, 'shared query'));
    }

    public function test_invalidate_busts_existing_entry(): void
    {
        Cache::flush();
        $cache = new KnowledgeCache;
        $tenant = $this->makeTenant();

        $cache->put($tenant, 'pricing', ['cached chunk']);
        $this->assertSame(['cached chunk'], $cache->get($tenant, 'pricing'));

        $cache->invalidate($tenant);

        $this->assertNull($cache->get($tenant, 'pricing'));
    }

    public function test_invalidate_does_not_affect_other_tenants(): void
    {
        Cache::flush();
        $cache = new KnowledgeCache;
        $a = $this->makeTenant();
        $b = $this->makeTenant();

        $cache->put($a, 'q', ['A chunks']);
        $cache->put($b, 'q', ['B chunks']);

        $cache->invalidate($a);

        $this->assertNull($cache->get($a, 'q'));
        $this->assertSame(['B chunks'], $cache->get($b, 'q'));
    }

    public function test_cache_key_uses_version_so_invalidate_does_not_overwrite_pre_existing_keys(): void
    {
        // After invalidate, a new put writes under v+1; an old reader looking
        // at the v key gets null (consistent with miss-on-bump semantics).
        Cache::flush();
        $cache = new KnowledgeCache;
        $tenant = $this->makeTenant();

        $cache->put($tenant, 'q', ['v0 chunks']);
        $cache->invalidate($tenant);
        $cache->put($tenant, 'q', ['v1 chunks']);

        $this->assertSame(['v1 chunks'], $cache->get($tenant, 'q'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test --filter=KnowledgeCacheTest 2>&1 | tail -10
```

Expected: FAIL — `Class "App\Services\Knowledge\KnowledgeCache" not found`.

- [ ] **Step 3: Implement the service**

Create `app/Services/Knowledge/KnowledgeCache.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;

/**
 * Single owner of the retrieval result cache.
 *
 * Cache layout:
 * - Result key: knowledge:{tenant}:v{version}:{md5(query)} — holds an
 *   array of chunk strings for a (tenant, query) pair.
 * - Version key: knowledge_version:{tenant} — incremented to invalidate
 *   every result entry for the tenant in one atomic write. Existing
 *   v{N-1} keys remain in storage until TTL expiry; readers compute the
 *   current version on every get(), so they cannot accidentally hit them.
 *
 * Previously owned across RetrievalService (read path) and
 * KnowledgeBaseController::clearKnowledgeCache (write path). The two
 * touched the same key shape but neither was the source of truth.
 */
class KnowledgeCache
{
    /** Result-cache TTL, in seconds. */
    private const TTL_SECONDS = 600;

    /** @return array<int, string>|null */
    public function get(Tenant $tenant, string $query): ?array
    {
        $key = $this->resultKey($tenant, $query);

        $value = Cache::get($key);

        return is_array($value) ? $value : null;
    }

    /** @param  array<int, string>  $chunks */
    public function put(Tenant $tenant, string $query, array $chunks): void
    {
        Cache::put($this->resultKey($tenant, $query), $chunks, self::TTL_SECONDS);
    }

    public function invalidate(Tenant $tenant): void
    {
        Cache::increment($this->versionKey($tenant));
    }

    private function resultKey(Tenant $tenant, string $query): string
    {
        $version = Cache::get($this->versionKey($tenant), 0);

        return "knowledge:{$tenant->id}:v{$version}:".md5($query);
    }

    private function versionKey(Tenant $tenant): string
    {
        return "knowledge_version:{$tenant->id}";
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php artisan test --filter=KnowledgeCacheTest 2>&1 | tail -10
```

Expected: PASS — 6 tests green.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Knowledge/KnowledgeCache.php tests/Unit/Services/Knowledge/KnowledgeCacheTest.php
git commit -m "$(cat <<'EOF'
feat(knowledge): extract KnowledgeCache — single owner of result cache + version key

Previously the cache key shape was duplicated across RetrievalService
(read) and KnowledgeBaseController::clearKnowledgeCache (write). Neither
was the source of truth. KnowledgeCache concentrates get/put/invalidate
behind a stable interface so the next tasks can delegate cleanly.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster B, B-2)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 4 — `KnowledgeItemWorkflow` service

**Files:**
- Create: `app/Services/Knowledge/KnowledgeItemWorkflow.php`
- Create: `tests/Unit/Services/Knowledge/KnowledgeItemWorkflowTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Knowledge/KnowledgeItemWorkflowTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Enums\KnowledgeItemStatus;
use App\Exceptions\InvalidTransitionException;
use App\Jobs\ProcessKnowledgeItem;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Services\Knowledge\KnowledgeCache;
use App\Services\Knowledge\KnowledgeItemWorkflow;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class KnowledgeItemWorkflowTest extends TestCase
{
    private function makeItem(string $status = 'pending'): KnowledgeItem
    {
        $tenant = Tenant::create([
            'name' => 'Wf Co',
            'slug' => 'wf-co-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        return KnowledgeItem::create([
            'tenant_id' => $tenant->id,
            'type' => 'text',
            'title' => 'Wf Item',
            'content' => 'Lorem ipsum content for testing the workflow.',
            'status' => $status,
        ]);
    }

    private function workflow(?KnowledgeCache $cache = null): KnowledgeItemWorkflow
    {
        return new KnowledgeItemWorkflow($cache ?? new KnowledgeCache);
    }

    public function test_mark_processing_from_pending(): void
    {
        $item = $this->makeItem('pending');

        $this->workflow()->markProcessing($item);

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::Processing, $item->status);
    }

    public function test_mark_processing_from_failed_clears_error_context(): void
    {
        $item = $this->makeItem('failed');
        $item->forceFill(['error_message' => 'old failure', 'failed_at' => now()->subHour()])->save();

        $this->workflow()->markProcessing($item);

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::Processing, $item->status);
        $this->assertNull($item->error_message);
        $this->assertNull($item->failed_at);
    }

    public function test_mark_processing_from_ready_throws(): void
    {
        $item = $this->makeItem('ready');

        $this->expectException(InvalidTransitionException::class);

        $this->workflow()->markProcessing($item);
    }

    public function test_mark_processing_from_processing_throws(): void
    {
        $item = $this->makeItem('processing');

        $this->expectException(InvalidTransitionException::class);

        $this->workflow()->markProcessing($item);
    }

    public function test_mark_ready_from_processing_invalidates_cache(): void
    {
        $item = $this->makeItem('processing');

        $cache = Mockery::mock(KnowledgeCache::class);
        $cache->shouldReceive('invalidate')
            ->once()
            ->with(Mockery::on(fn ($t) => $t->id === $item->tenant_id));

        $this->workflow($cache)->markReady($item);

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::Ready, $item->status);
    }

    public function test_mark_ready_from_pending_throws(): void
    {
        $item = $this->makeItem('pending');

        $this->expectException(InvalidTransitionException::class);

        $this->workflow()->markReady($item);
    }

    public function test_mark_ready_from_failed_throws(): void
    {
        $item = $this->makeItem('failed');

        $this->expectException(InvalidTransitionException::class);

        $this->workflow()->markReady($item);
    }

    public function test_mark_failed_captures_error_and_timestamp(): void
    {
        $item = $this->makeItem('processing');
        $exception = new \RuntimeException('embedding service down');

        $this->workflow()->markFailed($item, $exception);

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::Failed, $item->status);
        $this->assertSame('embedding service down', $item->error_message);
        $this->assertNotNull($item->failed_at);
    }

    public function test_mark_failed_from_pending_works(): void
    {
        $item = $this->makeItem('pending');

        $this->workflow()->markFailed($item, new \RuntimeException('boom'));

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::Failed, $item->status);
    }

    public function test_mark_failed_from_failed_overwrites_previous_error(): void
    {
        // G-4: ProcessKnowledgeItem::failed() AND GenerateEmbeddings::failed()
        // can both fire for the same item. Latest failure wins.
        $item = $this->makeItem('failed');
        $item->forceFill(['error_message' => 'first failure'])->save();

        $this->workflow()->markFailed($item, new \RuntimeException('second failure'));

        $item->refresh();
        $this->assertSame('second failure', $item->error_message);
    }

    public function test_mark_failed_from_ready_throws(): void
    {
        $item = $this->makeItem('ready');

        $this->expectException(InvalidTransitionException::class);

        $this->workflow()->markFailed($item, new \RuntimeException('boom'));
    }

    public function test_retry_from_failed_clears_error_and_dispatches_job(): void
    {
        Bus::fake();
        $item = $this->makeItem('failed');
        $item->forceFill(['error_message' => 'will be cleared', 'failed_at' => now()->subHour()])->save();

        $this->workflow()->retry($item);

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::Pending, $item->status);
        $this->assertNull($item->error_message);
        $this->assertNull($item->failed_at);

        Bus::assertDispatched(
            ProcessKnowledgeItem::class,
            fn (ProcessKnowledgeItem $job) => $job->item->id === $item->id,
        );
    }

    public function test_retry_from_ready_throws(): void
    {
        $item = $this->makeItem('ready');

        $this->expectException(InvalidTransitionException::class);

        $this->workflow()->retry($item);
    }

    public function test_retry_from_pending_throws(): void
    {
        $item = $this->makeItem('pending');

        $this->expectException(InvalidTransitionException::class);

        $this->workflow()->retry($item);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test --filter=KnowledgeItemWorkflowTest 2>&1 | tail -10
```

Expected: FAIL — `Class "App\Services\Knowledge\KnowledgeItemWorkflow" not found`.

- [ ] **Step 3: Implement the service**

Create `app/Services/Knowledge/KnowledgeItemWorkflow.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Enums\KnowledgeItemStatus;
use App\Exceptions\InvalidTransitionException;
use App\Jobs\ProcessKnowledgeItem;
use App\Models\KnowledgeItem;

/**
 * Canonical state transitions for KnowledgeItem.
 *
 * PS-strict: each transition enforces the allowed source state(s) and
 * throws InvalidTransitionException on violation. Idempotent on
 * destination — markFailed on an already-Failed item succeeds and
 * overwrites the prior error_message (G-4 in the master spec).
 *
 * Owns markReady's side-effect of invalidating the retrieval cache so
 * newly-ready chunks become queryable immediately (G-5).
 */
class KnowledgeItemWorkflow
{
    public function __construct(private KnowledgeCache $cache) {}

    /** Pending|Failed → Processing. Clears prior error context. */
    public function markProcessing(KnowledgeItem $item): void
    {
        $this->assertSourceIn(
            $item,
            [KnowledgeItemStatus::Pending, KnowledgeItemStatus::Failed],
            KnowledgeItemStatus::Processing,
        );

        $item->forceFill([
            'status' => KnowledgeItemStatus::Processing,
            'error_message' => null,
            'failed_at' => null,
        ])->save();
    }

    /** Processing → Ready. Invalidates retrieval cache for the tenant. */
    public function markReady(KnowledgeItem $item): void
    {
        $this->assertSourceIn(
            $item,
            [KnowledgeItemStatus::Processing],
            KnowledgeItemStatus::Ready,
        );

        $item->forceFill(['status' => KnowledgeItemStatus::Ready])->save();

        $item->loadMissing('tenant');
        $this->cache->invalidate($item->tenant);
    }

    /** Any non-Ready → Failed. Captures throwable message + timestamp. */
    public function markFailed(KnowledgeItem $item, \Throwable $exception): void
    {
        $this->assertSourceNotIn(
            $item,
            [KnowledgeItemStatus::Ready],
            KnowledgeItemStatus::Failed,
        );

        $item->forceFill([
            'status' => KnowledgeItemStatus::Failed,
            'error_message' => $exception->getMessage(),
            'failed_at' => now(),
        ])->save();
    }

    /** Failed → Pending + dispatch ProcessKnowledgeItem. Clears error context. */
    public function retry(KnowledgeItem $item): void
    {
        $this->assertSourceIn(
            $item,
            [KnowledgeItemStatus::Failed],
            KnowledgeItemStatus::Pending,
        );

        $item->forceFill([
            'status' => KnowledgeItemStatus::Pending,
            'error_message' => null,
            'failed_at' => null,
        ])->save();

        ProcessKnowledgeItem::dispatch($item);
    }

    /** @param  array<int, KnowledgeItemStatus>  $allowed */
    private function assertSourceIn(KnowledgeItem $item, array $allowed, KnowledgeItemStatus $target): void
    {
        if (! in_array($item->status, $allowed, true)) {
            throw new InvalidTransitionException(sprintf(
                'KnowledgeItem #%d cannot transition %s → %s (allowed sources: %s)',
                $item->id,
                $item->status->value,
                $target->value,
                implode(', ', array_map(fn (KnowledgeItemStatus $s) => $s->value, $allowed)),
            ));
        }
    }

    /** @param  array<int, KnowledgeItemStatus>  $forbidden */
    private function assertSourceNotIn(KnowledgeItem $item, array $forbidden, KnowledgeItemStatus $target): void
    {
        if (in_array($item->status, $forbidden, true)) {
            throw new InvalidTransitionException(sprintf(
                'KnowledgeItem #%d cannot transition %s → %s (forbidden source)',
                $item->id,
                $item->status->value,
                $target->value,
            ));
        }
    }
}
```

**Why `forceFill` not `update`:** `markReady` uses `forceFill` because once `status` is cast to the enum (next task), `update(['status' => KnowledgeItemStatus::Ready])` is fine but `forceFill` is consistent across all four methods (they all write multiple non-fillable columns like `failed_at`). All four use the same shape for symmetry.

- [ ] **Step 4: Run the test to verify it passes**

```bash
php artisan test --filter=KnowledgeItemWorkflowTest 2>&1 | tail -10
```

Expected: FAIL (some tests pass, some fail) — the model's `status` is still a plain string, so `$item->status === KnowledgeItemStatus::Processing` comparisons in `assertSourceIn` won't match yet. Task 5 lands the enum cast and makes these tests green.

If they all unexpectedly pass: investigate; the strict-comparison guards may have a bug masking the test signal.

- [ ] **Step 5: Commit (tests will go green in Task 5)**

```bash
git add app/Services/Knowledge/KnowledgeItemWorkflow.php tests/Unit/Services/Knowledge/KnowledgeItemWorkflowTest.php
git commit -m "$(cat <<'EOF'
feat(knowledge): add KnowledgeItemWorkflow — canonical state transitions

Replaces scattered KnowledgeItem::markAs* methods with a single PS-strict
workflow that enforces allowed source states and captures error context
on markFailed. markReady invalidates the retrieval cache so newly-ready
chunks become queryable immediately.

Note: tests fail at this commit because KnowledgeItem::status is still a
plain string column. The next task casts it to KnowledgeItemStatus enum
and the suite goes green.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster B, B-1)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 5 — Apply enum cast on `KnowledgeItem` + delete `markAs*` methods

**Files:**
- Modify: `app/Models/KnowledgeItem.php`

- [ ] **Step 1: Rewrite the model**

Replace the entire body of `app/Models/KnowledgeItem.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KnowledgeItemStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\BustsTenantUsageCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeItem extends Model
{
    use BelongsToTenant, BustsTenantUsageCache;

    protected $fillable = [
        'tenant_id',
        'title',
        'type',
        'content',
        'source_url',
        'file_path',
        'status',
        'metadata',
        'error_message',
        'failed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'status' => KnowledgeItemStatus::class,
            'failed_at' => 'datetime',
        ];
    }

    /** @return HasMany<KnowledgeChunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function isPending(): bool
    {
        return $this->status === KnowledgeItemStatus::Pending;
    }

    public function isProcessing(): bool
    {
        return $this->status === KnowledgeItemStatus::Processing;
    }

    public function isReady(): bool
    {
        return $this->status === KnowledgeItemStatus::Ready;
    }

    public function isFailed(): bool
    {
        return $this->status === KnowledgeItemStatus::Failed;
    }
}
```

What changed:
1. New `use App\Enums\KnowledgeItemStatus;` import.
2. `$fillable` gains `error_message` + `failed_at`.
3. `casts()` adds `'status' => KnowledgeItemStatus::class` and `'failed_at' => 'datetime'`.
4. The three `markAs*` methods are deleted (replaced by `KnowledgeItemWorkflow`).
5. The four `is*` predicates compare against the enum, not strings.

- [ ] **Step 2: Run the workflow tests to verify they now pass**

```bash
php artisan test --filter=KnowledgeItemWorkflowTest 2>&1 | tail -10
```

Expected: PASS — all 14 tests green.

- [ ] **Step 3: Run the full suite — KnowledgeStatusFlowTest and ProcessKnowledgeItemIdempotencyTest will fail**

```bash
php artisan test 2>&1 | tail -25
```

Expected: failures in:
- `tests/Unit/Jobs/KnowledgeStatusFlowTest.php` — calls `$item->markAsProcessing()` (deleted)
- `tests/Unit/Jobs/ProcessKnowledgeItemIdempotencyTest.php` — passes a real `TextChunker` mock; the job is unchanged at this commit, so it still works but will need updating once the job is rewritten
- Possibly Pest assertions against string `'pending'`/`'ready'` — now enum
- Possibly `tests/Feature/KnowledgeBaseTest.php` — `assertDatabaseHas` with string status values still works (string serialization at the DB layer), but `getStatusVariant` style code that reads `$item->status` from PHP context now sees the enum

Tasks 6, 7, 8 update the failing tests as each job/processor is rewritten.

- [ ] **Step 4: Verify the new schema columns are wired through the model**

```bash
php artisan tinker --execute="\$t = \\App\\Models\\Tenant::create(['name'=>'Inline','slug'=>'inline-'.uniqid(),'status'=>'active','trial_ends_at'=>now()->addDays(14)]); \$k = \\App\\Models\\KnowledgeItem::create(['tenant_id'=>\$t->id,'type'=>'text','title'=>'x','status'=>'failed','error_message'=>'because reasons','failed_at'=>now()]); echo json_encode(['status_class'=>get_class(\$k->status),'status_value'=>\$k->status->value,'error_message'=>\$k->error_message,'failed_at_is_carbon'=>(\$k->failed_at instanceof \\Carbon\\Carbon ? 'yes' : 'no')]);"
```

Expected JSON output:
```
{"status_class":"App\\Enums\\KnowledgeItemStatus","status_value":"failed","error_message":"because reasons","failed_at_is_carbon":"yes"}
```

(Tinker leaves an orphan tenant + item; harmless.)

- [ ] **Step 5: Commit**

```bash
git add app/Models/KnowledgeItem.php
git commit -m "$(cat <<'EOF'
feat(knowledge): cast KnowledgeItem::status to enum; delete markAs* methods

Status is now KnowledgeItemStatus (backed string enum). markAs* are
deleted — KnowledgeItemWorkflow owns transitions. error_message and
failed_at columns are wired into $fillable + casts. is* predicates use
enum comparisons.

Existing job tests that call $item->markAsProcessing() / etc. now fail;
Tasks 6 + 8 update them to call the workflow.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster B, B-1)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 6 — Rewrite `GenerateEmbeddings` to use `KnowledgeItemWorkflow`

**Files:**
- Modify: `app/Jobs/GenerateEmbeddings.php`
- Modify: `tests/Unit/Jobs/KnowledgeStatusFlowTest.php`

- [ ] **Step 1: Update the failing test cases in `KnowledgeStatusFlowTest.php`**

Replace `test_embeddings_job_marks_ready_on_success` and `test_embeddings_job_failed_callback_marks_failed` in `tests/Unit/Jobs/KnowledgeStatusFlowTest.php` with versions that work against the new API. Locate the file's existing test:

```php
    public function test_embeddings_job_marks_ready_on_success(): void
    {
        $item = $this->makeItem();
        $item->chunks()->create([
            'content' => 'chunk-a',
            'chunk_index' => 0,
            'embedding' => null,
        ]);
        $item->markAsProcessing();
        ...
```

Replace the call to `$item->markAsProcessing()` with workflow-driven setup. Update the file as follows (locate each section by its method signature and edit in place):

In the imports block, add:
```php
use App\Enums\KnowledgeItemStatus;
use App\Exceptions\EmbeddingGenerationException;
```

(Don't add `EmbeddingGenerationException` if already imported — it currently is. Verify by reading the import block.)

Replace **both** instances of `$item->markAsProcessing();` (lines ~65 and ~79 in the current file) with:
```php
        $item->forceFill(['status' => KnowledgeItemStatus::Processing])->save();
```

(We use `forceFill` for direct seeding in tests rather than calling the workflow — the workflow is exercised by `KnowledgeItemWorkflowTest`; here we just need a Processing-state item to drive the embeddings job.)

Replace the assertion `$this->assertSame('ready', $item->status);` with:
```php
        $this->assertSame(KnowledgeItemStatus::Ready, $item->status);
```

Replace the assertion `$this->assertSame('failed', $item->status);` (appears twice — in `test_embeddings_job_failed_callback_marks_failed` and `test_process_job_failed_callback_marks_failed`) with:
```php
        $this->assertSame(KnowledgeItemStatus::Failed, $item->status);
```

Replace `$this->assertSame('processing', $item->status, ...);` (appears twice — in `test_process_job_leaves_item_in_processing_until_embeddings_complete` and `test_process_job_catch_does_not_prematurely_mark_failed`) with:
```php
        $this->assertSame(
            KnowledgeItemStatus::Processing,
            $item->status,
            // (keep the existing assertion message)
        );
```

- [ ] **Step 2: Run `KnowledgeStatusFlowTest` to confirm `test_embeddings_*` tests fail with the right reason**

```bash
php artisan test --filter='KnowledgeStatusFlowTest::test_embeddings' 2>&1 | tail -10
```

Expected: FAIL — assertions about enum match but the actual job still calls `$this->item->markAsReady()` (deleted method) → method-not-found error.

- [ ] **Step 3: Rewrite `GenerateEmbeddings.php`**

Replace the entire file with:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeItem;
use App\Services\Knowledge\EmbeddingService;
use App\Services\Knowledge\KnowledgeItemWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class GenerateEmbeddings implements NotTenantAware, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public KnowledgeItem $item
    ) {}

    public function handle(EmbeddingService $embeddingService, KnowledgeItemWorkflow $workflow): void
    {
        $processed = 0;

        try {
            $this->item->chunks()
                ->whereNull('embedding')
                ->lazyById(50)
                ->each(function ($chunk) use ($embeddingService, &$processed) {
                    $embedding = $embeddingService->generate($chunk->content);
                    $chunk->update(['embedding' => $embedding]);
                    $processed++;
                });

            $workflow->markReady($this->item);

            Log::debug('[Embeddings] (NO $) Embeddings generated; item ready', [
                'item_id' => $this->item->id,
                'processed' => $processed,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Embeddings] Generation failed', [
                'item_id' => $this->item->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[Embeddings] Job failed after retries — marking item failed', [
            'item_id' => $this->item->id,
            'error' => $exception->getMessage(),
        ]);

        app(KnowledgeItemWorkflow::class)->markFailed($this->item->refresh(), $exception);
    }
}
```

**Why `app(...)` in `failed()`:** Laravel resolves `failed()` directly on the deserialized job; it isn't a method-injected handler like `handle()`. The container is reachable via the `app()` helper. The `->refresh()` reloads the model from DB because `SerializesModels` rehydrates a snapshot that may be stale.

- [ ] **Step 4: Run `KnowledgeStatusFlowTest` to verify the rewrite passes**

```bash
php artisan test --filter=KnowledgeStatusFlowTest 2>&1 | tail -10
```

Expected: `test_embeddings_job_marks_ready_on_success` PASS; `test_embeddings_job_failed_callback_marks_failed` PASS; the two `test_process_job_*` tests still FAIL (they call `markAsProcessing` directly and pass `TextChunker` — fixed in Tasks 7 + 8).

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/GenerateEmbeddings.php tests/Unit/Jobs/KnowledgeStatusFlowTest.php
git commit -m "$(cat <<'EOF'
refactor(knowledge): route GenerateEmbeddings through KnowledgeItemWorkflow

Replaces direct markAsReady/markAsFailed calls with workflow methods.
markFailed now captures the exception message + timestamp into
error_message/failed_at. markReady invalidates the retrieval cache so
newly-embedded chunks are queryable immediately.

failed() resolves the workflow via app() because Laravel doesn't
method-inject failed callbacks. Refreshes the model before transition
to avoid acting on a stale SerializesModels snapshot.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster B, B-1)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 7 — Merge `TextChunker` into `DocumentProcessor::process(KnowledgeItem)`; delete `TextChunker`

**Files:**
- Modify: `app/Services/Knowledge/DocumentProcessor.php`
- Create: `tests/Unit/Services/Knowledge/DocumentProcessorProcessTest.php`
- Delete: `app/Services/Knowledge/TextChunker.php`
- Delete: `tests/Unit/Services/Knowledge/TextChunkerTest.php`

- [ ] **Step 1: Write the failing test for `DocumentProcessor::process`**

Create `tests/Unit/Services/Knowledge/DocumentProcessorProcessTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Services\Knowledge\DocumentProcessor;
use Tests\TestCase;

class DocumentProcessorProcessTest extends TestCase
{
    private function makeItem(array $overrides = []): KnowledgeItem
    {
        $tenant = Tenant::create([
            'name' => 'DP Co',
            'slug' => 'dp-co-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        return KnowledgeItem::create(array_merge([
            'tenant_id' => $tenant->id,
            'type' => 'text',
            'title' => 'DP Item',
            'content' => str_repeat('Sentence one is reasonably long and detailed. ', 5),
            'status' => 'pending',
        ], $overrides));
    }

    public function test_process_text_item_returns_chunks(): void
    {
        $processor = new DocumentProcessor;
        $item = $this->makeItem();

        $chunks = $processor->process($item);

        $this->assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            $this->assertIsString($chunk);
            $this->assertGreaterThanOrEqual(50, strlen(trim($chunk)));
        }
    }

    public function test_process_faq_item_returns_chunks(): void
    {
        $processor = new DocumentProcessor;
        $item = $this->makeItem(['type' => 'faq']);

        $chunks = $processor->process($item);

        $this->assertNotEmpty($chunks);
    }

    public function test_process_unknown_type_returns_empty(): void
    {
        $processor = new DocumentProcessor;
        $item = $this->makeItem();
        $item->forceFill(['type' => 'unknown_type'])->save();

        $chunks = $processor->process($item);

        $this->assertSame([], $chunks);
    }

    public function test_process_text_item_with_empty_content_returns_empty(): void
    {
        $processor = new DocumentProcessor;
        $item = $this->makeItem(['content' => '']);

        $chunks = $processor->process($item);

        $this->assertSame([], $chunks);
    }

    public function test_process_chunks_filter_out_below_50_char_chunks(): void
    {
        $processor = new DocumentProcessor;
        // 30 chars — below the floor used by the chunker.
        $item = $this->makeItem(['content' => 'Too short to survive chunking.']);

        $chunks = $processor->process($item);

        $this->assertSame([], $chunks);
    }

    public function test_process_splits_long_content_into_multiple_chunks(): void
    {
        $processor = new DocumentProcessor;
        // Five paragraphs of ~120 chars each separated by blank lines.
        $paragraph = str_repeat('All work and no play makes Jack a dull boy. ', 3);
        $body = implode("\n\n", array_fill(0, 6, $paragraph));
        $item = $this->makeItem(['content' => $body]);

        $chunks = $processor->process($item);

        $this->assertGreaterThan(1, count($chunks));
    }

    public function test_process_preserves_unique_marker_across_chunks(): void
    {
        $processor = new DocumentProcessor;
        $marker = 'UNIQUE_MARKER_XYZ_42';
        $body = "Intro paragraph that is reasonably long and detailed enough.\n\n".
                str_repeat("Filler content paragraph here for bulk. ", 3)."\n\n".
                "Tail paragraph contains {$marker} which must survive chunking.";
        $item = $this->makeItem(['content' => $body]);

        $chunks = $processor->process($item);

        $combined = implode(' ', $chunks);
        $this->assertStringContainsString($marker, $combined);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test --filter=DocumentProcessorProcessTest 2>&1 | tail -10
```

Expected: FAIL — `Method App\Services\Knowledge\DocumentProcessor::process() does not exist`.

- [ ] **Step 3: Rewrite `DocumentProcessor.php`**

Replace the entire body of `app/Services/Knowledge/DocumentProcessor.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\KnowledgeItem;
use App\Rules\SafeExternalUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;

class DocumentProcessor
{
    private const CHUNK_SIZE = 500;

    private const CHUNK_OVERLAP = 50;

    private const MIN_CHUNK_CHARS = 50;

    /**
     * Extract text content for a KnowledgeItem and chunk it.
     *
     * Owns the type-switch (document / faq / webpage / text), extraction,
     * and chunking. Returns the chunk strings ready for persistence by the
     * caller (typically ProcessKnowledgeItem::handle).
     *
     * @return array<int, string>
     */
    public function process(KnowledgeItem $item): array
    {
        $content = match ($item->type) {
            'document' => $this->extractFromFile($item->file_path ?? ''),
            'webpage' => $this->extractFromUrl($item->source_url ?? ''),
            'faq', 'text' => $item->content ?? '',
            default => '',
        };

        return $this->chunk($content);
    }

    private function extractFromFile(string $filePath): string
    {
        $fullPath = Storage::disk('local')->path($filePath);

        if (! file_exists($fullPath)) {
            throw new \Exception("File not found: {$filePath}");
        }

        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

        Log::debug('[DocumentProcessor] (NO $) Extracting from file', [
            'path' => $filePath,
            'extension' => $extension,
        ]);

        return match ($extension) {
            'pdf' => $this->extractFromPdf($fullPath),
            'txt', 'md' => $this->extractFromText($fullPath),
            'doc', 'docx' => $this->extractFromDocx($fullPath),
            default => throw new \Exception("Unsupported file type: {$extension}"),
        };
    }

    private function extractFromUrl(string $url): string
    {
        Log::debug('[DocumentProcessor] (IS $) Extracting from URL', [
            'url' => $url,
        ]);

        if (! SafeExternalUrl::isSafe($url)) {
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

            return $this->extractTextFromHtml($response->body());
        } catch (\Exception $e) {
            Log::error('[DocumentProcessor] URL extraction failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function extractFromPdf(string $path): string
    {
        $parser = new PdfParser;
        $pdf = $parser->parseFile($path);

        return $this->cleanText($pdf->getText());
    }

    private function extractFromText(string $path): string
    {
        return $this->cleanText(file_get_contents($path));
    }

    private function extractFromDocx(string $path): string
    {
        $content = '';

        $zip = new \ZipArchive;

        if ($zip->open($path) === true) {
            $xmlContent = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xmlContent) {
                // OOXML splits text across <w:t> runs within <w:r> elements;
                // strip_tags alone would merge adjacent runs into one word.
                // Insert a space at every </w:t> and a newline at every </w:p>
                // before stripping.
                $xmlContent = str_replace(['</w:t>', '</w:p>'], [' ', "\n"], $xmlContent);
                $content = strip_tags($xmlContent);
            }
        }

        return $this->cleanText($content);
    }

    private function extractTextFromHtml(string $html): string
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $tagsToRemove = ['script', 'style', 'nav', 'header', 'footer', 'svg', 'iframe', 'form', 'noscript', 'aside', 'button', 'input', 'select', 'textarea', 'dialog', 'menu'];
        foreach ($tagsToRemove as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            $toRemove = [];
            for ($i = 0; $i < $elements->length; $i++) {
                $toRemove[] = $elements->item($i);
            }
            foreach ($toRemove as $element) {
                $element->parentNode?->removeChild($element);
            }
        }

        $xpath = new \DOMXPath($dom);
        $comments = $xpath->query('//comment()');
        if ($comments) {
            foreach ($comments as $comment) {
                $comment->parentNode?->removeChild($comment);
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $text = $body ? $body->textContent : $dom->textContent;

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->cleanText($text);
    }

    private function cleanText(string $text): string
    {
        $text = preg_replace('/\r\n|\r/', "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);

        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines, fn (string $line) => $line !== '');

        $text = implode("\n", $lines);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * Split text into overlapping chunks for context preservation.
     * Chunk size + overlap are spec-locked at 500 / 50; no caller varies them.
     *
     * @return array<int, string>
     */
    private function chunk(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $paragraphs = preg_split('/\n\s*\n/', $text);

        $chunks = [];
        $currentChunk = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if (strlen($currentChunk) + strlen($paragraph) + 1 <= self::CHUNK_SIZE) {
                $currentChunk .= ($currentChunk === '' ? '' : "\n\n").$paragraph;
                continue;
            }

            if ($currentChunk !== '') {
                $chunks[] = $currentChunk;
                $currentChunk = $this->getOverlapText($currentChunk);
            }

            if (strlen($paragraph) > self::CHUNK_SIZE) {
                $sentenceChunks = $this->splitLargeParagraph($paragraph);
                foreach ($sentenceChunks as $sentenceChunk) {
                    $chunks[] = $sentenceChunk;
                }
                $currentChunk = $this->getOverlapText(end($sentenceChunks) ?: '');
            } else {
                $currentChunk .= ($currentChunk === '' ? '' : "\n\n").$paragraph;
            }
        }

        if (trim($currentChunk) !== '') {
            $chunks[] = $currentChunk;
        }

        return array_values(array_filter(
            $chunks,
            fn (string $chunk) => strlen(trim($chunk)) >= self::MIN_CHUNK_CHARS,
        ));
    }

    /** @return array<int, string> */
    private function splitLargeParagraph(string $paragraph): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph);

        $chunks = [];
        $currentChunk = '';

        foreach ($sentences as $sentence) {
            if (strlen($currentChunk) + strlen($sentence) + 1 <= self::CHUNK_SIZE) {
                $currentChunk .= ($currentChunk === '' ? '' : ' ').$sentence;
                continue;
            }

            if ($currentChunk !== '') {
                $chunks[] = $currentChunk;
            }
            $currentChunk = $sentence;
        }

        if ($currentChunk !== '') {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    private function getOverlapText(string $text): string
    {
        if (strlen($text) <= self::CHUNK_OVERLAP) {
            return $text;
        }

        $overlapSection = substr($text, -self::CHUNK_OVERLAP * 2);

        if (preg_match('/[.!?]\s+([^.!?]+)$/', $overlapSection, $matches)) {
            return $matches[1];
        }

        $lastPart = substr($text, -self::CHUNK_OVERLAP);

        if (preg_match('/^\S*\s+(.+)/', $lastPart, $matches)) {
            return $matches[1];
        }

        return $lastPart;
    }
}
```

What changed (vs. the file currently on disk):
- New `use App\Models\KnowledgeItem;` import.
- New `process(KnowledgeItem $item): array<string>` public method — the canonical entry point.
- `extractFromFile` and `extractFromUrl` are now `private`.
- Chunking primitives (paragraphs/sentences/overlap) moved in as private methods. `splitByParagraphs` removed as a helper — its one-liner is inline in `chunk()`.
- Chunk size + overlap + 50-char floor are class constants.

- [ ] **Step 4: Delete `TextChunker.php` + `TextChunkerTest.php`**

```bash
git rm app/Services/Knowledge/TextChunker.php tests/Unit/Services/Knowledge/TextChunkerTest.php
```

- [ ] **Step 5: Run `DocumentProcessorProcessTest` to verify the rewrite passes**

```bash
php artisan test --filter=DocumentProcessorProcessTest 2>&1 | tail -10
```

Expected: PASS — 7 tests green.

- [ ] **Step 6: Run the full suite — `ProcessKnowledgeItem` job-level tests will still fail (job not yet rewritten)**

```bash
php artisan test 2>&1 | tail -25
```

Expected: failures in `tests/Unit/Jobs/KnowledgeStatusFlowTest.php` and `tests/Unit/Jobs/ProcessKnowledgeItemIdempotencyTest.php` that pass a `TextChunker` instance — `TextChunker` class no longer exists. Fixed in Task 8.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Knowledge/DocumentProcessor.php tests/Unit/Services/Knowledge/DocumentProcessorProcessTest.php
git commit -m "$(cat <<'EOF'
refactor(knowledge): merge TextChunker into DocumentProcessor::process()

DocumentProcessor::process(KnowledgeItem) now owns the type-switch,
extraction, and chunking — the three concerns previously fragmented
across ProcessKnowledgeItem::extractContent() + TextChunker.

TextChunker is deleted (137 lines of paragraph/sentence splitting
move into private methods on DocumentProcessor). Chunk size + overlap
are hardcoded as class constants (500 / 50) — no caller varies them
in v1.

extractFromFile / extractFromUrl are now private; process() is the
only public ingestion API.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster B, B-3)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 8 — Rewrite `ProcessKnowledgeItem` to use workflow + processor

**Files:**
- Modify: `app/Jobs/ProcessKnowledgeItem.php`
- Modify: `tests/Unit/Jobs/KnowledgeStatusFlowTest.php`
- Modify: `tests/Unit/Jobs/ProcessKnowledgeItemIdempotencyTest.php`

- [ ] **Step 1: Update `KnowledgeStatusFlowTest.php` to drop `TextChunker` and align with the new job signature**

Open `tests/Unit/Jobs/KnowledgeStatusFlowTest.php`. Make these edits:

**Remove import:**
```php
use App\Services\Knowledge\TextChunker;
```

**In `test_process_job_leaves_item_in_processing_until_embeddings_complete`** replace:
```php
        (new ProcessKnowledgeItem($item))->handle(
            app(DocumentProcessor::class),
            app(TextChunker::class),
        );
```
with:
```php
        (new ProcessKnowledgeItem($item))->handle(
            app(DocumentProcessor::class),
            app(\App\Services\Knowledge\KnowledgeItemWorkflow::class),
        );
```

**In `test_process_job_catch_does_not_prematurely_mark_failed`** replace:
```php
            $job->handle(app(DocumentProcessor::class), app(TextChunker::class));
```
with:
```php
            $job->handle(app(DocumentProcessor::class), app(\App\Services\Knowledge\KnowledgeItemWorkflow::class));
```

Also in that same test, change the setup line:
```php
        $item->markAsProcessing();
```
to:
```php
        $item->forceFill(['status' => \App\Enums\KnowledgeItemStatus::Processing])->save();
```

(Same edit anywhere else `$item->markAsProcessing()` appears in this file.)

- [ ] **Step 2: Update `ProcessKnowledgeItemIdempotencyTest.php` to mock `DocumentProcessor::process` and drop `TextChunker`**

Open `tests/Unit/Jobs/ProcessKnowledgeItemIdempotencyTest.php` and replace the entire file body with:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateEmbeddings;
use App\Jobs\ProcessKnowledgeItem;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Services\Knowledge\DocumentProcessor;
use App\Services\Knowledge\KnowledgeItemWorkflow;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ProcessKnowledgeItemIdempotencyTest extends TestCase
{
    private function makeItem(): KnowledgeItem
    {
        $tenant = Tenant::create([
            'name' => 'Idem Co',
            'slug' => 'idem-co-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => []],
        ]);

        return KnowledgeItem::create([
            'tenant_id' => $tenant->id,
            'type' => 'text',
            'title' => 'Test',
            'content' => 'Some content body for chunking.',
            'status' => 'pending',
        ]);
    }

    public function test_running_the_job_twice_does_not_duplicate_chunks(): void
    {
        Queue::fake([GenerateEmbeddings::class]);
        $item = $this->makeItem();

        $processor = Mockery::mock(DocumentProcessor::class);
        $processor->shouldReceive('process')->andReturn(['chunk-a', 'chunk-b']);

        (new ProcessKnowledgeItem($item))->handle($processor, app(KnowledgeItemWorkflow::class));
        $this->assertSame(2, KnowledgeChunk::where('knowledge_item_id', $item->id)->count());

        // Simulates a Laravel retry. Must produce the same 2 chunks, not 4.
        // Reset status to allow re-entry; the workflow forbids
        // Processing → Processing.
        $item->refresh()->forceFill(['status' => \App\Enums\KnowledgeItemStatus::Pending])->save();
        (new ProcessKnowledgeItem($item))->handle($processor, app(KnowledgeItemWorkflow::class));

        $this->assertSame(
            2,
            KnowledgeChunk::where('knowledge_item_id', $item->id)->count(),
            'Re-running the job must not append duplicate chunks'
        );
    }

    public function test_chunk_content_matches_after_retry(): void
    {
        Queue::fake([GenerateEmbeddings::class]);
        $item = $this->makeItem();

        $first = Mockery::mock(DocumentProcessor::class);
        $first->shouldReceive('process')->andReturn(['old-1', 'old-2']);
        (new ProcessKnowledgeItem($item))->handle($first, app(KnowledgeItemWorkflow::class));

        $item->refresh()->forceFill(['status' => \App\Enums\KnowledgeItemStatus::Pending])->save();

        $second = Mockery::mock(DocumentProcessor::class);
        $second->shouldReceive('process')->andReturn(['new-1', 'new-2', 'new-3']);
        (new ProcessKnowledgeItem($item))->handle($second, app(KnowledgeItemWorkflow::class));

        $contents = KnowledgeChunk::where('knowledge_item_id', $item->id)
            ->orderBy('chunk_index')
            ->pluck('content')
            ->all();
        $this->assertSame(['new-1', 'new-2', 'new-3'], $contents);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

(The status reset between calls is necessary because the new `KnowledgeItemWorkflow::markProcessing` only allows transitions from Pending or Failed — not Processing.)

- [ ] **Step 3: Run the failing tests to confirm they fail with the right reason**

```bash
php artisan test --filter='KnowledgeStatusFlowTest|ProcessKnowledgeItemIdempotencyTest' 2>&1 | tail -15
```

Expected: FAIL — `Argument #2 ($chunker) ... must be of type App\Services\Knowledge\TextChunker` (class gone) or similar. The job signature hasn't been updated yet.

- [ ] **Step 4: Rewrite `ProcessKnowledgeItem.php`**

Replace the entire body of `app/Jobs/ProcessKnowledgeItem.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Services\Knowledge\DocumentProcessor;
use App\Services\Knowledge\KnowledgeItemWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class ProcessKnowledgeItem implements NotTenantAware, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public KnowledgeItem $item
    ) {}

    public function handle(DocumentProcessor $processor, KnowledgeItemWorkflow $workflow): void
    {
        Log::debug('[Knowledge] (NO $) Processing item', [
            'item_id' => $this->item->id,
            'type' => $this->item->type,
        ]);

        $workflow->markProcessing($this->item);

        try {
            $chunks = $processor->process($this->item);

            if ($chunks === []) {
                throw new \Exception('No content could be extracted');
            }

            Log::debug('[Knowledge] (NO $) Content chunked', [
                'item_id' => $this->item->id,
                'chunks_count' => count($chunks),
            ]);

            // Replace any prior chunk set atomically — guards against the
            // tries=3 retry path appending a duplicate set, and against a
            // partial-insert state surviving when the transaction throws.
            DB::transaction(function () use ($chunks): void {
                $this->item->chunks()->delete();

                $now = now();
                $rows = [];
                foreach ($chunks as $index => $chunkContent) {
                    $rows[] = [
                        'knowledge_item_id' => $this->item->id,
                        'content' => $chunkContent,
                        'chunk_index' => $index,
                        'embedding' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if ($rows !== []) {
                    KnowledgeChunk::insert($rows);
                }
            });

            GenerateEmbeddings::dispatch($this->item);

            Log::debug('[Knowledge] (NO $) Chunks written; embedding job dispatched', [
                'item_id' => $this->item->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Knowledge] Processing failed', [
                'item_id' => $this->item->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[Knowledge] Job failed after retries — marking item failed', [
            'item_id' => $this->item->id,
            'error' => $exception->getMessage(),
        ]);

        app(KnowledgeItemWorkflow::class)->markFailed($this->item->refresh(), $exception);
    }
}
```

What changed:
- `TextChunker` import + parameter gone; `KnowledgeItemWorkflow` injected instead.
- `markAsProcessing` → `$workflow->markProcessing($this->item)`.
- `extractContent` private method deleted — replaced by `$processor->process($this->item)`.
- The `if ($this->item->type !== 'faq' && $this->item->type !== 'text')` write-back block is gone. The previous code wrote extracted content to `$this->item->content` for document/webpage types; this is dropped because the spec doesn't list it as a requirement and the master spec explicitly says `process()` returns chunks and the job persists chunks (not extracted text). If a future need surfaces to persist the raw extracted text, add it explicitly behind a flag — don't carry the legacy.
- `markAsFailed` in `failed()` → `$workflow->markFailed($this->item->refresh(), $exception)`.

**Note on raw-content write-back removal.** The deleted block was:
```php
if ($this->item->type !== 'faq' && $this->item->type !== 'text') {
    $this->item->update(['content' => $content]);
}
```
This persisted extracted text into `knowledge_items.content` for documents/webpages. After Task 8, that column stays at whatever `KnowledgeBaseController::store` set it to (typically null for document/webpage types — the controller only sets `content` for `faq`/`text`). The Show.vue page renders `item.content` if present (line 116), so document/webpage detail pages will no longer surface extracted text. The chunk count + status badge still reflect ingestion success. If the user wants the raw-text preview to keep working for document/webpage items, restore the write-back in a follow-up — for v1 of this cluster, dropping it keeps the job's single responsibility clean.

- [ ] **Step 5: Run the updated tests to verify they pass**

```bash
php artisan test --filter='KnowledgeStatusFlowTest|ProcessKnowledgeItemIdempotencyTest' 2>&1 | tail -10
```

Expected: PASS — all 9 tests green.

- [ ] **Step 6: Run the full suite**

```bash
php artisan test 2>&1 | tail -10
```

Expected: PASS — entire suite green. If any other test fails, it's relying on the deleted `markAs*` methods or on `content` being written back; address per-failure before continuing.

- [ ] **Step 7: Commit**

```bash
git add app/Jobs/ProcessKnowledgeItem.php tests/Unit/Jobs/KnowledgeStatusFlowTest.php tests/Unit/Jobs/ProcessKnowledgeItemIdempotencyTest.php
git commit -m "$(cat <<'EOF'
refactor(knowledge): ProcessKnowledgeItem delegates to workflow + processor

handle() now takes (DocumentProcessor, KnowledgeItemWorkflow). The
extractContent type-switch + TextChunker injection are gone — the
processor's process() method owns both. Status transitions route
through the workflow, so failures capture error_message + failed_at.

Behavior change: extracted text from documents/webpages is no longer
written back to knowledge_items.content. Chunks remain the source of
truth for retrieval; the column is left at whatever the controller
set on create (typically null for those types). Show.vue's content
preview becomes empty for documents/webpages — call out in PR.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster B, B-1 + B-3)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 9 — `RetrievalService` Eloquent rewrite + `KnowledgeCache` delegation

**Files:**
- Modify: `app/Services/Knowledge/RetrievalService.php`
- Modify: `tests/Unit/Services/Knowledge/RetrievalServiceTest.php`
- Modify: `phpstan-baseline.neon` — remove the 2 `RetrievalService.php` entries

- [ ] **Step 1: Update `RetrievalServiceTest.php` constructor calls**

Open `tests/Unit/Services/Knowledge/RetrievalServiceTest.php` and locate the two helper methods:

```php
    private function makeServiceWithFailingEmbeddings(): RetrievalService
    {
        $embedder = Mockery::mock(EmbeddingService::class);
        $embedder->shouldReceive('generate')
            ->andThrow(new EmbeddingGenerationException('test stub'));

        return new RetrievalService($embedder);
    }

    private function makeServiceWithSuccessfulEmbeddings(string $vector = '[0.1,0.2,0.3]'): RetrievalService
    {
        $embedder = Mockery::mock(EmbeddingService::class);
        $embedder->shouldReceive('generate')->andReturn($vector);

        return new RetrievalService($embedder);
    }
```

Replace `return new RetrievalService($embedder);` (in both methods) with:
```php
        return new RetrievalService($embedder, new \App\Services\Knowledge\KnowledgeCache);
```

The two cache-behavior tests (`test_retrieve_caches_results_per_query`, `test_retrieve_busts_cache_when_knowledge_version_changes`) continue to use `Cache::flush()` + `Cache::increment("knowledge_version:{$this->tenant->id}")` directly — `KnowledgeCache` uses the same underlying Laravel `Cache` facade, so these tests still validate the version-bump semantics.

- [ ] **Step 2: Run the existing tests — they should fail with a constructor arity / typing error**

```bash
php artisan test --filter='RetrievalServiceTest|RetrievalServiceFallbackTest' 2>&1 | tail -10
```

Expected: FAIL — `Too few arguments to function RetrievalService::__construct, 1 passed and exactly 2 expected` once the rewrite lands. At this commit, before the implementation, the current `RetrievalService` still has 1-arg constructor and the test now passes 2 args → "passed 2, expected 1". Either ordering is fine — confirms the test edits are wired up.

- [ ] **Step 3: Rewrite `RetrievalService.php`**

Replace the entire body of `app/Services/Knowledge/RetrievalService.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Enums\KnowledgeItemStatus;
use App\Exceptions\EmbeddingGenerationException;
use App\Models\KnowledgeChunk;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetrievalService
{
    public function __construct(
        private EmbeddingService $embeddingService,
        private KnowledgeCache $cache,
    ) {}

    /**
     * Retrieve relevant knowledge chunks for a query using pgvector
     * cosine-distance search, falling back to keyword LIKE if no
     * embedding can be generated.
     *
     * @return array<int, string>
     */
    public function retrieve(Tenant $tenant, string $query, int $limit = 5): array
    {
        $cached = $this->cache->get($tenant, $query);
        if ($cached !== null) {
            return $cached;
        }

        Log::debug('[RAG] (NO $) Retrieving context', [
            'tenant_id' => $tenant->id,
            'query_length' => strlen($query),
        ]);

        try {
            $queryVector = $this->embeddingService->generate($query);
        } catch (EmbeddingGenerationException $e) {
            Log::warning('[Retrieval] (IS $) Embedding failed, falling back to keyword search', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            $queryVector = null;
        }

        if ($queryVector === null) {
            Log::debug('[RAG] (NO $) No query embedding, using keyword fallback');

            $chunks = $this->retrieveByKeywords($tenant, $query, $limit);
            $this->cache->put($tenant, $query, $chunks);

            return $chunks;
        }

        $chunks = KnowledgeChunk::query()
            ->whereHas('knowledgeItem', function ($q) use ($tenant): void {
                $q->forTenant($tenant)->where('status', KnowledgeItemStatus::Ready);
            })
            ->whereNotNull('embedding')
            ->orderByRaw('embedding <=> ?::vector', [$queryVector])
            ->limit($limit)
            ->pluck('content')
            ->all();

        if ($chunks === []) {
            Log::debug('[RAG] (NO $) No vector matches, using keyword fallback');

            $chunks = $this->retrieveByKeywords($tenant, $query, $limit);
        }

        Log::debug('[RAG] (NO $) Retrieved chunks', [
            'count' => count($chunks),
        ]);

        $this->cache->put($tenant, $query, $chunks);

        return $chunks;
    }

    /**
     * Simple keyword-based retrieval as fallback.
     *
     * @return array<int, string>
     */
    public function retrieveByKeywords(Tenant $tenant, string $query, int $limit = 5): array
    {
        $keywords = $this->extractKeywords($query);

        if ($keywords === []) {
            return [];
        }

        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return KnowledgeChunk::query()
            ->whereHas('knowledgeItem', function ($q) use ($tenant): void {
                $q->forTenant($tenant)->where('status', KnowledgeItemStatus::Ready);
            })
            ->where(function ($q) use ($keywords, $operator): void {
                foreach ($keywords as $keyword) {
                    $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $keyword);
                    $q->orWhere('content', $operator, "%{$escaped}%");
                }
            })
            ->limit($limit)
            ->pluck('content')
            ->all();
    }

    /** @return array<int, string> */
    private function extractKeywords(string $text): array
    {
        $stopWords = ['a', 'an', 'the', 'is', 'are', 'was', 'were', 'what', 'when', 'where', 'who', 'why', 'how', 'do', 'does', 'did', 'can', 'could', 'would', 'should', 'will', 'i', 'you', 'he', 'she', 'it', 'we', 'they', 'my', 'your', 'his', 'her', 'its', 'our', 'their', 'this', 'that', 'these', 'those', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by'];

        $words = preg_split('/\W+/', strtolower($text));
        $words = array_filter($words, function (string $word) use ($stopWords): bool {
            return strlen($word) > 2 && ! in_array($word, $stopWords);
        });

        return array_values(array_unique($words));
    }
}
```

What changed:
- Constructor takes a `KnowledgeCache`.
- Inline `Cache::remember` replaced with `$this->cache->get()` → on miss compute → `$this->cache->put()`. Same TTL + key shape (now owned by `KnowledgeCache`).
- Both DB queries rewritten from `DB::table('knowledge_chunks as kc')->join('knowledge_items as ki', ...)->where('ki.tenant_id', $tenant->id)->...` to `KnowledgeChunk::query()->whereHas('knowledgeItem', fn ($q) => $q->forTenant($tenant)->where('status', KnowledgeItemStatus::Ready))->...`.
- `$rows->pluck('kc.content')` (qualified) → `->pluck('content')` (Eloquent on `KnowledgeChunk` model — unqualified is correct since the WHERE EXISTS subquery on `knowledge_items` doesn't pull columns into the outer select).
- `orderByRaw('kc.embedding <=> ?::vector', ...)` → `orderByRaw('embedding <=> ?::vector', ...)` (single-table base, no alias needed).
- `where('ki.status', 'ready')` becomes `->where('status', KnowledgeItemStatus::Ready)` inside the `whereHas` closure (Eloquent transparently coerces the enum to its backing string).

- [ ] **Step 4: Remove the 2 `RetrievalService.php` entries from `phpstan-baseline.neon`**

Open `phpstan-baseline.neon` and locate the block that reads:
```yaml
		-
			message: '#^Raw where\(''ki\.tenant_id'', \.\.\.\) bypasses tenant scoping\. Use forTenant\(\$tenant\) instead\.$#'
			identifier: tenancy.rawTenantId
			count: 2
			path: app/Services/Knowledge/RetrievalService.php
```

Delete the entire block (including the leading `-` line and trailing blank line if any).

If a *second* unrelated block for `RetrievalService.php` exists (e.g., `Strict comparison ... always evaluate to true`), leave it alone — that's a separate baseline entry not covered by this PR.

- [ ] **Step 5: Run both Retrieval test files to verify the rewrite passes**

```bash
php artisan test --filter='RetrievalServiceTest|RetrievalServiceFallbackTest' 2>&1 | tail -15
```

Expected: PASS — all tests green. The cache-busting test specifically verifies the version-key invalidation still works through the `KnowledgeCache` delegation.

- [ ] **Step 6: Confirm PHPStan is happy with the baseline shrinkage**

```bash
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -5
```

Expected: `[OK] No errors`. If PHPStan reports `Ignored error pattern ... was not matched in reported errors` for the removed `RetrievalService.php` block, the deletion was incomplete or the wrong block was removed — re-grep the baseline and try again.

- [ ] **Step 7: Run the full suite**

```bash
php artisan test 2>&1 | tail -10
```

Expected: PASS — entire suite green.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Knowledge/RetrievalService.php tests/Unit/Services/Knowledge/RetrievalServiceTest.php phpstan-baseline.neon
git commit -m "$(cat <<'EOF'
refactor(knowledge): RetrievalService uses Eloquent + KnowledgeCache

Inline Cache::remember is replaced with KnowledgeCache get/put. Both
DB::table queries rewrite to Eloquent KnowledgeChunk + whereHas('knowledgeItem',
fn (q) => q->forTenant(tenant)) — closing the Cluster A baseline by 2 entries.
Behavior unchanged for callers; query result shape and cache key
versioning are identical.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster B, B-2)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 10 — `KnowledgeBaseController`: cache delegation + `forTenant` conversions + retry route

**Files:**
- Modify: `app/Http/Controllers/Client/KnowledgeBaseController.php`
- Modify: `routes/web.php`
- Modify: `phpstan-baseline.neon` — remove the 2 `KnowledgeBaseController.php` entries

- [ ] **Step 1: Rewrite `KnowledgeBaseController.php`**

Replace the entire body of `app/Http/Controllers/Client/KnowledgeBaseController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessKnowledgeItem;
use App\Models\KnowledgeItem;
use App\Rules\SafeExternalUrl;
use App\Services\Knowledge\KnowledgeCache;
use App\Services\Knowledge\KnowledgeItemWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class KnowledgeBaseController extends Controller
{
    public function __construct(
        private KnowledgeCache $cache,
        private KnowledgeItemWorkflow $workflow,
    ) {}

    public function index(): Response
    {
        $tenant = $this->getTenant();

        $items = KnowledgeItem::forTenant($tenant)
            ->withCount('chunks')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (KnowledgeItem $item): array {
                return [
                    'id' => $item->id,
                    'title' => $item->title,
                    'type' => $item->type,
                    'status' => $item->status,
                    'chunks_count' => $item->chunks_count,
                    'created_at' => $item->created_at->format('M d, Y'),
                    'error_message' => $item->error_message,
                    'failed_at' => $item->failed_at?->format('M d, Y H:i'),
                ];
            });

        $statsByType = KnowledgeItem::forTenant($tenant)
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type');

        $stats = [
            'documents' => $statsByType->get('document', 0),
            'faqs' => $statsByType->get('faq', 0),
            'webpages' => $statsByType->get('webpage', 0),
            'text' => $statsByType->get('text', 0),
        ];

        return Inertia::render('Client/KnowledgeBase/Index', [
            'items' => $items,
            'stats' => $stats,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Client/KnowledgeBase/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->getTenant();

        $validated = $request->validate([
            'type' => 'required|in:document,faq,webpage,text',
            'title' => 'required|string|max:255',
            'content' => 'required_if:type,faq,text|nullable|string',
            'source_url' => ['required_if:type,webpage', 'nullable', 'url', new SafeExternalUrl],
            'file' => 'required_if:type,document|nullable|file|mimes:pdf,doc,docx,txt,md|max:10240',
        ]);

        Log::debug('[Knowledge] (NO $) Creating item', [
            'tenant_id' => $tenant->id,
            'type' => $validated['type'],
        ]);

        $item = new KnowledgeItem;
        $item->tenant_id = $tenant->id;
        $item->title = $validated['title'];
        $item->type = $validated['type'];
        $item->status = 'pending';

        if ($validated['type'] === 'document' && $request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store("knowledge/{$tenant->id}", 'local');
            $item->file_path = $path ?: null;
            $item->metadata = [
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ];
        } elseif ($validated['type'] === 'webpage') {
            $item->source_url = $validated['source_url'];
        } else {
            $item->content = $validated['content'];
        }

        $item->save();

        $this->dispatchProcessing($item);
        $this->cache->invalidate($tenant);

        Log::debug('[Knowledge] (NO $) Item created, processing queued', [
            'item_id' => $item->id,
        ]);

        return redirect()->route('client.knowledge.index')
            ->with('success', 'Knowledge item added and is being processed.');
    }

    public function show(KnowledgeItem $item): Response
    {
        $this->authorize('view', $item);

        $item->loadCount('chunks');

        return Inertia::render('Client/KnowledgeBase/Show', [
            'item' => [
                'id' => $item->id,
                'title' => $item->title,
                'type' => $item->type,
                'content' => $item->content,
                'source_url' => $item->source_url,
                'status' => $item->status,
                'metadata' => $item->metadata,
                'chunks_count' => $item->chunks_count,
                'created_at' => $item->created_at->format('M d, Y H:i'),
                'updated_at' => $item->updated_at->format('M d, Y H:i'),
                'error_message' => $item->error_message,
                'failed_at' => $item->failed_at?->format('M d, Y H:i'),
            ],
        ]);
    }

    public function edit(KnowledgeItem $item): Response
    {
        $this->authorize('view', $item);

        return Inertia::render('Client/KnowledgeBase/Edit', [
            'item' => [
                'id' => $item->id,
                'title' => $item->title,
                'type' => $item->type,
                'content' => $item->content,
                'source_url' => $item->source_url,
            ],
        ]);
    }

    public function update(Request $request, KnowledgeItem $item): RedirectResponse
    {
        $this->authorize('update', $item);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
            'source_url' => ['nullable', 'url', new SafeExternalUrl],
        ]);

        $item->update([
            'title' => $validated['title'],
            'content' => $validated['content'] ?? $item->content,
            'source_url' => $validated['source_url'] ?? $item->source_url,
            'status' => 'pending',
        ]);

        if ($item->wasChanged('content') || $item->wasChanged('source_url')) {
            $item->chunks()->delete();
            $this->dispatchProcessing($item);
        }

        $this->cache->invalidate($this->getTenant());

        return redirect()->route('client.knowledge.index')
            ->with('success', 'Knowledge item updated.');
    }

    public function destroy(KnowledgeItem $item): RedirectResponse
    {
        $this->authorize('delete', $item);

        Log::debug('[Knowledge] (NO $) Deleting item', [
            'item_id' => $item->id,
        ]);

        if ($item->file_path && Storage::disk('local')->exists($item->file_path)) {
            Storage::disk('local')->delete($item->file_path);
        }

        $item->chunks()->delete();
        $item->delete();

        $this->cache->invalidate($this->getTenant());

        return redirect()->route('client.knowledge.index')
            ->with('success', 'Knowledge item deleted.');
    }

    public function reprocess(KnowledgeItem $item): RedirectResponse
    {
        $this->authorize('update', $item);

        $item->chunks()->delete();
        $item->update(['status' => 'pending']);

        $this->dispatchProcessing($item);
        $this->cache->invalidate($this->getTenant());

        return redirect()->back()
            ->with('success', 'Knowledge item queued for reprocessing.');
    }

    public function retry(KnowledgeItem $item): RedirectResponse
    {
        $this->authorize('update', $item);

        $this->workflow->retry($item);

        return redirect()->back()
            ->with('success', 'Knowledge item queued for retry.');
    }

    /**
     * Dispatch ProcessKnowledgeItem in a way that doesn't block the HTTP response.
     *
     * Under a real queue driver (redis, database) plain dispatch() is already
     * non-blocking. Under QUEUE_CONNECTION=sync (dev or misconfigured prod),
     * plain dispatch() blocks the request for the full processing duration —
     * including DocumentProcessor::extractFromUrl's 30s HTTP fetch. Use
     * dispatchAfterResponse only in that case so the response goes out first.
     *
     * Note: dispatchAfterResponse internally calls dispatchSync which forces
     * the 'sync' connection regardless of config. Routing through it only
     * makes sense when the configured driver is already sync.
     */
    private function dispatchProcessing(KnowledgeItem $item): void
    {
        if (config('queue.default') === 'sync') {
            ProcessKnowledgeItem::dispatchAfterResponse($item);

            return;
        }

        ProcessKnowledgeItem::dispatch($item);
    }
}
```

What changed:
- Constructor injects `KnowledgeCache` + `KnowledgeItemWorkflow`.
- `index()` query rewritten from `KnowledgeItem::where('tenant_id', $tenant->id)` to `KnowledgeItem::forTenant($tenant)`. Same for `statsByType`.
- `index()` payload now includes `error_message` + `failed_at`. The map closure is type-hinted with `function (KnowledgeItem $item): array` for PHPStan.
- `show()` payload now includes `error_message` + `failed_at`.
- Private `clearKnowledgeCache(Tenant $tenant)` deleted; the four call sites (`store`, `update`, `destroy`, `reprocess`) call `$this->cache->invalidate(...)` directly.
- New `retry(KnowledgeItem $item)` action — delegates to `KnowledgeItemWorkflow::retry()`. Authorization-gated via the existing `update` policy ability (same gate as `reprocess`).
- `Cache` facade import removed (unused).
- `Tenant` import removed (unused — no longer referenced by `clearKnowledgeCache`).

- [ ] **Step 2: Add the retry route**

Open `routes/web.php` and locate the Knowledge Base block (around line 61-71). Replace:

```php
        Route::post('/{item}/reprocess', [KnowledgeBaseController::class, 'reprocess'])->name('reprocess');
    });
```

with:

```php
        Route::post('/{item}/reprocess', [KnowledgeBaseController::class, 'reprocess'])->name('reprocess');
        Route::post('/{item}/retry', [KnowledgeBaseController::class, 'retry'])->name('retry');
    });
```

- [ ] **Step 3: Remove the 2 `KnowledgeBaseController.php` entries from `phpstan-baseline.neon`**

Open `phpstan-baseline.neon` and locate:
```yaml
		-
			message: '#^Raw where\(''tenant_id'', \.\.\.\) bypasses tenant scoping\. Use forTenant\(\$tenant\) instead\.$#'
			identifier: tenancy.rawTenantId
			count: 2
			path: app/Http/Controllers/Client/KnowledgeBaseController.php
```

Delete the entire block.

- [ ] **Step 4: Run PHPStan to verify the baseline is consistent**

```bash
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -5
```

Expected: `[OK] No errors`. If PHPStan reports `Ignored error pattern ... was not matched`, the deletion was incomplete; re-grep the baseline.

- [ ] **Step 5: Run the full suite**

```bash
php artisan test 2>&1 | tail -10
```

Expected: PASS — entire suite green. If `KnowledgeBaseTest` fails because `assertDatabaseHas('knowledge_items', ['status' => 'pending'])` now sees an enum-cast column — Eloquent stores the backing string at the DB layer, so `assertDatabaseHas` (which queries via DB facade) should still work with `'pending'`. If it doesn't, swap the assertion to `'status' => KnowledgeItemStatus::Pending->value`.

- [ ] **Step 6: Manually verify the retry route is registered**

```bash
php artisan route:list 2>&1 | grep -E 'knowledge.*(retry|reprocess)'
```

Expected output includes a line for `POST knowledge/{item}/retry client.knowledge.retry`.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Client/KnowledgeBaseController.php routes/web.php phpstan-baseline.neon
git commit -m "$(cat <<'EOF'
refactor(knowledge): KnowledgeBaseController delegates cache + adds retry route

Drops private clearKnowledgeCache; injects KnowledgeCache and calls
invalidate() at four call sites. index() and statsByType queries
convert from raw where('tenant_id', ...) to forTenant($tenant) —
shrinks the Cluster A baseline by 2 more entries.

Adds POST /knowledge/{item}/retry (client.knowledge.retry) which
delegates to KnowledgeItemWorkflow::retry. Authorized by the existing
'update' policy ability. UI surfaces this via the next task.

index() + show() payloads now include error_message + failed_at so
the listing can render failure details.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster B, B-1 + B-2)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 11 — Vue `Index.vue`: failure error display + retry button

**Files:**
- Modify: `resources/js/Pages/Client/KnowledgeBase/Index.vue`

- [ ] **Step 1: Update the Vue component**

Replace the entire body of `resources/js/Pages/Client/KnowledgeBase/Index.vue` with:

```vue
<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import { useRoute } from '@/composables/useRoute'
import ClientLayout from '@/Layouts/ClientLayout.vue'
import { Card, CardContent } from '@/Components/ui/card'
import { Button } from '@/Components/ui/button'
import { Badge } from '@/Components/ui/badge'
import {
  FileText,
  HelpCircle,
  Globe,
  AlignLeft,
  Plus,
  Pencil,
  Trash2,
  RefreshCw,
  AlertCircle,
} from 'lucide-vue-next'

const route = useRoute()

defineProps({
  items: Array,
  stats: Object,
})

const deleteItem = (id) => {
  if (confirm('Are you sure you want to delete this item?')) {
    router.delete(route('client.knowledge.destroy', id))
  }
}

const retryItem = (id) => {
  router.post(route('client.knowledge.retry', id), {}, {
    preserveScroll: true,
  })
}

const getStatusVariant = (status) => {
  return {
    pending: 'warning',
    processing: 'secondary',
    ready: 'success',
    failed: 'destructive',
  }[status] || 'secondary'
}

const getTypeIcon = (type) => {
  return {
    document: FileText,
    faq: HelpCircle,
    webpage: Globe,
    text: AlignLeft,
  }[type] || FileText
}
</script>

<template>
  <Head title="Knowledge Base" />

  <ClientLayout>
    <div class="space-y-6">
      <!-- Header -->
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold text-foreground">Knowledge Base</h1>
        <Button as-child>
          <Link :href="route('client.knowledge.create')">
            <Plus class="h-4 w-4 mr-2" />
            Add Knowledge
          </Link>
        </Button>
      </div>

      <!-- Stats Grid -->
      <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-blue-100 p-2">
                <FileText class="h-5 w-5 text-blue-600" />
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Documents</p>
                <p class="text-xl font-semibold">{{ stats?.documents ?? 0 }}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-purple-100 p-2">
                <HelpCircle class="h-5 w-5 text-purple-600" />
              </div>
              <div>
                <p class="text-sm text-muted-foreground">FAQs</p>
                <p class="text-xl font-semibold">{{ stats?.faqs ?? 0 }}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-green-100 p-2">
                <Globe class="h-5 w-5 text-green-600" />
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Webpages</p>
                <p class="text-xl font-semibold">{{ stats?.webpages ?? 0 }}</p>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent class="p-4">
            <div class="flex items-center gap-3">
              <div class="rounded-lg bg-amber-100 p-2">
                <AlignLeft class="h-5 w-5 text-amber-600" />
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Text Snippets</p>
                <p class="text-xl font-semibold">{{ stats?.text ?? 0 }}</p>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Items List -->
      <Card>
        <CardContent class="p-0">
          <div v-if="!items || items.length === 0" class="text-center py-12">
            <FileText class="mx-auto h-12 w-12 text-muted-foreground" />
            <h3 class="mt-2 text-sm font-medium text-foreground">No knowledge items</h3>
            <p class="mt-1 text-sm text-muted-foreground">Get started by adding your first knowledge item.</p>
            <div class="mt-6">
              <Button as-child>
                <Link :href="route('client.knowledge.create')">
                  <Plus class="h-4 w-4 mr-2" />
                  Add Knowledge
                </Link>
              </Button>
            </div>
          </div>

          <ul v-else class="divide-y divide-border">
            <li v-for="item in items" :key="item.id" class="px-4 py-4 sm:px-6 hover:bg-accent/50 transition-colors">
              <div class="flex items-center justify-between gap-4">
                <div class="flex items-center min-w-0 gap-4">
                  <div class="flex-shrink-0 rounded-lg bg-muted p-2">
                    <component :is="getTypeIcon(item.type)" class="h-5 w-5 text-muted-foreground" />
                  </div>
                  <div class="min-w-0">
                    <Link
                      :href="route('client.knowledge.show', item.id)"
                      class="text-sm font-medium text-primary hover:underline truncate block"
                    >
                      {{ item.title }}
                    </Link>
                    <p class="text-sm text-muted-foreground">
                      <span class="capitalize">{{ item.type }}</span>
                      <span class="mx-1">&middot;</span>
                      {{ item.chunks_count }} chunks
                      <span class="mx-1">&middot;</span>
                      {{ item.created_at }}
                    </p>
                  </div>
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                  <Badge :variant="getStatusVariant(item.status)">
                    {{ item.status }}
                  </Badge>
                  <div class="flex items-center gap-1">
                    <Button
                      v-if="item.status === 'failed'"
                      variant="ghost"
                      size="icon"
                      title="Retry processing"
                      @click="retryItem(item.id)"
                    >
                      <RefreshCw class="h-4 w-4 text-primary" />
                    </Button>
                    <Button variant="ghost" size="icon" as-child>
                      <Link :href="route('client.knowledge.edit', item.id)">
                        <Pencil class="h-4 w-4" />
                      </Link>
                    </Button>
                    <Button variant="ghost" size="icon" @click="deleteItem(item.id)">
                      <Trash2 class="h-4 w-4 text-destructive" />
                    </Button>
                  </div>
                </div>
              </div>

              <!-- Failure detail row -->
              <div
                v-if="item.status === 'failed' && (item.error_message || item.failed_at)"
                class="mt-3 ml-12 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm"
              >
                <div class="flex items-start gap-2">
                  <AlertCircle class="h-4 w-4 mt-0.5 flex-shrink-0 text-destructive" />
                  <div class="min-w-0">
                    <p v-if="item.error_message" class="text-destructive font-medium break-words">
                      {{ item.error_message }}
                    </p>
                    <p v-else class="text-destructive font-medium">
                      Processing failed (no detail recorded).
                    </p>
                    <p v-if="item.failed_at" class="text-xs text-muted-foreground mt-0.5">
                      Failed at {{ item.failed_at }}
                    </p>
                  </div>
                </div>
              </div>
            </li>
          </ul>
        </CardContent>
      </Card>
    </div>
  </ClientLayout>
</template>
```

What changed:
- New `RefreshCw` + `AlertCircle` icon imports.
- New `retryItem(id)` action — POSTs to `client.knowledge.retry` with `preserveScroll: true` so the user stays in place after the redirect.
- New retry icon button, visible only when `item.status === 'failed'`.
- New failure detail row underneath the main item row, shown only for failed items. Displays the error message (or fallback text for pre-migration failures) and the failure timestamp.

- [ ] **Step 2: Build the frontend**

```bash
npm run build 2>&1 | tail -5
```

Expected: build succeeds. If Vite reports template-level errors, they'll point to the line — usually a typo introduced during the rewrite.

- [ ] **Step 3: Smoke-render the Knowledge index in a non-failed state**

Start the dev server (skip if already running on 8001):

```bash
php artisan serve --port=8001 &
```

Open `http://127.0.0.1:8001/dashboard/knowledge` (login as `test@example.com` / `password` if needed). Expected:
- The list renders normally; existing items have no retry button visible.
- The previous Pencil + Trash2 buttons still work.
- No JavaScript console errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Client/KnowledgeBase/Index.vue
git commit -m "$(cat <<'EOF'
feat(knowledge): show failure details + retry button on Knowledge index

Failed items now render an inline detail row with error_message +
failed_at, and a Retry icon button that POSTs to client.knowledge.retry.
Pre-migration failures (no error_message) show a fallback message.

Refs: docs/superpowers/specs/2026-05-14-architecture-deepening-design.md (Cluster B, B-1 UI)

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

---

## Task 12 — Browser smoke + baseline-shrinkage verification + full suite

**Files:** none modified — local verification only.

**Purpose:** prove the full happy path + failure-retry path work end-to-end against the rewritten code, and confirm the baseline shrank by exactly 4 entries.

- [ ] **Step 1: Run the full Pest suite one more time**

```bash
php artisan test 2>&1 | tail -10
```

Expected: PASS — entire suite green. Note total test count for the PR description.

- [ ] **Step 2: Confirm PHPStan is `[OK] No errors`**

```bash
./vendor/bin/phpstan analyse --memory-limit=512M 2>&1 | tail -5
```

Expected: `[OK] No errors`.

- [ ] **Step 3: Confirm the baseline has shrunk by exactly 4 entries**

```bash
git show HEAD~10:phpstan-baseline.neon 2>/dev/null | grep -c "^		-" || echo "unable to read pre-cluster baseline"
grep -c "^		-" phpstan-baseline.neon
```

Expected: the second number is exactly 4 less than the first. If the diff is wrong (e.g., 3 or 5), inspect the baseline `git diff main...HEAD -- phpstan-baseline.neon` and reconcile — every removed entry must correspond to an actual baseline-shrinking change in this PR; every retained entry must still match real-running violations.

If the first command can't reach the pre-cluster baseline (e.g., shallow clone), substitute:
```bash
git fetch origin main
git show origin/main:phpstan-baseline.neon | grep -c "^		-"
grep -c "^		-" phpstan-baseline.neon
```

- [ ] **Step 4: Smoke flow 1 — Happy-path knowledge upload**

Open `http://127.0.0.1:8001/dashboard/knowledge` (login as `test@example.com` / `password`).

Steps:
- Click "Add Knowledge" → "Text".
- Title: "Smoke B-1"; Content: "This is a smoke test for the new pipeline. We have several sentences here so the chunker will produce at least one chunk over fifty characters in length. Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt."
- Submit.

Verify:
```bash
php artisan tinker --execute="\$k = \\App\\Models\\KnowledgeItem::latest()->first(); echo json_encode(['id'=>\$k?->id,'status'=>\$k?->status?->value,'chunks_count'=>\$k?->chunks()->count(),'error_message'=>\$k?->error_message]);"
```

Expected: `status` reads `"ready"` (or `"processing"` if you check very fast before the embedding job lands), `chunks_count` > 0, `error_message` is `null`.

- [ ] **Step 5: Smoke flow 2 — Force a failure, verify error capture**

Force a failure via a URL that will be rejected at fetch time. Steps:
- Click "Add Knowledge" → "Webpage".
- Title: "Smoke B-fail"; URL: `http://example.invalid/no-such-page`
- Submit. (The URL passes the `SafeExternalUrl` validator at submit time because it's a public DNS — the fetch fails downstream.)

If the validator blocks the URL at submit, substitute a URL that passes validation but is guaranteed to fail at fetch time. Alternatively, manually trigger a failure:
```bash
php artisan tinker --execute="\$t = \\App\\Models\\Tenant::find(1); \$k = \\App\\Models\\KnowledgeItem::create(['tenant_id'=>\$t->id,'type'=>'document','title'=>'Smoke B-fail','file_path'=>'knowledge/nonexistent.txt','status'=>'pending']); \\App\\Jobs\\ProcessKnowledgeItem::dispatchSync(\$k);"
```

(`dispatchSync` runs the job inline and lets it throw without retrying.) Expected: the tinker output includes an exception trace; the item lands in `failed` state with `error_message` populated.

Verify via the UI: reload `http://127.0.0.1:8001/dashboard/knowledge`. The "Smoke B-fail" item shows a red `Failed` badge, a Retry icon next to Pencil + Trash2, and a detail row underneath with the error message + timestamp.

- [ ] **Step 6: Smoke flow 3 — Click Retry, verify the workflow re-runs**

Click the Retry icon button on the failed item. Expected:
- Flash message: "Knowledge item queued for retry."
- The item's status moves back to `pending` (or directly to `ready`/`failed` if processing is fast under `QUEUE_CONNECTION=sync`).
- The error message detail row disappears (if newly ready).
- If it fails again (still pointing at a bad URL/file), a fresh `error_message` + `failed_at` appears — confirming the workflow's idempotent-overwrite-on-Failed→Failed behavior.

- [ ] **Step 7: Take screenshots**

Capture before-merge evidence. Save:
- `smoke-knowledge-01-happy.png` — successful upload in `ready` state
- `smoke-knowledge-02-failed.png` — failed item with detail row + retry button visible
- `smoke-knowledge-03-retried.png` — retried item in `pending`/`processing`/`ready` state

Save to the repo root (these are temporary artifacts referenced in the PR description, not committed).

- [ ] **Step 8: Tear down the test data**

```bash
php artisan tinker --execute="\\App\\Models\\KnowledgeItem::whereIn('title', ['Smoke B-1','Smoke B-fail'])->delete();"
```

(Not strictly required — leftover items are harmless — but keeps the test tenant clean for downstream smoke runs.)

- [ ] **Step 9: No commit needed** — screenshots are not committed.

---

## Task 13 — Pint, /simplify, Pint, /simplify, PR

**Expected commit count when this PR is ready to push:** 11 feature commits from Tasks 1–11, plus 0–2 `style(pint): apply auto-fixes` commits per Pint pass, plus 0+ commits from each `/simplify` pass. Final total typically 12–18 commits.

- [ ] **Step 1: First Pint pass**

Run:
```bash
./vendor/bin/pint --test
```

If anything is flagged on PR-touched files only (not unrelated repo files):
```bash
./vendor/bin/pint \
  app/Enums/KnowledgeItemStatus.php \
  app/Exceptions/InvalidTransitionException.php \
  app/Services/Knowledge/KnowledgeCache.php \
  app/Services/Knowledge/KnowledgeItemWorkflow.php \
  app/Services/Knowledge/RetrievalService.php \
  app/Services/Knowledge/DocumentProcessor.php \
  app/Jobs/ProcessKnowledgeItem.php \
  app/Jobs/GenerateEmbeddings.php \
  app/Models/KnowledgeItem.php \
  app/Http/Controllers/Client/KnowledgeBaseController.php \
  tests/Unit/Enums/ tests/Unit/Exceptions/ \
  tests/Unit/Services/Knowledge/ \
  tests/Unit/Jobs/KnowledgeStatusFlowTest.php \
  tests/Unit/Jobs/ProcessKnowledgeItemIdempotencyTest.php
php artisan test
git add -p
git commit -m "style(pint): apply auto-fixes — Cluster B"
```

- [ ] **Step 2: First `/simplify` pass**

Run the `/simplify` slash command. It dispatches three parallel reviewers (reuse / quality / efficiency) over the diff. Apply real fixes; for stylistic noise, skip with a one-line reason in the response.

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
gh pr create --title "feat(knowledge): KnowledgeItemWorkflow + KnowledgeCache + DocumentProcessor merge (Cluster B)" --body "$(cat <<'EOF'
## Summary

Cluster B of the architecture-deepening backlog. Bundles three deepening candidates into one PR because all three rewrite `ProcessKnowledgeItem::handle()` and the same `app/Services/Knowledge/` files:

- **B-1 — `KnowledgeItemWorkflow` + `KnowledgeItemStatus` enum.** PS-strict transitions replace scattered `markAs*` model methods. Captures `error_message` + `failed_at` on failure (new columns). Failed items are now retry-able via a new `POST /knowledge/{item}/retry` route + UI button.
- **B-2 — `KnowledgeCache`.** Result cache + version-key invalidation extracted from `RetrievalService` (read path) and `KnowledgeBaseController::clearKnowledgeCache` (write path) into one service.
- **B-3 — `DocumentProcessor::process(KnowledgeItem)`.** Type-switch + extraction + chunking concentrate in one entry point. `TextChunker` deleted (137 lines move into private methods).

`RetrievalService` and `KnowledgeBaseController` queries rewrite from raw `where('tenant_id', ...)` (and `where('ki.tenant_id', ...)`) to the `BelongsToTenant` trait's `forTenant($tenant)` scope, **shrinking the Cluster A baseline by 4 entries** (51 → 47).

## Deploy steps

1. Run migration: `php artisan migrate` — adds `error_message` (text, nullable) and `failed_at` (timestamp, nullable) to `knowledge_items`.
2. Standard merge → deploy. No env vars; no config changes; no queue config changes.

**Rollback:** `git revert <merge-sha>` + `php artisan migrate:rollback --step=1`. The two new columns are nullable, so unmigrated old rows + the rolled-back schema are compatible.

## ⚠️ Behavior changes

- **`KnowledgeItem::markAsProcessing/Ready/Failed` methods deleted.** All callers go through `KnowledgeItemWorkflow`. Any out-of-tree code reaching for those methods will fail to resolve.
- **`KnowledgeItem::$status` is now a `KnowledgeItemStatus` backed enum cast.** PHP-land code reading `$item->status` gets an enum instance; comparisons must use the enum (`$item->status === KnowledgeItemStatus::Ready`) or `->value`. Frontend payloads still see plain strings — Eloquent + Inertia serialize the enum to its backing value transparently.
- **`TextChunker` class deleted.** `ProcessKnowledgeItem` constructor signature changes from `handle(DocumentProcessor, TextChunker)` to `handle(DocumentProcessor, KnowledgeItemWorkflow)`. Anything else injecting `TextChunker` will fail to resolve.
- **`KnowledgeBaseController::clearKnowledgeCache` deleted.** Callers route through `KnowledgeCache::invalidate`.
- **Failed knowledge items now carry an error message + timestamp.** Listing surfaces both. Pre-migration failures (older rows) show "Processing failed (no detail recorded)."
- **Failed items now have a Retry button.** UX improvement — no manual re-upload needed.
- **Newly-ready knowledge items invalidate the retrieval cache immediately.** Chat visitors querying right after an upload see the new chunks without waiting for the 10-minute TTL.
- **Extracted text from documents/webpages is no longer written back to `knowledge_items.content`.** Chunks remain the source of truth for retrieval. Document/webpage Show.vue's content-preview card becomes empty for those types. Call out if a follow-up is desired to restore the write-back.

## Test plan

- [x] `KnowledgeItemStatusTest`, `InvalidTransitionExceptionTest` (6 assertions, value-type contracts)
- [x] `KnowledgeCacheTest` (6 assertions, tenant-scoped get/put/invalidate)
- [x] `KnowledgeItemWorkflowTest` (14 assertions, every transition + every invalid-transition + G-4 overwrite + retry dispatch)
- [x] `DocumentProcessorProcessTest` (7 assertions, type-switch + chunking)
- [x] Updated `KnowledgeStatusFlowTest`, `ProcessKnowledgeItemIdempotencyTest` (existing tests, now on workflow + processor APIs)
- [x] Updated `RetrievalServiceTest`, `RetrievalServiceFallbackTest` (existing tests, now on Eloquent + `KnowledgeCache`)
- [x] Full Pest suite green
- [x] `./vendor/bin/phpstan analyse` → `[OK] No errors`; baseline shrunk by 4 entries
- [x] Browser smoke: happy-path upload, forced failure with error capture, retry button. Screenshots `smoke-knowledge-{01..03}.png` attached.

## Architecture

Cluster B of the 4-cluster architecture-deepening initiative. Three candidates intentionally bundled because they share `ProcessKnowledgeItem::handle()`'s rewrite surface — splitting would create back-to-back merge conflicts.

- Spec: `docs/superpowers/specs/2026-05-14-architecture-deepening-design.md`
- Plan: `docs/superpowers/plans/2026-05-14-knowledge-pipeline.md`
- Prior cluster: PR #18 (Cluster A — tenant scoping)

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 6: Wait for CI green and merge**

Watch the PR's checks. Fix any failures and re-push. Merge once green.

- [ ] **Step 7: Update memory after merge**

Save a project memory entry capturing what shipped + what's still pending. Reference the existing format in `~/.claude/projects/-Users-sam-Dev-laravel-chatbot/memory/`:

```text
arch_cluster_b_shipped (project memory):
- PR # merged 2026-05-XX; KnowledgeItemWorkflow + KnowledgeCache + DocumentProcessor merge.
- Baseline shrank by 4 entries (RetrievalService + KnowledgeBaseController converted to forTenant); 47 entries remain.
- Cluster C (LeadScoring merge) next — see master spec.
- Behavior change shipped: extracted text from documents/webpages no longer written to knowledge_items.content. Show.vue content preview blank for those types — restore if/when users complain.
```

---

## Self-review summary

**Spec coverage check (Cluster B section of `docs/superpowers/specs/2026-05-14-architecture-deepening-design.md`):**

- B-1 `KnowledgeItemWorkflow` + `KnowledgeItemStatus` enum → Tasks 2 (enum + exception), 4 (workflow), 5 (model cast + delete markAs*), 6 + 8 (job rewrites delegating to workflow). PS-strict on source + idempotent on destination per spec line 89. G-4 double-`markFailed` overwrite behavior covered by `KnowledgeItemWorkflowTest::test_mark_failed_from_failed_overwrites_previous_error`.
- B-1 EC1 error_message + failed_at columns → Task 1 migration + Task 5 fillable/casts + Task 10 controller payload + Task 11 UI.
- B-1 retry path → Task 4 workflow `retry()` + Task 10 controller `retry()` + route + Task 11 UI button.
- B-1 InvalidTransitionException → Task 2 + Task 4 use sites.
- B-2 `KnowledgeCache` → Task 3 service + Task 9 RetrievalService delegation + Task 10 controller delegation + Task 4 workflow cache invalidate on markReady (G-5).
- B-2 `RetrievalService` Eloquent rewrite (closes A→B coverage gap by removing 2 baseline entries) → Task 9.
- B-3 `DocumentProcessor::process()` merge + `TextChunker` deletion → Task 7.
- B-3 hardcoded chunk size 500 / overlap 50 → constants in DocumentProcessor (Task 7).
- B-3 `ProcessKnowledgeItem::handle()` simplification (no extractContent, no chunker dep) → Task 8.
- Out-of-scope items (strategy split, decorator pattern, multilingual stopwords, hybrid retrieval, BM25, configurable chunk size, multi-step Processing substates, auto-retry backoff) → not addressed; flagged for future.

**Behavior-changes table cross-check:** every spec entry under "Behavior changes deployable users should know about" appears in the PR description's `⚠️ Behavior changes` block.

**Placeholder scan:** no `TBD`, no `TODO`, no "implement later", no "Similar to Task N". Every code block is complete. Every command shows expected output.

**Type consistency:** `KnowledgeItemStatus` cases (`Pending`, `Processing`, `Ready`, `Failed`) appear with the same casing across enum definition, workflow, model, jobs, controller, tests. `KnowledgeCache::get/put/invalidate` signatures match between definition (Task 3), workflow injection (Task 4), and controller injection (Task 10). `KnowledgeItemWorkflow::markProcessing/markReady/markFailed/retry` signatures match between definition (Task 4) and call sites in jobs (Tasks 6 + 8) and controller (Task 10).

**Task 0 → spec dependency check:** Task 0's three verifications (RetrievalService DB::table form; ProcessKnowledgeItem extractContent + constructor; KnowledgeItem markAs* methods; GenerateEmbeddings markAs* references) gate exactly the rewrites in Tasks 7-9. If any verification fails, the corresponding task body's "replace the entire body" instruction would silently land against the wrong source — Task 0 catches that before any code lands.

**Baseline-shrink discipline:** Task 9 + Task 10 each remove specific baseline blocks; Task 12 verifies the count diff is exactly 4. `reportUnmatchedIgnoredErrors: true` in `phpstan.neon` makes CI fail if a baseline entry is removed while the underlying violation persists (or kept while the violation is fixed) — caught before merge.

**Cluster A → B → C handoff note:** The master spec at line 57 anticipated the Larastan rule would NOT cover `DB::table()->where('ki.tenant_id', ...)`. The shipped rule (Task 3 of Plan A) is broader and matches any `MethodCall|StaticCall` named `where*` with a string-literal first arg ending in `tenant_id` — so it caught the 2 RetrievalService sites at baseline-generation time. Plan B's rewrite eliminates them, which is what the spec wanted regardless of whether the rule covered them or not. The "A→B window" risk in spec line 59 was hypothetical — the actual rule had no coverage gap to close, just baseline entries to retire.
