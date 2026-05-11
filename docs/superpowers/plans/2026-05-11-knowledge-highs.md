# Knowledge-Cluster High-Severity Fixes — 2026-05-11

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close two interlocking HIGHs from the 2026-05-09 audit that together let prod RAG degrade silently:

- **H-NEW-4** — `App\Services\Knowledge\EmbeddingService` hardcodes `Provider::Ollama` and `'nomic-embed-text'`. In production where Ollama isn't running, `generate()` returns `null` silently and the chunk row gets persisted with `embedding=null`. Retrieval then falls back to keyword search with no alarm. Prod RAG is effectively disabled and nobody knows.
- **H-NEW-5** — `ProcessKnowledgeItem::handle` calls `markAsReady()` immediately after dispatching `GenerateEmbeddings`, before the embedding job runs. `RetrievalService` filters chunks by `knowledge_item.status='ready'`, so queries hit chunks with `embedding=null` during the window between dispatch and embedding completion (seconds to minutes — longer if the queue is backed up). Cosine similarity against null is undefined behavior in pgvector.

**Architecture:**
- For H-NEW-4: make embedding provider/model config-driven via new `services.embeddings.*` keys (env: `EMBEDDING_PROVIDER` / `EMBEDDING_MODEL`). Default to `ollama` / `nomic-embed-text` to preserve current dev behavior. Make `EmbeddingService::generate()` throw on real failure (instead of returning null silently). Update `RetrievalService::retrieve` to catch the exception and fall back to keyword search WITH an error log — preserves user-facing UX but produces an alarm signal. `GenerateEmbeddings::handle()` lets the exception propagate so Laravel's `tries=3` retry kicks in.
- For H-NEW-5: move `$this->item->markAsReady()` from `ProcessKnowledgeItem` to the end of `GenerateEmbeddings::handle()` (success path). Add a `failed()` method to `GenerateEmbeddings` that calls `markAsFailed()` when Laravel exhausts retries. The item stays `processing` between chunk-write and embedding-complete, so `RetrievalService` won't include its chunks.

**Tech Stack:** Laravel 13 with Prism for LLM providers. PHPUnit. The Prism `Provider` enum supports Ollama, Groq, OpenAI, Anthropic, etc. — production deployments can target any of them for embeddings via env.

**Branch base:** `main`. If PR #7's `services.embeddings` config hits a conflict, rebase to pick up the cleaner config shape.

---

## Pre-flight: branch + baseline

- [ ] **Pre-flight 1: Branch off main**

```bash
git checkout main && git pull --ff-only && git checkout -b fix/knowledge-highs-2026-05-11
```

- [ ] **Pre-flight 2: Baseline test run**

```bash
php artisan test
```

Expected: green (172 baseline on main; higher if PR #5/#6/#7 have merged).

---

## Task 1: `EmbeddingService` is config-driven and fails loudly

**Bug:** Provider and model are hardcoded. Production silently degrades to keyword-only retrieval.

**Files:**
- Modify: `config/services.php` — add `embeddings` block
- Modify: `app/Services/Knowledge/EmbeddingService.php` — read config; throw on failure
- Create: `app/Exceptions/EmbeddingGenerationException.php`
- Modify: `app/Services/Knowledge/RetrievalService.php` — catch the exception, log warning, fall back to keyword search
- Test: `tests/Unit/Services/Knowledge/EmbeddingServiceConfigTest.php` (create)
- Test: `tests/Unit/Services/Knowledge/RetrievalServiceFallbackTest.php` (create)

- [ ] **Step 1: Write the failing tests for EmbeddingService**

Create `tests/Unit/Services/Knowledge/EmbeddingServiceConfigTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Exceptions\EmbeddingGenerationException;
use App\Services\Knowledge\EmbeddingService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Tests\TestCase;

class EmbeddingServiceConfigTest extends TestCase
{
    public function test_returns_null_for_empty_text(): void
    {
        $this->assertNull(app(EmbeddingService::class)->generate(''));
        $this->assertNull(app(EmbeddingService::class)->generate('   '));
    }

    public function test_throws_when_provider_returns_no_vector(): void
    {
        Prism::fake([
            EmbeddingsResponseFake::make()->withEmbeddings([]),
        ]);

        $this->expectException(EmbeddingGenerationException::class);
        app(EmbeddingService::class)->generate('hello world');
    }
}
```

**Why no provider-call-failure test here:** `Prism::fake()` doesn't have an exception-throwing response shape (`EmbeddingsResponseFake` has no `withException()` method; the underlying `PrismFake` just dequeues responses). The `catch (\Throwable)` wrapping the Prism call is exercised indirectly by `RetrievalServiceFallbackTest::test_falls_back_to_keyword_when_embedding_throws` (which mocks `EmbeddingService` directly to throw `EmbeddingGenerationException`).

- [ ] **Step 2: Run, confirm tests FAIL**

```bash
php artisan test --filter=EmbeddingServiceConfigTest
```

Expected: failure-path tests fail (current code returns null on failure, doesn't throw).

- [ ] **Step 3: Add config keys**

Edit `config/services.php` — add after the `ollama` block:

```php
    'embeddings' => [
        'provider' => env('EMBEDDING_PROVIDER', 'ollama'),
        'model' => env('EMBEDDING_MODEL', 'nomic-embed-text'),
    ],
```

Append to `.env.example` (or create if missing):

```
EMBEDDING_PROVIDER=ollama
EMBEDDING_MODEL=nomic-embed-text
```

- [ ] **Step 4: Create the exception class**

Create `app/Exceptions/EmbeddingGenerationException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

class EmbeddingGenerationException extends \RuntimeException
{
}
```

- [ ] **Step 5: Patch EmbeddingService**

Edit `app/Services/Knowledge/EmbeddingService.php` — replace the class body:

```php
<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Exceptions\EmbeddingGenerationException;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

class EmbeddingService
{
    public const DIMENSIONS = 768;

    /**
     * Generate an embedding for the given text and return it as a pgvector
     * literal (e.g. "[0.1,0.2,...]"). Returns null for empty input. Throws
     * EmbeddingGenerationException on provider failure — callers decide
     * whether to surface (background jobs) or fall back (retrieval).
     */
    public function generate(string $text): ?string
    {
        if (empty(trim($text))) {
            return null;
        }

        $providerName = (string) config('services.embeddings.provider', 'ollama');
        $model = (string) config('services.embeddings.model', 'nomic-embed-text');
        $provider = $this->resolveProvider($providerName);

        Log::debug('[Embeddings] (IS $) Generating embedding', [
            'provider' => $providerName,
            'model' => $model,
            'text_length' => strlen($text),
        ]);

        try {
            $response = Prism::embeddings()
                ->using($provider, $model)
                ->fromInput($text)
                ->asEmbeddings();
        } catch (\Throwable $e) {
            Log::error('[Embeddings] Provider call failed', [
                'provider' => $providerName,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            throw new EmbeddingGenerationException(
                "Embedding provider {$providerName} failed: {$e->getMessage()}",
                previous: $e,
            );
        }

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

        return self::toPgVector($vector);
    }

    /**
     * Format a numeric array as a pgvector literal: "[1.0,2.0,...]".
     *
     * @param array<int, float|int> $vector
     */
    public static function toPgVector(array $vector): string
    {
        return '[' . implode(',', array_map(static fn ($v) => (float) $v, $vector)) . ']';
    }

    private function resolveProvider(string $name): Provider
    {
        return match (strtolower($name)) {
            'ollama' => Provider::Ollama,
            'openai' => Provider::OpenAI,
            'voyage', 'voyageai' => Provider::VoyageAI,
            'groq' => Provider::Groq,
            default => Provider::Ollama,
        };
    }
}
```

The match cases above are the ones confirmed present in the installed Prism Provider enum that have embedding APIs. `Cohere` is NOT in the installed Prism — don't add it. If `EMBEDDING_PROVIDER` env names a provider not in the match, the `default` falls back to Ollama (logged via the existing debug line so an operator can see the mismatch).

- [ ] **Step 6: Write the failing tests for RetrievalService fallback**

Create `tests/Unit/Services/Knowledge/RetrievalServiceFallbackTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Exceptions\EmbeddingGenerationException;
use App\Models\Tenant;
use App\Services\Knowledge\EmbeddingService;
use App\Services\Knowledge\RetrievalService;
use Mockery;
use Tests\TestCase;

class RetrievalServiceFallbackTest extends TestCase
{
    public function test_falls_back_to_keyword_when_embedding_throws(): void
    {
        $tenant = Tenant::create([
            'name' => 'Co',
            'slug' => 'co-' . uniqid(),
            'status' => 'active',
        ]);

        $embedder = Mockery::mock(EmbeddingService::class);
        $embedder->shouldReceive('generate')
            ->andThrow(new EmbeddingGenerationException('ollama down'));
        $this->app->instance(EmbeddingService::class, $embedder);

        $service = app(RetrievalService::class);
        $result = $service->retrieve($tenant, 'what services');

        // No knowledge items exist in this test — expect an empty result
        // (from keyword fallback returning nothing), NOT an exception.
        $this->assertIsArray($result);
    }
}
```

- [ ] **Step 7: Run, confirm RetrievalService test FAILS**

```bash
php artisan test --filter=RetrievalServiceFallbackTest
```

Expected: failure with `EmbeddingGenerationException` not caught by RetrievalService.

- [ ] **Step 8: Patch RetrievalService**

Open `app/Services/Knowledge/RetrievalService.php`. Locate the call to `$this->embeddingService->generate($query)`. Wrap it in try/catch:

```php
try {
    $vector = $this->embeddingService->generate($query);
} catch (\App\Exceptions\EmbeddingGenerationException $e) {
    Log::warning('[Retrieval] Embedding failed, falling back to keyword search', [
        'tenant_id' => $tenant->id,
        'error' => $e->getMessage(),
    ]);
    $vector = null;
}
```

The rest of the method already handles `$vector === null` by falling through to keyword search. No other changes needed there.

If `RetrievalService` doesn't yet import `Log` from `Illuminate\Support\Facades\Log`, add the use statement.

- [ ] **Step 9: Run all new tests**

```bash
php artisan test --filter='EmbeddingServiceConfigTest|RetrievalServiceFallbackTest'
```

Expected: all green.

- [ ] **Step 10a: Audit every caller of `EmbeddingService::generate()`**

```bash
grep -rn "embeddingService->generate\|EmbeddingService::class\|->generate(" app/ database/ tests/ | grep -i embed
```

Every caller that previously assumed `null` on failure must now either wrap in `try/catch (EmbeddingGenerationException)` OR be a downstream of one of the two patched sites (RetrievalService — handled; GenerateEmbeddings — handled by Task 2's retry/failed logic). List the call sites in your status report.

- [ ] **Step 10b: Update `RetrievalServiceTest::makeServiceWithFailingEmbeddings`**

`tests/Unit/Services/Knowledge/RetrievalServiceTest.php` line ~33 currently mocks `generate()` with `->andReturn(null)`. After Task 1, the production code expects a *throw* on failure, not a null. Update the helper to throw so the existing tests exercise the new code path:

```php
private function makeServiceWithFailingEmbeddings(): RetrievalService
{
    $embedder = Mockery::mock(EmbeddingService::class);
    $embedder->shouldReceive('generate')
        ->andThrow(new \App\Exceptions\EmbeddingGenerationException('test stub'));
    return new RetrievalService($embedder);
}
```

Add `use App\Exceptions\EmbeddingGenerationException;` to the test file's imports if not already present.

- [ ] **Step 10c: Full suite**

```bash
php artisan test
```

Expected: green.

- [ ] **Step 11: Commit**

```bash
git add config/services.php .env.example app/Exceptions/EmbeddingGenerationException.php app/Services/Knowledge/EmbeddingService.php app/Services/Knowledge/RetrievalService.php tests/Unit/Services/Knowledge/EmbeddingServiceConfigTest.php tests/Unit/Services/Knowledge/RetrievalServiceFallbackTest.php tests/Unit/Services/Knowledge/RetrievalServiceTest.php
git commit -m "$(cat <<'EOF'
fix(knowledge): make EmbeddingService config-driven and fail-loud

Embedding provider/model were hardcoded to Ollama. In production
where Ollama isn't running, generate() silently returned null and
chunks were persisted with embedding=null, leaving RAG degraded to
keyword-only with no alarm. Now reads services.embeddings.provider
and .model from config (env: EMBEDDING_PROVIDER, EMBEDDING_MODEL),
throws EmbeddingGenerationException on real failure, and the
RetrievalService catches that exception to preserve user-facing
keyword-fallback UX while emitting a warning log for ops.

GenerateEmbeddings (job path) lets the exception propagate so
Laravel's tries=3 retry kicks in — Task 2 will wire markAsFailed
to the final-failure callback.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `markAsReady` moves to `GenerateEmbeddings`

**Bug:** Item flips to `ready` while embeddings are still pending. `RetrievalService` includes its `null`-embedding chunks in pgvector queries → undefined behavior.

**Files:**
- Modify: `app/Jobs/ProcessKnowledgeItem.php` — remove `markAsReady()` from the success path
- Modify: `app/Jobs/GenerateEmbeddings.php` — call `markAsReady()` at end of success path; add `failed()` callback
- Test: `tests/Unit/Jobs/KnowledgeStatusFlowTest.php` (create)

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Jobs/KnowledgeStatusFlowTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Exceptions\EmbeddingGenerationException;
use App\Jobs\GenerateEmbeddings;
use App\Jobs\ProcessKnowledgeItem;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Services\Knowledge\DocumentProcessor;
use App\Services\Knowledge\EmbeddingService;
use App\Services\Knowledge\TextChunker;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class KnowledgeStatusFlowTest extends TestCase
{
    private function makeItem(): KnowledgeItem
    {
        $tenant = Tenant::create([
            'name' => 'Flow Co',
            'slug' => 'flow-co-' . uniqid(),
            'status' => 'active',
        ]);

        return KnowledgeItem::create([
            'tenant_id' => $tenant->id,
            'type' => 'text',
            'title' => 'Test',
            'content' => 'Long enough content to clear the 50 char minimum chunk filter for chunking.',
            'status' => 'pending',
        ]);
    }

    public function test_process_job_leaves_item_in_processing_until_embeddings_complete(): void
    {
        Queue::fake([GenerateEmbeddings::class]);
        $item = $this->makeItem();

        (new ProcessKnowledgeItem($item))->handle(
            app(DocumentProcessor::class),
            app(TextChunker::class),
        );

        $item->refresh();
        $this->assertSame(
            'processing',
            $item->status,
            'Item must NOT be ready until embeddings complete'
        );
        Queue::assertPushed(GenerateEmbeddings::class);
    }

    public function test_embeddings_job_marks_ready_on_success(): void
    {
        $item = $this->makeItem();
        // Synthetic chunk so GenerateEmbeddings has something to process.
        $item->chunks()->create([
            'content' => 'chunk-a',
            'chunk_index' => 0,
            'embedding' => null,
        ]);
        $item->markAsProcessing();

        $embedder = Mockery::mock(EmbeddingService::class);
        $embedder->shouldReceive('generate')->andReturn('[0.1,0.2,0.3]');

        (new GenerateEmbeddings($item))->handle($embedder);

        $item->refresh();
        $this->assertSame('ready', $item->status);
    }

    public function test_embeddings_job_failed_callback_marks_failed(): void
    {
        $item = $this->makeItem();
        $item->markAsProcessing();

        $job = new GenerateEmbeddings($item);
        $job->failed(new EmbeddingGenerationException('all retries exhausted'));

        $item->refresh();
        $this->assertSame('failed', $item->status);
    }
}
```

- [ ] **Step 2: Run, confirm tests FAIL**

```bash
php artisan test --filter=KnowledgeStatusFlowTest
```

Expected: 
- `test_process_job_leaves_item_in_processing_until_embeddings_complete` fails (current code marks ready inside ProcessKnowledgeItem).
- `test_embeddings_job_marks_ready_on_success` fails (current code doesn't markAsReady in GenerateEmbeddings).
- `test_embeddings_job_failed_callback_marks_failed` fails (`failed()` method doesn't exist).

- [ ] **Step 3: Patch `ProcessKnowledgeItem`**

Edit `app/Jobs/ProcessKnowledgeItem.php`. Remove the `$this->item->markAsReady()` call (around line 74 — verify the actual line in the current file). Also update the trailing debug log to reflect that processing dispatched the embedding job but the item is not yet ready.

```php
            // Dispatch embedding job for each chunk
            GenerateEmbeddings::dispatch($this->item);

            Log::debug('[Knowledge] (NO $) Chunks written; embedding job dispatched', [
                'item_id' => $this->item->id,
            ]);
```

(No `markAsReady` here. The item stays in `processing` until `GenerateEmbeddings` completes.)

- [ ] **Step 4: Patch `GenerateEmbeddings`**

Edit `app/Jobs/GenerateEmbeddings.php`. Add `markAsReady()` at end of the success path inside `handle()`, and add a `failed()` callback:

```php
    public function handle(EmbeddingService $embeddingService): void
    {
        Log::debug('[Embeddings] (IS $) Generating embeddings', [
            'item_id' => $this->item->id,
            'chunks_count' => $this->item->chunks()->count(),
        ]);

        try {
            $chunks = $this->item->chunks()->whereNull('embedding')->get();

            foreach ($chunks as $chunk) {
                $embedding = $embeddingService->generate($chunk->content);

                $chunk->update([
                    'embedding' => $embedding,
                ]);
            }

            $this->item->markAsReady();

            Log::debug('[Embeddings] (IS $) Embeddings generated; item ready', [
                'item_id' => $this->item->id,
                'processed' => $chunks->count(),
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
        $this->item->markAsFailed();
    }
```

Note the widened catch from `\Exception` to `\Throwable` (matches the EmbeddingService throw and is consistent with the chat-controller pattern from PR #6).

- [ ] **Step 5: Run new tests**

```bash
php artisan test --filter=KnowledgeStatusFlowTest
```

Expected: all 3 green.

- [ ] **Step 6: Full suite**

```bash
php artisan test
```

Expected: green. The existing `ProcessKnowledgeItemIdempotencyTest` (if PR #6 has merged) tests that chunks are de-duped on retry — it doesn't assert on `markAsReady` so should remain green.

If `KnowledgeBaseTest` (feature test) had been asserting that the item is `ready` after the controller dispatch returns, update that assertion to `processing` (the new contract).

- [ ] **Step 7: Commit**

```bash
git add app/Jobs/ProcessKnowledgeItem.php app/Jobs/GenerateEmbeddings.php tests/Unit/Jobs/KnowledgeStatusFlowTest.php
git commit -m "$(cat <<'EOF'
fix(knowledge): item only marks ready after embeddings complete

ProcessKnowledgeItem flipped status to 'ready' immediately after
dispatching GenerateEmbeddings, opening a window (seconds to minutes,
longer if the queue is backed up) where RetrievalService treated
the item as ready but its chunks had embedding=null. pgvector's
cosine operator against null is undefined and the silent-null
EmbeddingService path made the window indefinite when the provider
was unavailable.

Now: ProcessKnowledgeItem leaves the item in 'processing' after
dispatching the embedding job. GenerateEmbeddings::handle marks
ready on successful completion; the new failed() callback marks
failed when Laravel exhausts retries.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Browser smoke + simplify + PR

- [ ] **Step 1: Smoke the embedding config end-to-end**

```bash
php artisan tinker --execute="echo 'provider: ' . config('services.embeddings.provider') . PHP_EOL; echo 'model: ' . config('services.embeddings.model') . PHP_EOL;"
```

Expected: `ollama` / `nomic-embed-text` by default; respects `.env` if set.

- [ ] **Step 2: Smoke the status flow**

Upload a knowledge item via the dashboard (or via `php artisan tinker`). With `php artisan queue:work` running:

- Before queue worker picks it up: `status` should be `processing`, chunks should exist with `embedding=null`.
- After embedding job completes: `status` = `ready`, chunks have non-null `embedding`.
- If you simulate failure (kill Ollama / set EMBEDDING_PROVIDER to an invalid value): after 3 retries the item should end in `status=failed`.

```bash
# Pick the most recent knowledge item
php artisan tinker --execute="\$i = \App\Models\KnowledgeItem::latest()->first(); echo \$i->id . ' ' . \$i->status . PHP_EOL;"
```

- [ ] **Step 3: Run `/simplify`** (multi-agent review). Apply high-confidence findings.

- [ ] **Step 4: Second-pass `/simplify`**.

- [ ] **Step 5: Open PR**

PR title: `fix: close 2 knowledge-cluster high-severity bugs (silent RAG degradation, premature ready status)`

PR body should call out:
- **Behavior change**: production embedding provider is now configurable via `EMBEDDING_PROVIDER` / `EMBEDDING_MODEL` env. Deployments without those vars set keep the old Ollama default. If prod doesn't run Ollama, set these before deploy or the job will retry and ultimately mark items `failed` — instead of silently succeeding with null embeddings as before.
- **Behavior change**: knowledge items stay in `status='processing'` until the embedding job completes (seconds to minutes after upload). The dashboard may need to poll or refresh to show `ready`. UI changes are out of scope for this PR — flag it in the description.
- **Behavior change**: a knowledge item whose embedding job fails after 3 retries now ends in `status='failed'` instead of stuck in `'processing'`.
- **Deploy prerequisite**: `php artisan queue:work` MUST be running in production. Without it, knowledge items will stay in `processing` forever (no embedding job execution, no `failed()` callback). The pre-PR behavior was synchronous `markAsReady` so items would become queryable even with the worker down (albeit with no embeddings); after this PR the worker is on the critical path.

---

## Out of scope

- H-NEW-6 (prompt injection via `bot_custom_instructions` + knowledge content) — needs design work for delimiter sanitization and a knowledge-content token budget. Separate plan.
- H-NEW-7 (no prompt-budget guard before LLM call) — related to H-NEW-6 but distinct: trim conversation history when estimated tokens would exceed the provider's context window.
- H-NEW-8 (failed-but-billed LLM attempts not tracked) — separate plan, touches `ChatService::retry` and `UsageRecord`.
- H-NEW-12..H-NEW-15 (UI cluster) — separate plan, mostly Vue.
- Backfilling existing knowledge items that were marked `ready` while their embeddings were still null — out of scope for the code fix. Operations note in the PR body if needed.
