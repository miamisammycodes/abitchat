# Knowledge / RAG Quality — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close four medium-severity knowledge/RAG-quality findings from the May 2026 audits — DOCX extractor word-merging (M-NEW-11), vector dimension mismatch crashing pgvector (M-NEW-8), `GenerateEmbeddings` materializing all chunks (OOM on big imports) (M-NEW-9), and sync-queue + slow webpage producing user-visible 500s (M9).

**Architecture:** Each fix lives in a single file:
- M-NEW-11: `DocumentProcessor::extractFromDocx` replaces OOXML word-break tags with spaces/newlines before stripping
- M-NEW-8: `EmbeddingService::generate` validates `count($vector) === self::DIMENSIONS` post-call; mismatch → `EmbeddingGenerationException`
- M-NEW-9: `GenerateEmbeddings::handle` uses `lazyById()` so the chunk set streams instead of materializing
- M9: `ProcessKnowledgeItem::dispatch(...)` becomes `dispatchAfterResponse(...)` at all three call sites so the HTTP response goes out before the job runs (eliminates sync-queue hang)

**Tech Stack:** Laravel 13+, PHP 8.3+, Pest/PHPUnit. SQLite in tests (the embedding column becomes plain `text` on SQLite per the migration's driver branch). Prism for embeddings (Ollama in dev, model-configurable). The `Prism\Prism\Testing\EmbeddingsResponseFake` is the project's existing way to mock embedding responses.

**Spec reference:** `docs/superpowers/specs/2026-05-12-medium-backlog-design.md` — Cluster 4.

**Order rationale:** simplest/most-isolated first. M-NEW-11 touches one private method, no dispatch semantics. M-NEW-8 adds one guard, one test. M-NEW-9 is a single-line `get()` → `lazyById()->each(...)`. M9 changes dispatch semantics across 3 call sites — riskier so it ships last code-task.

---

## Task 0: Verification pass against current `main`

- [ ] **Step 1: Verify M-NEW-11 — DOCX uses strip_tags directly on OOXML**

```bash
grep -n "extractFromDocx\|strip_tags" app/Services/Knowledge/DocumentProcessor.php
```
Expected: `extractFromDocx` calls `strip_tags($xmlContent)` directly with no pre-replacement for `</w:t>` or `</w:p>`.

- [ ] **Step 2: Verify M-NEW-8 — no dimension validation**

```bash
grep -nE "DIMENSIONS|count\(\\\$vector\)" app/Services/Knowledge/EmbeddingService.php
```
Expected: `DIMENSIONS = 768` constant exists at the top; no `count($vector)` check anywhere in `generate()`.

- [ ] **Step 3: Verify M-NEW-9 — chunks loaded eagerly**

```bash
grep -n "chunks().*->get()\|lazyById\|->chunk(" app/Jobs/GenerateEmbeddings.php
```
Expected: `$chunks = $this->item->chunks()->whereNull('embedding')->get();` with no lazy iteration.

- [ ] **Step 4: Verify M9 — uses dispatch() not dispatchAfterResponse**

```bash
grep -n "ProcessKnowledgeItem::dispatch" app/Http/Controllers/Client/KnowledgeBaseController.php
```
Expected: three call sites in `store()`, `update()`, `reprocess()`, all using `::dispatch(...)`.

- [ ] **Step 5: Verify M9 scope — confirm no non-HTTP callers of ProcessKnowledgeItem**

`dispatchAfterResponse` registers a `terminating()` callback that only fires for HTTP responses. A console command or scheduled task that dispatches `ProcessKnowledgeItem` would silently never run after the switch. Confirm hits are exclusively in HTTP controllers:

```bash
grep -rn "ProcessKnowledgeItem::dispatch\|ProcessKnowledgeItem::dispatchAfterResponse" --include='*.php' app/
```
Expected: hits ONLY in `app/Http/Controllers/Client/KnowledgeBaseController.php`. If any non-controller (console command, scheduled task, another job) appears, leave those on plain `dispatch` and limit Task 4's switch to the controller sites.

- [ ] **Step 6: Proceed**

No commit. All four findings live.

---

## Task 1: M-NEW-11 — DOCX word boundary fix

**Goal:** Adjacent `<w:t>` runs in OOXML must be space-separated after extraction so that `<w:t>price</w:t><w:t>list</w:t>` becomes `price list`, not `pricelist`. The current `strip_tags` removes tags but doesn't insert separators.

**Files:**
- Modify: `app/Services/Knowledge/DocumentProcessor.php` (private `extractFromDocx`, currently lines 89–106)
- Test: `tests/Unit/Services/Knowledge/DocumentProcessorDocxTest.php` (new file)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/Knowledge/DocumentProcessorDocxTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Services\Knowledge\DocumentProcessor;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentProcessorDocxTest extends TestCase
{
    /**
     * Build a minimal DOCX file (a ZIP containing word/document.xml) and
     * return the relative path on the local disk.
     */
    private function makeDocx(string $bodyXml): string
    {
        Storage::fake('local');
        $tmp = tempnam(sys_get_temp_dir(), 'docxtest_') . '.docx';
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE);
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $bodyXml . '</w:body>'
            . '</w:document>';
        $zip->addFromString('word/document.xml', $document);
        $zip->close();

        $relative = 'docxtest/' . basename($tmp);
        Storage::disk('local')->put($relative, file_get_contents($tmp));
        unlink($tmp);

        return $relative;
    }

    public function test_adjacent_text_runs_get_space_separated(): void
    {
        $bodyXml = '<w:p><w:r><w:t>price</w:t></w:r><w:r><w:t>list</w:t></w:r></w:p>';
        $path = $this->makeDocx($bodyXml);

        $text = app(DocumentProcessor::class)->extractFromFile($path);

        $this->assertStringContainsString('price list', $text);
        $this->assertStringNotContainsString('pricelist', $text);
    }

    public function test_paragraphs_are_newline_separated(): void
    {
        $bodyXml = '<w:p><w:r><w:t>First paragraph.</w:t></w:r></w:p>'
                 . '<w:p><w:r><w:t>Second paragraph.</w:t></w:r></w:p>';
        $path = $this->makeDocx($bodyXml);

        $text = app(DocumentProcessor::class)->extractFromFile($path);

        $this->assertStringContainsString('First paragraph.', $text);
        $this->assertStringContainsString('Second paragraph.', $text);
        $this->assertStringNotContainsString('First paragraph.Second paragraph.', $text);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DocumentProcessorDocxTest`
Expected: `test_adjacent_text_runs_get_space_separated` FAILS — extracted text contains `pricelist`.

- [ ] **Step 3: Insert word-break separators before strip_tags**

In `app/Services/Knowledge/DocumentProcessor.php`, replace the `extractFromDocx` method (lines 89–106):

```php
    private function extractFromDocx(string $path): string
    {
        $content = '';

        $zip = new \ZipArchive;

        if ($zip->open($path) === true) {
            $xmlContent = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xmlContent) {
                // OOXML splits text across <w:t> runs; strip_tags alone would
                // merge adjacent runs into one word. Insert a space at every
                // </w:t> and a newline at every </w:p> before stripping.
                $xmlContent = str_replace(['</w:t>', '</w:p>'], [' ', "\n"], $xmlContent);
                $content = strip_tags($xmlContent);
            }
        }

        return $this->cleanText($content);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=DocumentProcessorDocxTest`
Expected: both PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Knowledge/DocumentProcessor.php tests/Unit/Services/Knowledge/DocumentProcessorDocxTest.php
git commit -m "$(cat <<'EOF'
fix(rag): preserve word boundaries when extracting from DOCX (M-NEW-11)

OOXML splits text across <w:t> runs within <w:r> elements; strip_tags
alone merged "price" + "list" into "pricelist", causing RAG to
silently miss those terms. Insert a space at every </w:t> and a
newline at every </w:p> before stripping.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: all green (272 baseline + 2 new = 274).

---

## Task 2: M-NEW-8 — Vector dimension validation

**Goal:** `EmbeddingService::generate()` must reject vectors whose dimensionality doesn't match `self::DIMENSIONS` (768). Some Ollama builds return 384-dim from `nomic-embed-text`, which would crash pgvector's `vector(768)` column with an opaque error. Surface the mismatch as `EmbeddingGenerationException` so callers can fall back (retrieval already has a keyword-search fallback path).

**Files:**
- Modify: `app/Services/Knowledge/EmbeddingService.php` (`generate` method)
- Test: `tests/Unit/Services/Knowledge/EmbeddingServiceConfigTest.php` (extend existing class)

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Services/Knowledge/EmbeddingServiceConfigTest.php` inside the existing class:

```php
    public function test_throws_when_provider_returns_wrong_dimension_vector(): void
    {
        // Simulate Ollama returning a 384-dim vector when 768 is expected.
        $wrongDimVector = array_fill(0, 384, 0.01);
        Prism::fake([
            EmbeddingsResponseFake::make()->withEmbeddings([$wrongDimVector]),
        ]);

        $this->expectException(EmbeddingGenerationException::class);
        $this->expectExceptionMessageMatches('/dimension/i');
        app(EmbeddingService::class)->generate('hello world');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_throws_when_provider_returns_wrong_dimension_vector`
Expected: FAIL — no dimension check; the wrong-dim vector flows through to `toPgVector` which returns a malformed-for-pgvector literal.

- [ ] **Step 3: Add dimension validation in `generate`**

In `app/Services/Knowledge/EmbeddingService.php`, locate the existing empty-vector guard (around lines 57–65) and add a dimension check directly after it. The full block becomes:

```php
        $vector = $response->embeddings[0]->embedding ?? null;

        if (! is_array($vector) || $vector === []) {
            Log::error('[Embeddings] Provider returned empty vector', [
                'provider' => $providerName,
                'model' => $model,
            ]);
            throw new EmbeddingGenerationException(
                "Embedding provider {$providerName} returned no vector",
            );
        }

        if (count($vector) !== self::DIMENSIONS) {
            Log::error('[Embeddings] Provider returned wrong-dimension vector', [
                'provider' => $providerName,
                'model' => $model,
                'expected_dimensions' => self::DIMENSIONS,
                'actual_dimensions' => count($vector),
            ]);
            throw new EmbeddingGenerationException(
                "Embedding provider {$providerName} returned " . count($vector)
                . "-dimension vector; expected " . self::DIMENSIONS,
            );
        }

        return self::toPgVector($vector);
```

- [ ] **Step 4: Run tests to verify**

Run: `php artisan test --filter=EmbeddingServiceConfigTest`
Expected: 3 passing (existing 2 + new 1).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Knowledge/EmbeddingService.php tests/Unit/Services/Knowledge/EmbeddingServiceConfigTest.php
git commit -m "$(cat <<'EOF'
fix(rag): validate embedding dimension before insert (M-NEW-8)

Some Ollama builds return 384-dim from nomic-embed-text while the
column is vector(768). pgvector throws on insert with an opaque
error. Validate the dimension at the EmbeddingService boundary and
surface as EmbeddingGenerationException, which the retrieval path
already handles via keyword-search fallback.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: all green (275 = 274 + 1 new).

---

## Task 3: M-NEW-9 — Lazy chunk iteration in `GenerateEmbeddings`

**Goal:** Stream chunks via `lazyById()` instead of materializing the whole set with `->get()`. Large PDF imports (1000s of chunks) currently OOM the worker; `tries=3` means it OOMs three times before failing the item.

**Files:**
- Modify: `app/Jobs/GenerateEmbeddings.php` (`handle` method)
- Test: `tests/Unit/Jobs/GenerateEmbeddingsLazyTest.php` (new file)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Jobs/GenerateEmbeddingsLazyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateEmbeddings;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Services\Knowledge\EmbeddingService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Tests\TestCase;

class GenerateEmbeddingsLazyTest extends TestCase
{
    public function test_processes_all_chunks_and_marks_item_ready(): void
    {
        $tenant = Tenant::create([
            'name' => 'KB', 'slug' => 'kb-' . uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);

        $item = KnowledgeItem::create([
            'tenant_id' => $tenant->id,
            'type' => 'text',
            'title' => 'Lazy test',
            'content' => 'x',
            'status' => 'processing',
        ]);

        // Create more chunks than would comfortably fit in a single in-memory
        // collection if we cared about that constraint — small enough to keep
        // the test fast.
        $now = now();
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = [
                'knowledge_item_id' => $item->id,
                'content' => "chunk {$i}",
                'chunk_index' => $i,
                'embedding' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        KnowledgeChunk::insert($rows);

        // Fake 30 embedding responses with valid 768-dim vectors.
        $fakes = [];
        for ($i = 0; $i < 30; $i++) {
            $fakes[] = EmbeddingsResponseFake::make()->withEmbeddings([array_fill(0, 768, 0.01)]);
        }
        Prism::fake($fakes);

        (new GenerateEmbeddings($item))->handle(app(EmbeddingService::class));

        $item->refresh();
        $this->assertSame('ready', $item->status);
        $this->assertSame(
            0,
            $item->chunks()->whereNull('embedding')->count(),
            'every chunk must have its embedding populated after the job runs',
        );
    }
}
```

- [ ] **Step 2: Run test to verify it passes against the current (eager) implementation**

Run: `php artisan test --filter=GenerateEmbeddingsLazyTest`
Expected: PASS today via the eager `->get()` path. This is a **non-regression guard** that locks correct behavior so the lazy refactor in Step 3 preserves semantics.

- [ ] **Step 3: Switch to `lazyById` iteration**

In `app/Jobs/GenerateEmbeddings.php`, replace the `handle` method:

```php
    public function handle(EmbeddingService $embeddingService): void
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

            $this->item->markAsReady();

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
```

Note the per-chunk `whereNull('embedding')` predicate combined with `lazyById` means once a chunk gets its embedding written, it falls out of subsequent batch fetches. The `lazyById` cursor pages by primary key, fetching 50 rows at a time.

- [ ] **Step 4: Run tests to verify they still pass**

Run: `php artisan test --filter=GenerateEmbeddingsLazyTest`
Expected: PASS — all 30 chunks have embeddings after the run.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/GenerateEmbeddings.php tests/Unit/Jobs/GenerateEmbeddingsLazyTest.php
git commit -m "$(cat <<'EOF'
perf(rag): stream chunks via lazyById in GenerateEmbeddings (M-NEW-9)

The eager ->get() materialized every NULL-embedding chunk into a
single in-memory collection. Large PDF imports OOM'd the worker;
tries=3 meant three OOMs before marking the item failed. lazyById
pages by primary key (50/page) so memory stays bounded regardless
of chunk count.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: 276 passing (275 + 1 new).

---

## Task 4: M9 — `dispatchAfterResponse` for ProcessKnowledgeItem

**Goal:** When `QUEUE_CONNECTION=sync` (dev or misconfigured prod), `ProcessKnowledgeItem::dispatch` runs synchronously in the HTTP request. For URL-type knowledge items, this triggers `DocumentProcessor::extractFromUrl` which does a 30s-timeout HTTP request — long-running webpages produce a user-visible 500 / timeout. Switching to `dispatchAfterResponse` makes Laravel send the HTTP response *before* running the job, regardless of queue driver. Real queue drivers (Redis, DB) treat it the same as `dispatch`; sync driver no longer blocks the request.

**Files:**
- Modify: `app/Http/Controllers/Client/KnowledgeBaseController.php` (three call sites: `store`, `update`, `reprocess`)
- Test: `tests/Feature/KnowledgeBaseTest.php` (add one method) or new file if cleaner

- [ ] **Step 1: Write the failing test**

Decide on placement: `tests/Feature/KnowledgeBaseTest.php` exists from the original H1 hardening. Look at its setUp before deciding — if it uses `actingAsTenantUser()` and creates plans, append. Otherwise create a new file.

Create `tests/Feature/Client/KnowledgeQueueDispatchTest.php` (new file, isolates this concern):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Jobs\ProcessKnowledgeItem;
use App\Models\Plan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeQueueDispatchTest extends TestCase
{
    private function makePlan(): Plan
    {
        return Plan::create([
            'name' => 'P', 'slug' => 'p-' . uniqid(),
            'price' => 0, 'billing_period' => 'monthly',
            'conversations_limit' => 100,
            'leads_limit' => 50,
            'tokens_limit' => 10000,
            'knowledge_items_limit' => 100,
            'is_active' => true,
        ]);
    }

    public function test_text_item_dispatches_process_job_after_response(): void
    {
        Bus::fake();
        $this->actingAsTenantUser();
        $plan = $this->makePlan();
        $this->tenant->update(['plan_id' => $plan->id, 'plan_expires_at' => now()->addMonth()]);

        $this->post(route('client.knowledge.store'), [
            'type' => 'text',
            'title' => 'Smoke',
            'content' => 'hello world',
        ])->assertRedirect();

        Bus::assertDispatchedAfterResponse(ProcessKnowledgeItem::class);
    }
}
```

`BusFake` keeps separate registers for regular and after-response dispatches (`$commands` vs `$commandsAfterResponse`). `assertDispatchedAfterResponse` only checks the second, so the test fails today (regular `dispatch` populates only the first) and passes after Task 4's switch.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_text_item_dispatches_process_job_after_response`
Expected: FAIL — `assertDispatchedAfterResponse` won't match because the current code uses regular `dispatch`.

- [ ] **Step 3: Switch the three call sites to `dispatchAfterResponse`**

In `app/Http/Controllers/Client/KnowledgeBaseController.php`, find and change:

1. Line ~105 in `store()`: `ProcessKnowledgeItem::dispatch($item);` → `ProcessKnowledgeItem::dispatchAfterResponse($item);`
2. Line ~174 in `update()`: same change
3. Line ~213 in `reprocess()`: same change

All three sites have identical shape. After the change, grep should show zero remaining plain `ProcessKnowledgeItem::dispatch(` in this controller:

```bash
grep -n "ProcessKnowledgeItem::dispatch" app/Http/Controllers/Client/KnowledgeBaseController.php
```
Expected: three hits, all `dispatchAfterResponse`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_text_item_dispatches_process_job_after_response`
Expected: PASS.

- [ ] **Step 5: Run the existing knowledge tests to ensure no regression**

Run: `php artisan test tests/Feature/KnowledgeBaseTest.php tests/Unit/Jobs`
Expected: all green. Pre-existing knowledge tests assert dispatch happens via `Bus::assertDispatched` which matches both `dispatch` and `dispatchAfterResponse`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Client/KnowledgeBaseController.php tests/Feature/Client/KnowledgeQueueDispatchTest.php
git commit -m "$(cat <<'EOF'
fix(knowledge): dispatch processing after HTTP response (M9)

ProcessKnowledgeItem::dispatch ran synchronously under
QUEUE_CONNECTION=sync, so submitting a webpage URL caused
DocumentProcessor::extractFromUrl's 30s HTTP fetch to block the
client request. dispatchAfterResponse makes Laravel send the
response first regardless of queue driver — real queue drivers
treat it the same as dispatch.

🤖 Generated with Claude Code
Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: 277 passing (276 + 1 new).

---

## Task 5: Browser smoke, Pint, /simplify, PR

Per CLAUDE.md's updated workflow (Pint → /simplify → Pint → /simplify → PR), with browser smoke first.

- [ ] **Step 1: Boot dev environment**

```bash
php artisan serve --port=8001
npm run dev
```

- [ ] **Step 2: Browser smoke — DOCX upload preserves word boundaries**

Create a small `.docx` file with text like "Please find the price list attached." Upload via `/knowledge/create` (Document type). After processing completes, visit the item's show page and confirm the extracted content shows `price list` (space-separated), not `pricelist`.

If creating a real `.docx` is friction, skip — the Pest test exercises the OOXML word-break path directly with a built-in-test ZIP and is sufficient.

- [ ] **Step 3: Browser smoke — sync-queue + URL submission no longer hangs**

Verify `QUEUE_CONNECTION` in `.env`:
```bash
grep QUEUE_CONNECTION .env
```

If `sync`, this smoke is meaningful. Submit a webpage URL knowledge item via `/knowledge/create` (Webpage type). The form submission should redirect back to `/knowledge` immediately (under 1s) with a "Knowledge item added and is being processed" flash, even if the URL is slow. Without the fix, this would hang for up to 30s.

Quick way to test: use a URL pointing to a slow-loading or invalid endpoint. Time the response.

- [ ] **Step 4: `/simplify` and Pint** (per CLAUDE.md workflow)

Run Pint pass 1:
```bash
./vendor/bin/pint --test \
  app/Services/Knowledge/DocumentProcessor.php \
  app/Services/Knowledge/EmbeddingService.php \
  app/Jobs/GenerateEmbeddings.php \
  app/Http/Controllers/Client/KnowledgeBaseController.php \
  tests/Unit/Services/Knowledge/DocumentProcessorDocxTest.php \
  tests/Unit/Services/Knowledge/EmbeddingServiceConfigTest.php \
  tests/Unit/Jobs/GenerateEmbeddingsLazyTest.php \
  tests/Feature/Client/KnowledgeQueueDispatchTest.php
```
Apply fixes if flagged; commit as `style(pint): apply auto-fixes to cluster-4 files`.

Run `/simplify` pass 1. Apply substantive fixes.

Run Pint pass 2 (same command). Fix if anything.

Run `/simplify` pass 2.

Run the full suite once more: `php artisan test` — expect 277 green.

- [ ] **Step 5: Open the PR**

```bash
git push -u origin HEAD
gh pr create --title "fix(rag): close cluster-4 knowledge & RAG quality findings" --body "$(cat <<'EOF'
## Summary

Cluster 4 of the medium-backlog spec — knowledge / RAG quality.

- **M-NEW-11** — DOCX extractor inserts spaces at \`</w:t>\` and newlines at \`</w:p>\` before \`strip_tags\`, so adjacent OOXML text runs no longer merge into one word. RAG no longer silently misses terms like "price list" when the source DOCX split them across runs.
- **M-NEW-8** — \`EmbeddingService::generate\` validates the returned vector's length against \`self::DIMENSIONS\` (768). Wrong-dim vectors (some Ollama builds return 384) throw \`EmbeddingGenerationException\` instead of crashing pgvector with an opaque error. \`RetrievalService\` already handles this exception with a keyword-search fallback.
- **M-NEW-9** — \`GenerateEmbeddings\` streams chunks via \`lazyById(50)\` instead of materializing every NULL-embedding chunk. Large imports no longer OOM the worker.
- **M9** — \`ProcessKnowledgeItem::dispatch\` → \`dispatchAfterResponse\` at all three call sites in \`KnowledgeBaseController\`. Sync-queue mode no longer blocks the HTTP response on slow webpage extraction.

## Deploy steps

1. Merge.
2. No migrations.
3. Recommend a configuration audit: confirm \`QUEUE_CONNECTION\` is NOT \`sync\` in production. The M9 fix protects against sync misconfiguration but real queue drivers remain the right operational setup.

## ⚠️ Behavior changes

| Change | Who's affected | Mitigation |
|---|---|---|
| DOCX extracted text inserts whitespace at OOXML boundaries | Tenants with DOCX knowledge items whose OOXML split words across runs | None — bug fix; RAG now matches terms it silently missed |
| Embedding service throws on dimension mismatch | Tenants on Ollama builds returning non-768 vectors | Retrieval falls back to keyword search; admins should re-embed once Ollama config is fixed |
| \`GenerateEmbeddings\` streams chunks | None — memory usage drops on large items | None |
| Knowledge create/update/reprocess responses now return before processing job runs | None visible — under sync queue the response is now <1s instead of up to 30s | None |

## Test plan

- [x] \`php artisan test\` — 277 passing
- [x] Pint clean on PR-touched files (×2)
- [x] \`/simplify\` ×2
- [x] Browser smoke: webpage URL submission returns quickly under sync queue
- [x] \`DocumentProcessorDocxTest\` — adjacent text runs space-separated, paragraphs newline-separated
- [x] \`EmbeddingServiceConfigTest::test_throws_when_provider_returns_wrong_dimension_vector\`
- [x] \`GenerateEmbeddingsLazyTest::test_processes_all_chunks_and_marks_item_ready\`
- [x] \`KnowledgeQueueDispatchTest::test_text_item_dispatches_process_job_after_response\`

## Architecture notes

- The DOCX fix is intentionally narrow: a string-level replacement before \`strip_tags\`. A full OOXML parser (DOMDocument / phpoffice) would be more robust for complex documents but is out of scope.
- The dimension check uses \`EmbeddingService::DIMENSIONS\` as the single source of truth. The migration's \`vector(768)\` column was hardcoded but is documented in the schema comment.
- \`dispatchAfterResponse\` is a drop-in replacement: real queue drivers treat it identically to \`dispatch\`, while sync-driver users get a deferred run after the HTTP response sends.

## Links

- Spec: \`docs/superpowers/specs/2026-05-12-medium-backlog-design.md\` (Cluster 4)
- Plan: \`docs/superpowers/plans/2026-05-14-knowledge-rag-quality.md\`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 6: Update memory after merge**

Save a memory entry capturing:
- Cluster 4 closed (M9, M-NEW-8, M-NEW-9, M-NEW-11)
- New invariants: dimension check at the EmbeddingService boundary; OOXML extraction inserts word breaks; knowledge job dispatch always uses `dispatchAfterResponse`
- Cluster 5 (misc operational) still not drafted; spec lists items but needs verify-first task 0 for M3

---

## Out of scope

- A full OOXML DOM parser (e.g., phpoffice/phpword) — adds a heavy dependency for a fix achievable with two string replacements
- Re-embedding existing tenants' chunks if their embeddings are 384-dim — needs an admin migration tool and tenant comms; defer to ops
- Wider chunk-batch optimization (e.g., batching embedding API calls for multiple chunks per request) — separate perf workstream
- Switching from `tries=3` to `tries=1` for embedding jobs — retry semantics are unchanged; OOM was the underlying cause and is now fixed
