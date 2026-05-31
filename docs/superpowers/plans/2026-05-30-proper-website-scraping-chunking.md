# Proper Website Scraping & Chunking — Implementation Plan (Phase 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make crawled content clean (fix block-merge whitespace), detect JavaScript-rendered (SPA) pages that yield no real content, exclude them from RAG, and surface a dedicated `SkippedNoContent` status to the merchant — instead of silently indexing SEO boilerplate.

**Architecture:** A shared `ContentSufficiency` gate (word count + text-to-HTML ratio) decides whether extracted text is real. The crawler extracts synchronously so crawl stats stay honest; the job re-gates for manual-add paths. `DocumentProcessor` splits into public `extractHtml`/`extract`/`chunk`. The `knowledge_items.status` enum column becomes a plain string so a new status value needs no per-status migration.

**Tech Stack:** Laravel 13 / PHP 8.3, Postgres (dev/prod) + SQLite (tests), Vue 3 + Inertia, Pest/PHPUnit.

**Spec:** `docs/superpowers/specs/2026-05-30-proper-website-scraping-chunking-design.md`

---

## Task 0: Verification (already done — recorded here)

These were probed against the live DB and source before planning. **Do not re-derive; trust unless a step contradicts reality.**

- `knowledge_items.status` is a DB enum: `$table->enum('status', ['pending','processing','ready','failed'])` (`database/migrations/2025_11_28_060503_create_knowledge_items_table.php:24`). On Postgres/SQLite this is `varchar` + a `CHECK` constraint → the new value fails to insert until migrated. **Task 1 handles this and proves it via RED→GREEN.**
- `DocumentProcessor::process()` call sites: 13 in tests + 1 in `app/Jobs/ProcessKnowledgeItem.php:42`. Test files: `DocumentProcessorProcessTest` (8), `DocumentProcessorFetchTest` (3), `DocumentProcessorDocxTest` (2). Mockery `shouldReceive('process')` in `ProcessKnowledgeItemIdempotencyTest` (3).
- No test asserts the `throw new \Exception('No content could be extracted')` at `ProcessKnowledgeItem.php:45` — safe to replace with the skip path.
- **SPA signal:** the strict empty-`#root` regex does NOT match base44; the reliable signal is **text-to-HTML ratio**. Live `bookbhutantour.com` pages measured: words 9–16, ratio **0.0085–0.0131** (threshold 0.03 catches them; real short pages sit far higher). Real crawler-test pages have ratio ~0.8.
- Existing fixtures stay above the gate: `KnowledgeStatusFlowTest` content = 13 words (text, no SPA marker → sufficient). Crawler tests use small real-HTML pages (high ratio → sufficient).
- `warning` Badge variant exists (`resources/js/Components/ui/badge/Badge.vue:16`).

---

## Task 1: Add `SkippedNoContent` status + migrate `status` enum → string

**Files:**
- Modify: `app/Enums/KnowledgeItemStatus.php`
- Create: `database/migrations/2026_05_30_000001_change_knowledge_items_status_to_string.php`
- Test: `tests/Feature/Migrations/KnowledgeItemStatusStringTest.php`

- [ ] **Step 1: Add the enum case**

In `app/Enums/KnowledgeItemStatus.php`, add after `case Failed`:

```php
    case SkippedNoContent = 'skipped_no_content';
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Migrations/KnowledgeItemStatusStringTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Enums\KnowledgeItemStatus;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeItemStatusStringTest extends TestCase
{
    use RefreshDatabase;

    public function test_knowledge_item_persists_skipped_no_content_status(): void
    {
        $tenant = Tenant::factory()->create();

        $item = KnowledgeItem::create([
            'tenant_id' => $tenant->id,
            'type' => 'webpage',
            'title' => 'JS page',
            'status' => KnowledgeItemStatus::SkippedNoContent,
        ]);

        $this->assertSame(KnowledgeItemStatus::SkippedNoContent, $item->refresh()->status);
    }
}
```

- [ ] **Step 3: Run it — expect RED**

Run: `php artisan test --filter=KnowledgeItemStatusStringTest`
Expected: FAIL — `QueryException` (the enum/CHECK constraint rejects `skipped_no_content`).

- [ ] **Step 4: Write the migration**

Create `database/migrations/2026_05_30_000001_change_knowledge_items_status_to_string.php`:

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
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE knowledge_items ALTER COLUMN status TYPE varchar(32)');
            DB::statement('ALTER TABLE knowledge_items DROP CONSTRAINT IF EXISTS knowledge_items_status_check');

            return;
        }

        // SQLite (tests) + others: change() rebuilds the table from the Blueprint,
        // which defines a plain string with no enum CHECK constraint.
        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->string('status', 32)->default('pending')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE knowledge_items ADD CONSTRAINT knowledge_items_status_check CHECK (status IN ('pending','processing','ready','failed'))");

            return;
        }

        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->enum('status', ['pending', 'processing', 'ready', 'failed'])->default('pending')->change();
        });
    }
};
```

- [ ] **Step 5: Run it — expect GREEN**

Run: `php artisan test --filter=KnowledgeItemStatusStringTest`
Expected: PASS. (RefreshDatabase runs the new migration on SQLite; the insert now succeeds.)

> If this still fails on SQLite (change() preserved the CHECK), replace the `else` branch with a manual rebuild: create `knowledge_items_tmp` with `status` as `string`, `INSERT ... SELECT`, drop original, rename. Re-run until GREEN.

- [ ] **Step 6: Run the full suite** (the column change touches every knowledge_items test)

Run: `php artisan test`
Expected: PASS (no regressions).

- [ ] **Step 7: Commit**

```bash
git add app/Enums/KnowledgeItemStatus.php database/migrations/2026_05_30_000001_change_knowledge_items_status_to_string.php tests/Feature/Migrations/KnowledgeItemStatusStringTest.php
git commit -m "feat(knowledge): add SkippedNoContent status; migrate status enum to string"
```

---

## Task 2: `ContentSufficiency` gate

**Files:**
- Create: `app/Services/Knowledge/ContentSufficiency.php`
- Test: `tests/Unit/Services/Knowledge/ContentSufficiencyTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Knowledge/ContentSufficiencyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Services\Knowledge\ContentSufficiency;
use PHPUnit\Framework\TestCase;

class ContentSufficiencyTest extends TestCase
{
    private ContentSufficiency $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new ContentSufficiency;
    }

    public function test_empty_text_is_insufficient(): void
    {
        $this->assertFalse($this->gate->isSufficient(''));
    }

    public function test_below_hard_floor_word_count_is_insufficient(): void
    {
        $this->assertFalse($this->gate->isSufficient('two words'));
    }

    public function test_plain_short_text_without_html_is_sufficient(): void
    {
        // No raw HTML supplied → only the hard floor applies (manual text/faq path).
        $this->assertTrue($this->gate->isSufficient('one two three four five six seven'));
    }

    public function test_spa_shell_with_low_text_ratio_is_insufficient(): void
    {
        // ~16 words of body text, but the page is dominated by a JS bundle.
        $clean = 'Tours Book Bhutan Tour Official Website for Book Bhutan Tour visit us today now here';
        $html = '<html><body><div id="root">'.$clean.'</div><script>'.str_repeat('var x=1;', 800).'</script></body></html>';

        $this->assertFalse($this->gate->isSufficient($clean, $html));
    }

    public function test_real_short_page_with_high_text_ratio_is_sufficient(): void
    {
        $clean = 'Visit our showroom Monday to Friday from nine to five at Main Street Thimphu';
        $html = '<html><body><p>'.$clean.'</p></body></html>';

        $this->assertTrue($this->gate->isSufficient($clean, $html));
    }

    public function test_word_count_uses_whitespace_split(): void
    {
        $this->assertSame(4, $this->gate->wordCount("  one\ntwo   three\tfour  "));
        $this->assertSame(0, $this->gate->wordCount('   '));
    }
}
```

- [ ] **Step 2: Run it — expect RED**

Run: `php artisan test --filter=ContentSufficiencyTest`
Expected: FAIL — class `ContentSufficiency` does not exist.

- [ ] **Step 3: Implement the gate**

Create `app/Services/Knowledge/ContentSufficiency.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

/**
 * Decides whether extracted text is real content or an empty/SPA-shell page.
 *
 * Signals (combined, so a thin-but-real page is not buried):
 * - hard floor: fewer than HARD_FLOOR_WORDS words is always insufficient;
 * - SPA shell: short text AND its length is a tiny fraction of the raw HTML
 *   (the page is dominated by a JS bundle — base44/React/Vue/Next shells).
 */
class ContentSufficiency
{
    public const HARD_FLOOR_WORDS = 3;

    public const SPA_CEILING_WORDS = 25;

    public const SPA_TEXT_RATIO = 0.03;

    public function isSufficient(string $cleanText, ?string $rawHtml = null): bool
    {
        $words = $this->wordCount($cleanText);

        if ($words < self::HARD_FLOOR_WORDS) {
            return false;
        }

        if ($words < self::SPA_CEILING_WORDS
            && $rawHtml !== null
            && $this->looksLikeSpaShell($rawHtml, $cleanText)) {
            return false;
        }

        return true;
    }

    public function wordCount(string $text): int
    {
        $trimmed = trim($text);

        if ($trimmed === '') {
            return 0;
        }

        return count(preg_split('/\s+/', $trimmed));
    }

    private function looksLikeSpaShell(string $rawHtml, string $cleanText): bool
    {
        $htmlLength = strlen($rawHtml);

        return $htmlLength > 0
            && (strlen($cleanText) / $htmlLength) < self::SPA_TEXT_RATIO;
    }
}
```

- [ ] **Step 4: Run it — expect GREEN**

Run: `php artisan test --filter=ContentSufficiencyTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Knowledge/ContentSufficiency.php tests/Unit/Services/Knowledge/ContentSufficiencyTest.php
git commit -m "feat(knowledge): add ContentSufficiency gate (word-count + SPA text-ratio)"
```

---

## Task 3: `KnowledgeItemWorkflow::markSkippedNoContent`

**Files:**
- Modify: `app/Services/Knowledge/KnowledgeItemWorkflow.php`
- Modify: `tests/Unit/Services/Knowledge/KnowledgeItemWorkflowTest.php` (the file already exists — APPEND two methods; its `makeItem(string)` + `workflow()` helpers and the `KnowledgeItemStatus`/`InvalidTransitionException` imports are already present)

- [ ] **Step 1: Write the failing test**

Append these two methods to the existing `KnowledgeItemWorkflowTest` class (e.g. just before `tearDown()`), reusing the file's existing helpers:

```php
    public function test_mark_skipped_no_content_from_processing(): void
    {
        $item = $this->makeItem('processing');

        $this->workflow()->markSkippedNoContent($item);

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::SkippedNoContent, $item->status);
    }

    public function test_mark_skipped_no_content_from_pending_throws(): void
    {
        $item = $this->makeItem('pending');

        $this->expectException(InvalidTransitionException::class);

        $this->workflow()->markSkippedNoContent($item);
    }
```

- [ ] **Step 2: Run it — expect RED**

Run: `php artisan test --filter=KnowledgeItemWorkflowTest`
Expected: FAIL — `markSkippedNoContent` does not exist.

- [ ] **Step 3: Implement the transition**

In `app/Services/Knowledge/KnowledgeItemWorkflow.php`, add after `markFailed()`:

```php
    /** Processing → SkippedNoContent. For pages that yield no real content. */
    public function markSkippedNoContent(KnowledgeItem $item): void
    {
        $this->assertSourceIn(
            $item,
            [KnowledgeItemStatus::Processing],
            KnowledgeItemStatus::SkippedNoContent,
        );

        $item->update([
            'status' => KnowledgeItemStatus::SkippedNoContent,
            'error_message' => null,
            'failed_at' => null,
        ]);
    }
```

- [ ] **Step 4: Run it — expect GREEN**

Run: `php artisan test --filter=KnowledgeItemWorkflowTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Knowledge/KnowledgeItemWorkflow.php tests/Unit/Services/Knowledge/KnowledgeItemWorkflowTest.php
git commit -m "feat(knowledge): add markSkippedNoContent workflow transition"
```

---

## Task 4: Split `DocumentProcessor` + fix block-merge whitespace

**Files:**
- Modify: `app/Services/Knowledge/DocumentProcessor.php`
- Test: `tests/Unit/Services/Knowledge/DocumentProcessorProcessTest.php` (add one test)

- [ ] **Step 1: Write the failing whitespace test**

Append to `tests/Unit/Services/Knowledge/DocumentProcessorProcessTest.php`:

```php
    public function test_extract_html_separates_adjacent_block_elements(): void
    {
        $processor = new DocumentProcessor;

        $text = $processor->extractHtml('<html><body><h1>Our Bakery</h1><p>We bake bread daily.</p><ul><li>Sourdough</li><li>Rye</li></ul></body></html>');

        $this->assertStringNotContainsString('BakeryWe', $text);
        $this->assertStringNotContainsString('SourdoughRye', $text);
        $this->assertStringContainsString('Our Bakery', $text);
        $this->assertStringContainsString('We bake', $text);
    }
```

- [ ] **Step 2: Run it — expect RED**

Run: `php artisan test --filter=test_extract_html_separates_adjacent_block_elements`
Expected: FAIL — method `extractHtml` not found (private `extractTextFromHtml`) and/or `BakeryWe` present.

- [ ] **Step 3: Make `extractHtml`/`extract`/`chunk` public, add the separator, keep a temporary `process()` wrapper**

In `app/Services/Knowledge/DocumentProcessor.php`:

(a) Replace the `process()` method body with a thin wrapper and add the public `extract()`:

```php
    /**
     * @deprecated Temporary during the extract/chunk split — removed in the
     * same PR once all callers use extract()+chunk(). Do not add new callers.
     *
     * @return array<int, string>
     */
    public function process(KnowledgeItem $item): array
    {
        return $this->chunk($this->extract($item));
    }

    /** Clean text for a KnowledgeItem by type. Webpage content is already cleaned. */
    public function extract(KnowledgeItem $item): string
    {
        return match ($item->type) {
            'document' => $this->extractFromFile($item->file_path ?? ''),
            'webpage' => $item->content !== null && $item->content !== ''
                ? $item->content
                : $this->extractFromUrl($item->source_url ?? ''),
            'faq', 'text' => $item->content ?? '',
            default => '',
        };
    }
```

(b) Rename `private function extractTextFromHtml` to `public function extractHtml` and insert block separators before reading text. The method becomes:

```php
    public function extractHtml(string $html): string
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

        // Block elements carry no whitespace between them in textContent, so
        // "<h1>Our Bakery</h1><p>We bake" collapses to "Our BakeryWe bake".
        // Append a newline text node to each block element before extracting.
        $blockTags = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'br', 'section', 'article', 'blockquote', 'pre', 'tr', 'td', 'th', 'ul', 'ol', 'table', 'main'];
        foreach ($blockTags as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $element) {
                $element->appendChild($dom->createTextNode("\n"));
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $text = $body ? $body->textContent : $dom->textContent;

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->cleanText($text);
    }
```

(c) Update the one internal caller in `extractFromUrl()`: change `return $this->extractTextFromHtml($response->body());` to `return $this->extractHtml($response->body());`.

(d) Change the `webpage` arm of the old type-switch that lived in `process()` — it now lives in `extract()` (done in (a)); ensure no `extractTextFromHtml(` references remain (grep below).

- [ ] **Step 4: Verify no stale references**

Run: `grep -rn 'extractTextFromHtml' app`
Expected: no matches.

- [ ] **Step 5: Run the processor + whitespace tests — expect GREEN**

Run: `php artisan test --filter=DocumentProcessorProcessTest`
Expected: PASS (the existing `->process()` tests still pass via the wrapper; the new whitespace test passes).

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Knowledge/DocumentProcessor.php tests/Unit/Services/Knowledge/DocumentProcessorProcessTest.php
git commit -m "refactor(knowledge): split DocumentProcessor into extractHtml/extract/chunk; fix block whitespace"
```

---

## Task 5: `ProcessKnowledgeItem` — gate, skip path, store clean text

**Files:**
- Modify: `app/Jobs/ProcessKnowledgeItem.php`
- Modify: `tests/Unit/Jobs/KnowledgeStatusFlowTest.php` (pass the gate arg)
- Modify: `tests/Unit/Jobs/ProcessKnowledgeItemIdempotencyTest.php` (mock extract+chunk; pass gate)
- Test: add a skip test to `tests/Unit/Jobs/KnowledgeStatusFlowTest.php`

- [ ] **Step 1: Write the failing skip test**

Append to `tests/Unit/Jobs/KnowledgeStatusFlowTest.php` (add `use App\Services\Knowledge\ContentSufficiency;` to imports):

```php
    public function test_process_job_marks_skipped_when_content_insufficient(): void
    {
        Queue::fake([GenerateEmbeddings::class]);

        $item = $this->makeItem();
        $item->update(['type' => 'webpage', 'content' => 'two words']); // below the hard floor

        (new ProcessKnowledgeItem($item))->handle(
            app(DocumentProcessor::class),
            app(KnowledgeItemWorkflow::class),
            app(ContentSufficiency::class),
        );

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::SkippedNoContent, $item->status);
        $this->assertSame('no_content', $item->metadata['skipped_reason'] ?? null);
        Queue::assertNotPushed(GenerateEmbeddings::class);
    }
```

- [ ] **Step 2: Run it — expect RED**

Run: `php artisan test --filter=test_process_job_marks_skipped_when_content_insufficient`
Expected: FAIL — `handle()` takes 2 args / no skip path.

- [ ] **Step 3: Rewrite `ProcessKnowledgeItem::handle()`**

Replace the `handle()` method in `app/Jobs/ProcessKnowledgeItem.php` (add `use App\Services\Knowledge\ContentSufficiency;`):

```php
    public function handle(DocumentProcessor $processor, KnowledgeItemWorkflow $workflow, ContentSufficiency $gate): void
    {
        Log::debug('[Knowledge] (NO $) Processing item', [
            'item_id' => $this->item->id,
            'type' => $this->item->type,
        ]);

        $workflow->markProcessing($this->item);

        try {
            $text = $processor->extract($this->item);

            if (! $gate->isSufficient($text)) {
                $this->markSkipped($workflow);

                return;
            }

            if (in_array($this->item->type, ['webpage', 'document'], true)) {
                $this->item->update(['content' => $text]);
            }

            $chunks = $processor->chunk($text);

            if ($chunks === []) {
                $this->markSkipped($workflow);

                return;
            }

            // Replace any prior chunk set atomically — guards the tries=3 retry
            // path from appending duplicates.
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
                KnowledgeChunk::insert($rows);
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

    private function markSkipped(KnowledgeItemWorkflow $workflow): void
    {
        $this->item->chunks()->delete();
        $this->item->forceFill([
            'metadata' => array_merge((array) $this->item->metadata, [
                'skipped_reason' => 'no_content',
                'skipped_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $workflow->markSkippedNoContent($this->item);

        Log::debug('[Knowledge] (NO $) Item skipped — no readable content', [
            'item_id' => $this->item->id,
        ]);
    }
```

- [ ] **Step 4: Update the two job tests' `handle()` calls to pass the gate**

In `tests/Unit/Jobs/KnowledgeStatusFlowTest.php`, change the call in `test_process_job_leaves_item_in_processing_until_embeddings_complete` and `test_process_job_catch_does_not_prematurely_mark_failed` from:

```php
        (new ProcessKnowledgeItem($item))->handle(
            app(DocumentProcessor::class),
            app(KnowledgeItemWorkflow::class),
        );
```

to (append the gate):

```php
        (new ProcessKnowledgeItem($item))->handle(
            app(DocumentProcessor::class),
            app(KnowledgeItemWorkflow::class),
            app(ContentSufficiency::class),
        );
```

(In `test_process_job_catch_...` the call is inside the `try` — same edit.)

- [ ] **Step 5: Update the idempotency test to mock `extract` + `chunk`**

In `tests/Unit/Jobs/ProcessKnowledgeItemIdempotencyTest.php` add `use App\Services\Knowledge\ContentSufficiency;`, then:

Replace the mock + call in `test_running_the_job_twice_does_not_duplicate_chunks`:

```php
        $processor = Mockery::mock(DocumentProcessor::class);
        $processor->shouldReceive('extract')->andReturn('Some sufficiently long content body for chunking here.');
        $processor->shouldReceive('chunk')->andReturn(['chunk-a', 'chunk-b']);

        (new ProcessKnowledgeItem($item))->handle($processor, app(KnowledgeItemWorkflow::class), app(ContentSufficiency::class));
        $this->assertSame(2, KnowledgeChunk::where('knowledge_item_id', $item->id)->count());

        $item->refresh()->forceFill(['status' => KnowledgeItemStatus::Pending])->save();
        (new ProcessKnowledgeItem($item))->handle($processor, app(KnowledgeItemWorkflow::class), app(ContentSufficiency::class));
```

Replace the mocks + calls in `test_chunk_content_matches_after_retry`:

```php
        $first = Mockery::mock(DocumentProcessor::class);
        $first->shouldReceive('extract')->andReturn('Some sufficiently long content body for chunking here.');
        $first->shouldReceive('chunk')->andReturn(['old-1', 'old-2']);
        (new ProcessKnowledgeItem($item))->handle($first, app(KnowledgeItemWorkflow::class), app(ContentSufficiency::class));

        $item->refresh()->forceFill(['status' => KnowledgeItemStatus::Pending])->save();

        $second = Mockery::mock(DocumentProcessor::class);
        $second->shouldReceive('extract')->andReturn('Some sufficiently long content body for chunking here.');
        $second->shouldReceive('chunk')->andReturn(['new-1', 'new-2', 'new-3']);
        (new ProcessKnowledgeItem($item))->handle($second, app(KnowledgeItemWorkflow::class), app(ContentSufficiency::class));
```

- [ ] **Step 6: Run the job tests — expect GREEN**

Run: `php artisan test --filter=KnowledgeStatusFlowTest && php artisan test --filter=ProcessKnowledgeItemIdempotencyTest`
Expected: PASS (incl. the new skip test).

- [ ] **Step 7: Full suite + commit**

Run: `php artisan test`
Expected: PASS.

```bash
git add app/Jobs/ProcessKnowledgeItem.php tests/Unit/Jobs/KnowledgeStatusFlowTest.php tests/Unit/Jobs/ProcessKnowledgeItemIdempotencyTest.php
git commit -m "feat(knowledge): gate processing; skip no-content items instead of failing"
```

---

## Task 6: `SiteCrawler` synchronous extraction + skip accounting

**Files:**
- Modify: `app/Services/Crawler/SiteCrawler.php`
- Create: `database/migrations/2026_05_30_000002_add_pages_skipped_no_content_to_crawl_sessions.php`
- Modify: `app/Models/CrawlSession.php`
- Test: `tests/Unit/Services/Crawler/SiteCrawlerTest.php` (add a SPA-skip test)

- [ ] **Step 1: Add the session counter migration**

Create `database/migrations/2026_05_30_000002_add_pages_skipped_no_content_to_crawl_sessions.php`:

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
        Schema::table('crawl_sessions', function (Blueprint $table): void {
            $table->unsignedInteger('pages_skipped_no_content')->default(0)->after('pages_skipped_unchanged');
        });
    }

    public function down(): void
    {
        Schema::table('crawl_sessions', function (Blueprint $table): void {
            $table->dropColumn('pages_skipped_no_content');
        });
    }
};
```

- [ ] **Step 2: Add the column to the model**

In `app/Models/CrawlSession.php`: add `'pages_skipped_no_content',` to `$fillable` (after `'pages_skipped_unchanged',`) and `'pages_skipped_no_content' => 'integer',` to `casts()`.

- [ ] **Step 3: Write the failing SPA-skip test**

Append to `tests/Unit/Services/Crawler/SiteCrawlerTest.php` (add `use App\Enums\KnowledgeItemStatus;` to imports):

```php
    public function test_javascript_rendered_shell_is_marked_skipped_no_content(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://spa.example.com']);
        $session = CrawlSession::factory()->forTenant($tenant)->create([
            'mode' => CrawlMode::Initial,
            'status' => CrawlSessionStatus::Running,
        ]);

        $clean = 'Tours Book Bhutan Tour Official Website for Book Bhutan Tour visit us today now here';
        $shell = '<html><body><div id="root">'.$clean.'</div><script>'.str_repeat('var x=1;', 800).'</script></body></html>';

        Http::fake([
            'https://spa.example.com/robots.txt' => Http::response('', 404),
            'https://spa.example.com/sitemap.xml' => Http::response(
                $this->sitemapWith(['https://spa.example.com/tours']),
                200,
            ),
            'https://spa.example.com/tours*' => Http::response($shell, 200),
        ]);

        $this->crawler->crawl($tenant, $session);

        $session->refresh();
        $item = KnowledgeItem::forTenant($tenant)->where('type', 'webpage')->firstOrFail();

        $this->assertSame(KnowledgeItemStatus::SkippedNoContent, $item->status);
        $this->assertSame('no_content', $item->metadata['skipped_reason'] ?? null);
        $this->assertSame(1, $session->pages_skipped_no_content);
        $this->assertSame(0, $session->pages_indexed);
        $this->assertSame(CrawlSessionStatus::Partial, $session->status);
        Bus::assertNotDispatched(ProcessKnowledgeItem::class);
    }
```

- [ ] **Step 4: Run it — expect RED**

Run: `php artisan test --filter=test_javascript_rendered_shell_is_marked_skipped_no_content`
Expected: FAIL — crawler still stores raw HTML, dispatches, no skip counter.

- [ ] **Step 5: Update `SiteCrawler`**

(a) Constructor — inject the processor + gate. Change the constructor signature to:

```php
    public function __construct(
        private readonly SitemapDiscoverer $discoverer,
        private readonly RobotsTxtPolicy $robotsTxt,
        private readonly UrlNormalizer $normalizer,
        private readonly UsageTracker $usage,
        private readonly DocumentProcessor $processor,
        private readonly ContentSufficiency $sufficiency,
    ) {
```

Add imports: `use App\Services\Knowledge\DocumentProcessor;` and `use App\Services\Knowledge\ContentSufficiency;`.

(b) In `crawl()`, update the counter-init block. Replace:

```php
            $pagesIndexed = 0;
            $pagesFailed = 0;
            $pagesSkippedBudget = 0;
            $pagesSkippedUnchanged = 0;
            $pagesDiscovered = 0;
            $emptyExtractCount = 0;
```

with (drop `$emptyExtractCount`, add `$pagesSkippedNoContent`):

```php
            $pagesIndexed = 0;
            $pagesFailed = 0;
            $pagesSkippedBudget = 0;
            $pagesSkippedUnchanged = 0;
            $pagesSkippedNoContent = 0;
            $pagesDiscovered = 0;
```

(c) Replace the block from `$body = $this->fetchBody($url);` through the `ProcessKnowledgeItem::dispatch` + `$pagesIndexed++;` + `($this->sleeper)($crawlDelay);` (current lines ~124-190) with:

```php
                $body = $this->fetchBody($url);
                if ($body === null) {
                    $pagesFailed++;

                    continue;
                }

                $contentHash = 'sha256:'.hash('sha256', $body);
                if ($existing && ($existing->metadata['content_hash'] ?? null) === $contentHash) {
                    $metadata = array_merge((array) $existing->metadata, [
                        'last_modified' => $headResult['last_modified'],
                        'etag' => $headResult['etag'],
                        'crawl_session_id' => $session->id,
                    ]);
                    $existing->update(['metadata' => $metadata]);
                    $pagesSkippedUnchanged++;

                    continue;
                }

                $cleanText = $this->processor->extractHtml($body);
                $title = $this->extractTitle($body) ?: $url;

                $attributes = [
                    'tenant_id' => $tenant->id,
                    'type' => 'webpage',
                    'url_normalized' => $normalized,
                ];
                $values = [
                    'title' => $title,
                    'source_url' => $url,
                    'content' => $cleanText,
                    'metadata' => [
                        'crawl_session_id' => $session->id,
                        'content_hash' => $contentHash,
                        'last_modified' => $headResult['last_modified'],
                        'etag' => $headResult['etag'],
                    ],
                ];

                if (! $this->sufficiency->isSufficient($cleanText, $body)) {
                    $values['status'] = KnowledgeItemStatus::SkippedNoContent;
                    $values['metadata']['skipped_reason'] = 'no_content';
                    $values['metadata']['skipped_at'] = now()->toIso8601String();
                    KnowledgeItem::updateOrCreate($attributes, $values);
                    $pagesSkippedNoContent++;
                    ($this->sleeper)($crawlDelay);

                    continue;
                }

                $values['status'] = KnowledgeItemStatus::Pending;
                $item = KnowledgeItem::updateOrCreate($attributes, $values);

                try {
                    ProcessKnowledgeItem::dispatch($item);
                } catch (\Throwable $e) {
                    Log::warning('[SiteCrawler] (NO $) Processing dispatch failed; continuing crawl', [
                        'tenant_id' => $tenant->id,
                        'item_id' => $item->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                $pagesIndexed++;

                ($this->sleeper)($crawlDelay);
```

> This removes the old `trim(strip_tags($body)) === ''` empty-guard entirely.

(d) Replace the session update + status `match` (current lines ~193-209) with:

```php
            $session->update([
                'pages_discovered' => $pagesDiscovered,
                'pages_indexed' => $pagesIndexed,
                'pages_failed' => $pagesFailed,
                'pages_skipped_budget' => $pagesSkippedBudget,
                'pages_skipped_unchanged' => $pagesSkippedUnchanged,
                'pages_skipped_no_content' => $pagesSkippedNoContent,
            ]);

            $status = match (true) {
                $pagesSkippedBudget > 0 => CrawlSessionStatus::Partial,
                $pagesIndexed === 0 && $pagesSkippedNoContent > 0 => CrawlSessionStatus::Partial,
                $pagesIndexed === 0 && $pagesFailed > 0 => CrawlSessionStatus::Failed,
                $pagesFailed > 0 && $pagesIndexed > 0 => CrawlSessionStatus::Partial,
                $pagesSkippedNoContent > 0 && $pagesIndexed > 0 => CrawlSessionStatus::Partial,
                default => CrawlSessionStatus::Completed,
            };
```

(e) Add `use App\Enums\KnowledgeItemStatus;` to the imports.

- [ ] **Step 6: Run crawler tests — expect GREEN**

Run: `php artisan test --filter=SiteCrawlerTest`
Expected: PASS — `test_happy_path_indexes_pages` (real pages, high ratio → Pending/dispatched), `test_diff_skip` (dedup before gate), `test_budget_cap`, and the new SPA-skip test.

- [ ] **Step 7: Full suite + commit**

Run: `php artisan test`
Expected: PASS.

```bash
git add app/Services/Crawler/SiteCrawler.php app/Models/CrawlSession.php database/migrations/2026_05_30_000002_add_pages_skipped_no_content_to_crawl_sessions.php tests/Unit/Services/Crawler/SiteCrawlerTest.php
git commit -m "feat(crawler): extract+gate pages synchronously; mark JS shells SkippedNoContent"
```

---

## Task 7: Remove temporary `process()` + migrate its test call sites

**Files:**
- Modify: `app/Services/Knowledge/DocumentProcessor.php`
- Modify: `tests/Unit/Services/Knowledge/DocumentProcessorProcessTest.php`
- Modify: `tests/Unit/Services/DocumentProcessorFetchTest.php`
- Modify: `tests/Unit/Services/Knowledge/DocumentProcessorDocxTest.php`

- [ ] **Step 1: Migrate every `->process(` call site**

In all three test files, replace each `$processor->process($item)` (and `app(DocumentProcessor::class)->process($item)`) with the two-call form `$processor->chunk($processor->extract($item))` (respectively `app(DocumentProcessor::class)->chunk(app(DocumentProcessor::class)->extract($item))`). The 13 sites are listed in Task 0.

- [ ] **Step 2: Update the stored-webpage test to the new clean-text contract**

In `tests/Unit/Services/Knowledge/DocumentProcessorProcessTest.php`, replace `test_process_webpage_with_stored_content_does_not_fetch_url` body so `content` holds CLEAN text (the crawler now stores cleaned text, not HTML):

```php
    public function test_process_webpage_with_stored_content_does_not_fetch_url(): void
    {
        // Crawler stores CLEAN text in $item->content; extract() must reuse it
        // verbatim instead of re-fetching $item->source_url.
        Http::fake(['*' => Http::response('SHOULD_NOT_BE_CALLED', 200)]);
        Http::preventStrayRequests();

        $processor = new DocumentProcessor;
        $clean = 'Stored clean page text that is long enough to exceed the minimum chunk threshold for this test.';
        $item = $this->makeItem([
            'type' => 'webpage',
            'source_url' => 'https://example.com/about',
            'content' => $clean,
        ]);

        $chunks = $processor->chunk($processor->extract($item));

        $this->assertNotEmpty($chunks);
        Http::assertNothingSent();
    }
```

- [ ] **Step 3: Remove the `process()` wrapper**

In `app/Services/Knowledge/DocumentProcessor.php`, delete the temporary `process()` method (and its `@deprecated` docblock).

- [ ] **Step 4: Verify no remaining callers**

Run: `grep -rn '\->process(' app tests`
Expected: no matches.

- [ ] **Step 5: Run the affected tests — expect GREEN**

Run: `php artisan test --filter=DocumentProcessor`
Expected: PASS.

- [ ] **Step 6: Full suite + commit**

Run: `php artisan test`
Expected: PASS.

```bash
git add app/Services/Knowledge/DocumentProcessor.php tests/Unit/Services/Knowledge/DocumentProcessorProcessTest.php tests/Unit/Services/DocumentProcessorFetchTest.php tests/Unit/Services/Knowledge/DocumentProcessorDocxTest.php
git commit -m "refactor(knowledge): remove temporary DocumentProcessor::process wrapper"
```

---

## Task 8: Honest crawl reporting (banner + status payload)

**Files:**
- Modify: `app/Http/Controllers/Client/WebsiteIndexingController.php`
- Modify: `resources/js/Components/IndexingStatusBanner.vue`
- Test: `tests/Feature/Client/` (add/extend a latest-status test if one exists; otherwise a focused new one)

- [ ] **Step 1: Write the failing payload test**

Create `tests/Feature/Client/IndexingStatusSkippedTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Models\CrawlSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexingStatusSkippedTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_status_exposes_pages_skipped_no_content(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->for($tenant)->create();

        CrawlSession::factory()->forTenant($tenant)->create([
            'mode' => CrawlMode::Manual,
            'status' => CrawlSessionStatus::Partial,
            'pages_indexed' => 2,
            'pages_skipped_no_content' => 3,
        ]);

        $response = $this->actingAs($user)->getJson(route('widget.indexing.status'));

        $response->assertOk()->assertJsonPath('session.pages_skipped_no_content', 3);
    }
}
```

> If `User::factory()->for($tenant)` is not the project's convention, mirror the user-creation pattern used in an existing `tests/Feature/Client/*` test for an authenticated tenant user.

- [ ] **Step 2: Run it — expect RED**

Run: `php artisan test --filter=IndexingStatusSkippedTest`
Expected: FAIL — key `pages_skipped_no_content` missing from the payload.

- [ ] **Step 3: Add the field to the payload**

In `app/Http/Controllers/Client/WebsiteIndexingController.php`, inside `latestStatus()`'s JSON `session` array, add after `'pages_skipped_budget' => $session->pages_skipped_budget,`:

```php
                'pages_skipped_no_content' => $session->pages_skipped_no_content,
```

- [ ] **Step 4: Add the banner branch**

In `resources/js/Components/IndexingStatusBanner.vue`, in the `partial` case of the `banner` computed, insert before the final `return` (the generic "some pages could not be processed"):

```javascript
      if (s.pages_skipped_no_content > 0) {
        return {
          tone: 'warning',
          text: `Indexed ${s.pages_indexed} pages — ${s.pages_skipped_no_content} had no readable content (the site may be JavaScript-rendered).`,
          link: { href: `/knowledge?crawl_session_id=${s.id}`, label: 'View' },
        }
      }
```

- [ ] **Step 5: Run it — expect GREEN, then build the frontend**

Run: `php artisan test --filter=IndexingStatusSkippedTest`
Expected: PASS.
Run: `pnpm run build`
Expected: builds without errors.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Client/WebsiteIndexingController.php resources/js/Components/IndexingStatusBanner.vue tests/Feature/Client/IndexingStatusSkippedTest.php
git commit -m "feat(crawler): surface skipped-no-content count in indexing banner"
```

---

## Task 9: Knowledge Base dashboard — badge + guidance for `SkippedNoContent`

**Files:**
- Modify: `resources/js/Pages/Client/KnowledgeBase/Index.vue`
- Modify: `resources/js/Pages/Client/KnowledgeBase/Show.vue`

> No PHP test surface (no Vue unit tests in this repo). Verified by `pnpm run build` + the browser smoke in Task 10.

- [ ] **Step 1: Index.vue — variant + label + guidance**

(a) In `getStatusVariant`, add `skipped_no_content: 'warning',` to the map.

(b) Add a label helper after `getStatusVariant`:

```javascript
const getStatusLabel = (status) => {
  return {
    skipped_no_content: 'No content',
  }[status] || status
}
```

(c) Change the badge text from `{{ item.status }}` to `{{ getStatusLabel(item.status) }}`.

(d) After the existing failure-detail `<div v-if="item.status === 'failed' ...">` block, add a guidance row:

```html
              <!-- No-content (JS-rendered) guidance row -->
              <div
                v-if="item.status === 'skipped_no_content'"
                class="mt-3 ml-12 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm"
              >
                <div class="flex items-start gap-2">
                  <AlertCircle class="h-4 w-4 mt-0.5 flex-shrink-0 text-amber-600" />
                  <p class="text-amber-900">
                    This page is rendered by JavaScript — no readable text was found. Add its content manually, or wait for site-rendering support.
                  </p>
                </div>
              </div>
```

- [ ] **Step 2: Show.vue — variant + label + guidance**

(a) In `getStatusVariant`, add `skipped_no_content: 'warning',`.

(b) Add the same `getStatusLabel` helper after `getStatusVariant`.

(c) Change the header badge from `{{ item.status }}` to `{{ getStatusLabel(item.status) }}`.

(d) In the details `<dl>`, add a guidance row as the first child (before the Type row):

```html
            <div v-if="item.status === 'skipped_no_content'" class="px-6 py-4 bg-amber-50">
              <p class="text-sm text-amber-900">
                This page is rendered by JavaScript — no readable text was found. Add its content manually, or wait for site-rendering support.
              </p>
            </div>
```

- [ ] **Step 3: Build**

Run: `pnpm run build`
Expected: builds without errors.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Client/KnowledgeBase/Index.vue resources/js/Pages/Client/KnowledgeBase/Show.vue
git commit -m "feat(knowledge): show No-content status badge + guidance in dashboard"
```

---

## Task 10: Verification & wrap-up

- [ ] **Step 1: Full suite**

Run: `php artisan test`
Expected: all green.

- [ ] **Step 2: Static analysis**

Run: `./vendor/bin/phpstan analyse`
Expected: 0 errors (baseline stays at zero — add `@property KnowledgeItemStatus` PHPDoc only if PHPStan flags the new enum case).

- [ ] **Step 3: Pint (scoped to touched files)**

Run: `./vendor/bin/pint --test`
If anything flagged: `./vendor/bin/pint`, then `php artisan test`, then commit `style(pint): apply auto-fixes`.

- [ ] **Step 4: Browser smoke (Layer 3)**

Start the app (`composer dev` or the dev server + `crawls` queue worker). Set a tenant's website to a JS-rendered site (e.g. `https://bookbhutantour.com`), trigger a crawl, and confirm in `/dashboard/.../knowledge`:
- pages show the amber **"No content"** badge + guidance row;
- the indexing banner reports the skipped count;
- a server-rendered site still indexes normally with readable chunks (open an item → Show page → chunks are clean text, no HTML).

- [ ] **Step 5: `/simplify` → Pint → `/simplify` → Pint** per the project process, then open the PR.

---

## Phase 2 (separate plan, NOT in this PR)

`spatie/browsershot` (Node + Chromium); `PageRenderer.render(url): ?string` with HTTP fallback; config `CRAWLER_JS_RENDERING`. Render-on-fallback: when the gate fails on the HTTP body, render the page and re-extract before marking `SkippedNoContent`. Re-process previously-skipped items (found via `metadata.skipped_reason = 'no_content'`), bypassing the `content_hash` skip once. Revisit whether `markFailed` should forbid `SkippedNoContent` as a source.

## Known Phase-1 limitations (documented)

- **Manual single-URL adds of a SPA** are gated by word-count only (the job doesn't retain the fetched raw HTML for the ratio check), so a ~16-word SPA shell added manually may index as boilerplate. The crawl path — the reported bug — has full ratio gating. Phase 2 rendering closes this.
- **Existing dev webpage items** hold raw HTML in `content` (pre-change). Re-crawl (or `migrate:fresh`) to repopulate them with clean text. Pre-prod, so no production backfill needed.
- A genuinely thin server-rendered page (very low text, heavy markup) may trip the SPA ratio and be marked `SkippedNoContent`; the merchant is told and can add content manually. Phase 2 rendering won't lengthen such pages.
