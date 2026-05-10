# Orphan-Message + Chunk-Dup Fixes — 2026-05-09 Audit (Round 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the two remaining CRITICALs from the 2026-05-09 audit — orphaned user messages on chat-pipeline failure (C-NEW-4) and chunk duplication on `ProcessKnowledgeItem` job retry (C-NEW-5). Both bugs corrupt data that compounds with every retry.

**Architecture:**
- For `ChatController::sendMessage` and `ChatController::streamMessage`: persist the user message **after** `retrievalService->retrieve` succeeds (retrieval is read-only — failing it is safe pre-persist) but **before** `captureLeadFromMessage` (which writes a `Lead`, updates `conversation.lead_id`, and dispatches a notification — those should not fire if the request is going to fail, and `LeadScoringService` counts user messages so the message must exist when scoring runs). Wrap the LLM-and-assistant-write phase in try/catch that deletes the user message on failure (avoiding a held-open DB transaction during the LLM call). `Cache::forget("tenant:{$id}:usage")` lives in a `finally` block so partial token-usage writes still invalidate the cache. Outer catches use `\Throwable` so PHP `Error`s also produce the structured error response.
- For `ProcessKnowledgeItem::handle`: wrap `delete` + create-loop in a `DB::transaction` so the chunk set is replaced atomically. A partial-set after a mid-loop DB failure would survive `markAsFailed` and skew RAG retrieval until manual re-trigger.

**Tech Stack:** Laravel 13, PHPUnit. The `ChatService`, `RetrievalService`, and `LeadService` are constructor-injected on `ChatController`, so tests can swap them via `$this->app->instance(...)` or via a service-container partial mock.

---

## Pre-flight: branch + baseline

- [ ] **Pre-flight 1: Create the fix branch from main**

```bash
git checkout main && git pull --ff-only && git checkout -b fix/orphan-and-chunk-dup-2026-05-09
```

- [ ] **Pre-flight 2: Baseline test run**

```bash
php artisan test
```

Expected: green (193 baseline after PR #5 — adjust if main is at a different state).

---

## Task 1: `ChatController::sendMessage` no longer orphans on pre-LLM or LLM failure

**Bug:** `sendMessage` persists the user message at line 139, then calls `captureLeadFromMessage` (146), `retrievalService->retrieve` (149), `chatService->generateResponse` (152), and finally writes the assistant message (159). Any throw between line 139 and line 159 leaves the user message persisted with no assistant reply. The catch block at line 174 logs and returns 500 but the orphan stays. On retry, another orphan is created and `buildMessageHistory` (in `ChatService`) feeds them all back to the LLM.

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Widget/ChatController.php` — `sendMessage` (108-178)
- Test: `tests/Feature/Widget/ChatSendMessageOrphanTest.php` (create)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Widget/ChatSendMessageOrphanTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Knowledge\RetrievalService;
use App\Services\LLM\ChatService;
use Mockery;
use Tests\TestCase;

class ChatSendMessageOrphanTest extends TestCase
{
    private Tenant $tenant;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Chat Co',
            'slug' => 'chat-co',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => []],
        ]);
        // The default tenant gets an api_key on Tenant::saved (existing behavior);
        // refresh to get it.
        $this->tenant->refresh();

        User::create([
            'name' => 'Owner',
            'email' => 'owner@chat.co',
            'password' => bcrypt('password'),
            'tenant_id' => $this->tenant->id,
        ]);

        $this->conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'session_id' => 'session-orphan-test',
            'status' => 'active',
        ]);
    }

    private function postMessage(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/widget/message', [
            'api_key' => $this->tenant->api_key,
            'conversation_id' => $this->conversation->id,
            'message' => 'hello',
        ]);
    }

    public function test_no_user_message_persisted_when_retrieval_fails(): void
    {
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('retrieve')->andThrow(new \RuntimeException('retrieval down'));
        $this->app->instance(RetrievalService::class, $retrieval);

        $response = $this->postMessage();

        $response->assertStatus(500);
        $this->assertSame(0, Message::where('conversation_id', $this->conversation->id)->count(),
            'Retrieval failure must not leave an orphan user message');
    }

    public function test_user_message_removed_when_chat_service_fails(): void
    {
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('retrieve')->andReturn([]);
        $this->app->instance(RetrievalService::class, $retrieval);

        $chat = Mockery::mock(ChatService::class);
        $chat->shouldReceive('generateResponse')->andThrow(new \RuntimeException('groq 503'));
        $this->app->instance(ChatService::class, $chat);

        $response = $this->postMessage();

        $response->assertStatus(500);
        $this->assertSame(0, Message::where('conversation_id', $this->conversation->id)->count(),
            'LLM failure must roll back the user message');
    }

    public function test_happy_path_persists_user_then_assistant(): void
    {
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('retrieve')->andReturn([]);
        $this->app->instance(RetrievalService::class, $retrieval);

        $chat = Mockery::mock(ChatService::class);
        $chat->shouldReceive('generateResponse')->andReturn('hi back');
        $this->app->instance(ChatService::class, $chat);

        $response = $this->postMessage();

        $response->assertOk();
        $messages = Message::where('conversation_id', $this->conversation->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $messages);
        $this->assertSame('user', $messages[0]->role);
        $this->assertSame('hello', $messages[0]->content);
        $this->assertSame('assistant', $messages[1]->role);
        $this->assertSame('hi back', $messages[1]->content);
    }

    public function test_retry_after_failure_does_not_accumulate_orphans(): void
    {
        // First attempt: chat service fails → user msg should not stick.
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('retrieve')->andReturn([]);
        $this->app->instance(RetrievalService::class, $retrieval);

        $failing = Mockery::mock(ChatService::class);
        $failing->shouldReceive('generateResponse')->andThrow(new \RuntimeException('groq 503'));
        $this->app->instance(ChatService::class, $failing);
        $this->postMessage()->assertStatus(500);

        // Second attempt: chat service succeeds → exactly one user + one assistant.
        $succeeding = Mockery::mock(ChatService::class);
        $succeeding->shouldReceive('generateResponse')->andReturn('finally');
        $this->app->instance(ChatService::class, $succeeding);
        $this->postMessage()->assertOk();

        $count = Message::where('conversation_id', $this->conversation->id)->count();
        $this->assertSame(2, $count, 'Retry must not accumulate orphan user messages');
    }
}
```

- [ ] **Step 2: Run, confirm the orphan tests FAIL**

```bash
php artisan test --filter=ChatSendMessageOrphanTest
```

Expected: `test_no_user_message_persisted_when_retrieval_fails`, `test_user_message_removed_when_chat_service_fails`, and `test_retry_after_failure_does_not_accumulate_orphans` fail because the user message is currently persisted before retrieval/generation.

- [ ] **Step 3: Patch `sendMessage`**

Edit `app/Http/Controllers/Api/V1/Widget/ChatController.php`. Replace the body of `sendMessage` (the method starting at `public function sendMessage(Request $request): JsonResponse`) so the structure becomes:

1. **Phase 1 — read-only pre-persist:** call `retrievalService->retrieve` *before* the user message is created. Retrieval has no writes, so a failure here returns 500 with no orphan.
2. **Phase 2 — persist user message and side effects:** create `Message(role=user)`, then call `captureLeadFromMessage`. Lead capture's writes (Lead row, conversation.lead_id update, notification) only happen once the user message is durable, and `LeadScoringService::score` counts user messages so the new message must exist before scoring runs.
3. **Phase 3 — LLM + assistant message:** wrap in a nested try/catch that deletes `$userMessage` and re-throws on any failure.
4. **Phase 4 — usage cache invalidation:** in a `finally` block so partial token-usage writes still bust the cache.
5. **Outer catch is `\Throwable`** (was `\Exception`) so PHP `Error` instances thrown from inside `generateResponse` also produce the structured error response.

```php
try {
    $conversation = Conversation::where('id', $conversationId)
        ->where('tenant_id', $tenant->id)
        ->first();

    if (! $conversation) {
        return $this->errorResponse('Conversation not found', 'CONVERSATION_NOT_FOUND', 404);
    }

    // Phase 1: retrieval is read-only, so a failure here cannot leave an orphan.
    $context = $this->retrievalService->retrieve($tenant, $message);

    // Phase 2: persist the user message before any write-side-effects so the
    // current message is counted by LeadScoringService and lead capture's
    // writes only happen if we have a durable message to anchor them to.
    $userMessage = Message::create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => $message,
    ]);

    try {
        $this->captureLeadFromMessage($conversation, $message);

        // Phase 3: LLM call + assistant message; on any throw, delete the
        // user message before re-raising so retries don't accumulate orphans.
        $response = $this->chatService->generateResponse(
            $conversation,
            $message,
            ['knowledge' => $context]
        );

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $response,
        ]);
    } catch (\Throwable $e) {
        $userMessage->delete();
        throw $e;
    } finally {
        Cache::forget("tenant:{$tenant->id}:usage");
    }

    Log::debug('[Widget] (NO $) Message exchanged', [
        'conversation_id' => $conversation->id,
    ]);

    return response()->json([
        'response' => $response,
    ]);
} catch (\Throwable $e) {
    Log::error('[Widget] Failed to send message', ['error' => $e->getMessage()]);
    return $this->errorResponse('Failed to process message', 'MESSAGE_ERROR', 500);
}
```

Note: the outer catch was `\Exception` in the original code; widening to `\Throwable` is intentional — it ensures `Error` instances (e.g., a `TypeError` from a contract violation inside the chat service) also produce the structured `MESSAGE_ERROR` response rather than escaping to Laravel's generic exception handler.

- [ ] **Step 4: Run, confirm tests PASS**

```bash
php artisan test --filter=ChatSendMessageOrphanTest
```

Expected: all 4 tests green.

- [ ] **Step 5: Run the full suite**

```bash
php artisan test
```

Expected: green. If `WidgetApiTest` had asserted that a user message is persisted even on LLM failure, update it — the new behavior is correct.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/Widget/ChatController.php tests/Feature/Widget/ChatSendMessageOrphanTest.php
git commit -m "$(cat <<'EOF'
fix(widget): roll back user message on retrieval/LLM failure in sendMessage

Persisting the user message before retrieval and LLM generation meant
any failure left an orphan that subsequent retries duplicated and that
buildMessageHistory then fed back to the LLM, corrupting context.
Pre-LLM failures (lead capture, retrieve) now happen before the user
message is created; LLM failures delete the user message before
re-throwing.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `ChatController::streamMessage` no longer orphans on retrieval or stream failure

**Bug:** `streamMessage` has the same shape — user message is persisted at line 218 before `captureLeadFromMessage` (225), `retrievalService->retrieve` (228), and the stream closure (230). The closure itself can throw (LLM 503, network reset) and there's no cleanup; the user message remains persisted indefinitely.

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Widget/ChatController.php` — `streamMessage` (183-259)
- Test: `tests/Feature/Widget/ChatStreamMessageOrphanTest.php` (create)

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Widget/ChatStreamMessageOrphanTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Knowledge\RetrievalService;
use App\Services\LLM\ChatService;
use Mockery;
use Tests\TestCase;

class ChatStreamMessageOrphanTest extends TestCase
{
    private Tenant $tenant;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Stream Co',
            'slug' => 'stream-co',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => []],
        ]);
        $this->tenant->refresh();

        User::create([
            'name' => 'Owner',
            'email' => 'owner@stream.co',
            'password' => bcrypt('password'),
            'tenant_id' => $this->tenant->id,
        ]);

        $this->conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'session_id' => 'session-stream-orphan',
            'status' => 'active',
        ]);
    }

    private function postStream(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/widget/message/stream', [
            'api_key' => $this->tenant->api_key,
            'conversation_id' => $this->conversation->id,
            'message' => 'hello',
        ]);
    }

    public function test_no_user_message_persisted_when_retrieval_fails(): void
    {
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('retrieve')->andThrow(new \RuntimeException('retrieval down'));
        $this->app->instance(RetrievalService::class, $retrieval);

        $response = $this->postStream();

        $response->assertStatus(500);
        $this->assertSame(0, Message::where('conversation_id', $this->conversation->id)->count(),
            'Retrieval failure must not leave an orphan user message');
    }

    public function test_user_message_removed_when_stream_throws_mid_stream(): void
    {
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('retrieve')->andReturn([]);
        $this->app->instance(RetrievalService::class, $retrieval);

        $chat = Mockery::mock(ChatService::class);
        // streamResponse is a generator-returning method. Throw mid-iteration
        // by returning a generator that yields once and then throws — the
        // controller's foreach will hit the throw on the next() call.
        $chat->shouldReceive('streamResponse')->andReturnUsing(function () {
            return (function () {
                yield 'partial';
                throw new \RuntimeException('groq 503 mid-stream');
            })();
        });
        $this->app->instance(ChatService::class, $chat);

        $response = $this->postStream();

        // The HTTP response opens 200 (SSE) before the closure runs; we must
        // call streamedContent() (NOT getContent()) to actually execute the
        // streaming closure in the test environment. getContent() returns
        // false on a StreamedResponse and never invokes the callback.
        $response->streamedContent();

        $this->assertSame(0, Message::where('conversation_id', $this->conversation->id)->count(),
            'Stream failure must roll back the user message');
    }

    public function test_happy_path_persists_user_then_assistant(): void
    {
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('retrieve')->andReturn([]);
        $this->app->instance(RetrievalService::class, $retrieval);

        $chat = Mockery::mock(ChatService::class);
        $chat->shouldReceive('streamResponse')->andReturnUsing(function () {
            return (function () {
                yield 'hi ';
                yield 'back';
            })();
        });
        $this->app->instance(ChatService::class, $chat);

        $response = $this->postStream();
        // streamedContent() runs the SSE closure synchronously and captures
        // its output. Without this, the assistant message is never created
        // in the test process.
        $response->streamedContent();

        $messages = Message::where('conversation_id', $this->conversation->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $messages);
        $this->assertSame('user', $messages[0]->role);
        $this->assertSame('assistant', $messages[1]->role);
        $this->assertSame('hi back', $messages[1]->content);
    }
}
```

- [ ] **Step 2: Run, confirm orphan tests FAIL**

```bash
php artisan test --filter=ChatStreamMessageOrphanTest
```

Expected: `test_no_user_message_persisted_when_retrieval_fails` and `test_user_message_removed_when_stream_throws_mid_stream` fail because the user message is currently persisted before retrieval and is never deleted on stream failure.

- [ ] **Step 3: Patch `streamMessage`**

Edit `app/Http/Controllers/Api/V1/Widget/ChatController.php`. Apply this against the **post-Task-1 file state** (Task 1 shifted line numbers). Use the function-name anchor: locate `public function streamMessage(Request $request): JsonResponse|StreamedResponse` and replace everything below the `if (! $tenant) { return $this->errorResponse('Invalid API key', ...) }` block with:

```php
try {
    $conversation = Conversation::where('id', $conversationId)
        ->where('tenant_id', $tenant->id)
        ->first();
} catch (\Throwable $e) {
    Log::error('[Widget] Failed to prepare stream', ['error' => $e->getMessage()]);
    return $this->errorResponse('Failed to process message', 'MESSAGE_ERROR', 500);
}

if (! $conversation) {
    return $this->errorResponse('Conversation not found', 'CONVERSATION_NOT_FOUND', 404);
}

// Phase 1: retrieval is read-only — a failure here cannot leave an orphan
// because the user message hasn't been persisted yet.
try {
    $context = $this->retrievalService->retrieve($tenant, $message);
} catch (\Throwable $e) {
    Log::error('[Widget] Failed to prepare stream', ['error' => $e->getMessage()]);
    return $this->errorResponse('Failed to process message', 'MESSAGE_ERROR', 500);
}

// Phase 2: persist the user message. Subsequent writes (lead capture,
// assistant message) are anchored to it. If either Phase 3's lead capture
// or the stream itself throws, the closure deletes this row.
$userMessage = Message::create([
    'conversation_id' => $conversation->id,
    'role' => 'user',
    'content' => $message,
]);

return response()->stream(function () use ($tenant, $conversation, $message, $context, $userMessage) {
    $fullResponse = '';

    try {
        // Phase 3: side-effecting lead capture happens here so the user
        // message is durable and counted by LeadScoringService. A throw
        // bubbles to the catch below and the user message is rolled back.
        $this->captureLeadFromMessage($conversation, $message);

        foreach ($this->chatService->streamResponse($conversation, $message, ['knowledge' => $context]) as $chunk) {
            $fullResponse .= (string) $chunk;
            echo 'data: '.json_encode(['chunk' => $chunk])."\n\n";
            ob_flush();
            flush();
        }

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $fullResponse,
        ]);

        echo 'data: '.json_encode(['done' => true])."\n\n";
        ob_flush();
        flush();
    } catch (\Throwable $e) {
        $userMessage->delete();
        Log::error('[Widget] Stream failed', ['error' => $e->getMessage()]);
        echo 'data: '.json_encode(['error' => 'Stream failed'])."\n\n";
        ob_flush();
        flush();
    } finally {
        // Phase 4: invalidate the usage cache regardless of outcome.
        // streamResponse may have written partial UsageRecord rows before
        // throwing; skipping forget would leave the cached total stale.
        Cache::forget("tenant:{$tenant->id}:usage");
    }
}, 200, [
    'Content-Type' => 'text/event-stream',
    'Cache-Control' => 'no-cache',
    'Connection' => 'keep-alive',
    'X-Accel-Buffering' => 'no',
]);
```

Notes:
1. `retrieve` is now in its own pre-persist try/catch; failure returns 500 with no orphan.
2. `captureLeadFromMessage` runs *inside* the stream closure (after user message is durable) so its writes happen only when we have a real anchor message and `LeadScoringService` counts the current message.
3. The stream closure's catch deletes `$userMessage` on any in-stream throw, emits an SSE error event, and continues to the `finally` for cache invalidation.
4. The `Conversation::where(...)->first()` catch is widened to `\Throwable` to match the rest.

- [ ] **Step 4: Run, confirm tests PASS**

```bash
php artisan test --filter=ChatStreamMessageOrphanTest
```

Expected: all 3 tests green.

- [ ] **Step 5: Run the full suite**

```bash
php artisan test
```

Expected: green.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/Widget/ChatController.php tests/Feature/Widget/ChatStreamMessageOrphanTest.php
git commit -m "$(cat <<'EOF'
fix(widget): roll back user message on retrieval/stream failure in streamMessage

Same orphan shape as sendMessage but harder to reach because the
assistant message is created inside the SSE closure. Pre-stream
phase (lead capture, retrieve) now runs before user-message persist
so its failures return 500 cleanly. Stream-phase failures inside
the closure delete the user message and emit an SSE error event
instead of leaving an orphan.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `ProcessKnowledgeItem` is idempotent on retry

**Bug:** `ProcessKnowledgeItem::handle` (in `app/Jobs/ProcessKnowledgeItem.php`) creates chunks via `KnowledgeChunk::create(...)` in a foreach loop at lines 62-69, with no prior delete. The job has `$tries = 3`. If the job crashes between chunk-write and `markAsReady` (e.g., `GenerateEmbeddings::dispatch` throws, or the worker is killed) Laravel retries the entire `handle` from line 38, which calls `markAsProcessing` and runs `extractContent` again, producing a fresh full set of chunks **alongside** the existing ones. `GenerateEmbeddings::handle` uses `whereNull('embedding')` so it embeds both sets, leaving N×retry duplicates.

**Files:**
- Modify: `app/Jobs/ProcessKnowledgeItem.php:31-88`
- Test: `tests/Unit/Jobs/ProcessKnowledgeItemIdempotencyTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Jobs/ProcessKnowledgeItemIdempotencyTest.php`:

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
use App\Services\Knowledge\TextChunker;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ProcessKnowledgeItemIdempotencyTest extends TestCase
{
    private function makeItem(): KnowledgeItem
    {
        $tenant = Tenant::create([
            'name' => 'Idem Co',
            'slug' => 'idem-co',
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

        $chunker = Mockery::mock(TextChunker::class);
        $chunker->shouldReceive('chunk')->andReturn(['chunk-a', 'chunk-b']);
        $processor = Mockery::mock(DocumentProcessor::class);

        // First invocation — clean state.
        (new ProcessKnowledgeItem($item))->handle($processor, $chunker);
        $this->assertSame(2, KnowledgeChunk::where('knowledge_item_id', $item->id)->count());

        // Second invocation — simulates a Laravel retry. Must produce the
        // same 2 chunks, not 4.
        (new ProcessKnowledgeItem($item))->handle($processor, $chunker);
        $this->assertSame(
            2,
            KnowledgeChunk::where('knowledge_item_id', $item->id)->count(),
            'Re-running the job must not append duplicate chunks'
        );
    }

    public function test_chunk_content_matches_after_retry(): void
    {
        // Verify that the chunks present after retry are the new ones, not
        // a mix of old + new that happened to total the same count.
        Queue::fake([GenerateEmbeddings::class]);
        $item = $this->makeItem();

        $first = Mockery::mock(TextChunker::class);
        $first->shouldReceive('chunk')->andReturn(['old-1', 'old-2']);
        (new ProcessKnowledgeItem($item))->handle(Mockery::mock(DocumentProcessor::class), $first);

        $second = Mockery::mock(TextChunker::class);
        $second->shouldReceive('chunk')->andReturn(['new-1', 'new-2', 'new-3']);
        (new ProcessKnowledgeItem($item))->handle(Mockery::mock(DocumentProcessor::class), $second);

        $contents = KnowledgeChunk::where('knowledge_item_id', $item->id)
            ->orderBy('chunk_index')
            ->pluck('content')
            ->all();
        $this->assertSame(['new-1', 'new-2', 'new-3'], $contents);
    }
}
```

- [ ] **Step 2: Run, confirm tests FAIL**

```bash
php artisan test --filter=ProcessKnowledgeItemIdempotencyTest
```

Expected: `test_running_the_job_twice_does_not_duplicate_chunks` fails (count is 4, not 2). `test_chunk_content_matches_after_retry` fails (5 chunks: 2 old + 3 new).

- [ ] **Step 3: Patch the job to delete-then-create atomically**

Edit `app/Jobs/ProcessKnowledgeItem.php`. Add `use Illuminate\Support\Facades\DB;` to the import block. Then replace the chunk-creation block (currently the foreach over `$chunks` that calls `KnowledgeChunk::create`) with a `DB::transaction` that deletes existing chunks and writes the fresh set atomically. If the transaction throws partway, the delete is rolled back and the old chunks survive — better than a half-replaced state that survives all retries.

```php
            // Chunk the content
            $chunks = $chunker->chunk($content);

            Log::debug('[Knowledge] (NO $) Content chunked', [
                'item_id' => $this->item->id,
                'chunks_count' => count($chunks),
            ]);

            // Replace any prior chunk set atomically. Job retries (tries=3)
            // would otherwise append a duplicate set; a partial mid-loop
            // failure without the transaction would leave half the new
            // chunks alongside no old chunks. The transaction keeps the
            // item in a single consistent chunk set at all times.
            DB::transaction(function () use ($chunks) {
                $this->item->chunks()->delete();
                foreach ($chunks as $index => $chunkContent) {
                    KnowledgeChunk::create([
                        'knowledge_item_id' => $this->item->id,
                        'content' => $chunkContent,
                        'chunk_index' => $index,
                        'embedding' => null,
                    ]);
                }
            });
```

The `chunks()` relationship is defined on `KnowledgeItem` (`public function chunks(): HasMany` — the same relation that `GenerateEmbeddings::handle` uses). No model change required.

- [ ] **Step 4: Run, confirm tests PASS**

```bash
php artisan test --filter=ProcessKnowledgeItemIdempotencyTest
```

Expected: both tests green.

- [ ] **Step 5: Full suite**

```bash
php artisan test
```

Expected: green. If `KnowledgeBaseTest` had a fixture that ran `ProcessKnowledgeItem` twice and asserted on accumulated chunk count, update it to expect the de-duped count.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/ProcessKnowledgeItem.php tests/Unit/Jobs/ProcessKnowledgeItemIdempotencyTest.php
git commit -m "$(cat <<'EOF'
fix(knowledge): make ProcessKnowledgeItem idempotent on retry

Job has tries=3. A crash between chunk-write and markAsReady caused
the next attempt to re-run extractContent and append a fresh full set
of chunks alongside the existing ones; GenerateEmbeddings then embedded
both sets. Three retries left 4× chunks per item, skewing RAG retrieval
ranking and inflating storage. Now deletes existing chunks before
writing the new set so each attempt produces a single canonical set.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Browser smoke + finishing

- [ ] **Step 1: Confirm existing widget flow still works in a browser**

Login as `test@example.com` / `password`, visit `/widget/test.html`, send a message. Confirm:
- A message → response cycle works end-to-end (no 500).
- Each interaction creates exactly two `messages` rows (user + assistant). Spot-check via tinker:
  ```bash
  php artisan tinker --execute="dump(\App\Models\Message::where('conversation_id', /*last id*/)->orderBy('id')->get(['id','role','content']));"
  ```

- [ ] **Step 2: Force a knowledge-item re-process and confirm no chunk duplication**

```bash
php artisan tinker --execute="
\$item = \App\Models\KnowledgeItem::create([
    'tenant_id' => 1,
    'type' => 'text',
    'title' => 'idempotency check',
    'content' => 'one. two. three.',
    'status' => 'pending',
]);
(new \App\Jobs\ProcessKnowledgeItem(\$item))->handle(
    app(\App\Services\Knowledge\DocumentProcessor::class),
    app(\App\Services\Knowledge\TextChunker::class)
);
(new \App\Jobs\ProcessKnowledgeItem(\$item))->handle(
    app(\App\Services\Knowledge\DocumentProcessor::class),
    app(\App\Services\Knowledge\TextChunker::class)
);
dump(\$item->chunks()->count());
\$item->forceDelete();
"
```

Expected: chunk count after two runs equals chunk count after one run (small integer like 1 or 2 — not double).

- [ ] **Step 3: Run `/simplify` and apply meaningful findings, then run a second pass**

(Same workflow as the prior plan — three parallel reviewers, then a second-pass review for issues introduced by cleanups.)

- [ ] **Step 4: Push + open PR**

PR title: `fix: close 2 remaining critical bugs (orphan messages, chunk duplication)`

PR body should call out:
- Behavior change: failed chat requests no longer leave residual user messages (good).
- Behavior change: re-processing a knowledge item replaces its chunks rather than appending. Re-embedding cost is the same; storage shrinks for any item that previously suffered the bug.
- Test plan: full suite + the tinker idempotency check.

---

## Out of scope

- All HIGH and MEDIUM findings from the 2026-05-09 audit (12 H + 15 M still open) — separate plans.
- The 11 documented Mediums M1–M11 from the May 7 audit — also separate.
- Refactoring `ChatController` to extract a `MessagePipeline` service (would reduce duplication between `sendMessage` and `streamMessage` but the bug fix is local and a refactor expands scope unnecessarily).
- Hardening `captureLeadFromMessage` to never throw — out of scope; it's wrapped in our pre-LLM try/catch which handles whatever it raises.

This plan stays narrow to the two CRITICAL data-corruption bugs and one PR.
