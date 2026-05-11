# Prompt Budget + Failed-Attempt Billing — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close H-NEW-7 (no prompt-budget guard) and H-NEW-8 (failed-but-billed LLM attempts not tracked) — the two remaining HIGH findings from the 2026-05-09 audit.

**Architecture:**
- **H-NEW-7:** `buildMessageHistory` trims conversation history from the oldest end so total estimated tokens stay within a configurable budget (`MAX_HISTORY_TOKENS = 4000`). Token estimation uses the standard 4-chars-per-token heuristic via a private `estimateTokens` helper. The newest message is always kept even if it alone exceeds budget — at that point the LLM provider will reject and we let the existing failure path handle it cleanly.
- **H-NEW-8:** Track failed retry attempts inside `generateResponse`'s retry closure. After the retry chain completes (success or total failure), write a single `UsageRecord` covering the estimated prompt tokens for each failed attempt (since the provider may have billed for them). The success-path `UsageRecord` for the actual response is unchanged.

**Tech Stack:** Laravel 13, Prism for LLM, PHPUnit. `ChatService` already has `Log::warning` for retry attempts; this PR extends those events with per-attempt token estimates and ties them into `UsageTracker`.

---

## Pre-flight: branch + baseline

- [ ] **Pre-flight 1: Branch off main**

```bash
git checkout main && git pull --ff-only && git checkout -b fix/llm-budget-billing-2026-05-11
```

- [ ] **Pre-flight 2: Baseline test + commit graph confirms PR #10 merged**

```bash
php artisan test
git log --oneline -3
```

Expected: green (235/235 if main has PR #10 merged). The commit log should show `Merge pull request #10` at the top.

---

## Task 1: H-NEW-7 — Prompt-budget guard via history truncation

The current `buildMessageHistory` returns the last 20 messages unconditionally. Each message can be up to 2000 chars (`max:2000` widget API rule). That's up to 40k chars ≈ 10k tokens for history alone — enough to blow Groq's 8k-token context window when combined with the system prompt and knowledge content.

This task adds a token budget that walks newest → oldest and stops when including the next-older message would exceed `MAX_HISTORY_TOKENS = 4000`. The newest message in history is always included — if it alone exceeds budget, we ship it anyway and let the provider's own rejection trigger the existing failure path (a single oversized user message is a malformed input, not a normal-load case).

**Files:**
- Modify: `app/Services/LLM/ChatService.php` — add token estimation + budget logic to `buildMessageHistory`; add private const `MAX_HISTORY_TOKENS`.
- Modify: `tests/Unit/Services/LLM/ChatServiceTest.php` — add 4 new tests for the budget behavior.

### Step 1: Write failing tests

Append to `tests/Unit/Services/LLM/ChatServiceTest.php` (above the closing `}`):

```php
    public function test_estimate_tokens_uses_four_chars_per_token_heuristic(): void
    {
        $this->assertSame(0, $this->invokePrivate('estimateTokens', ''));
        $this->assertSame(1, $this->invokePrivate('estimateTokens', 'a'));     // 1 char → 1 token (ceil)
        $this->assertSame(1, $this->invokePrivate('estimateTokens', 'abcd'));  // 4 chars → 1 token
        $this->assertSame(2, $this->invokePrivate('estimateTokens', 'abcde')); // 5 chars → 2 tokens (ceil)
        $this->assertSame(250, $this->invokePrivate('estimateTokens', str_repeat('x', 1000))); // 1000 chars → 250 tokens
    }

    public function test_message_history_under_budget_returns_all_messages(): void
    {
        $tenant = $this->configureTenant([]);
        $conversation = \App\Models\Conversation::create([
            'tenant_id' => $tenant->id,
            'session_id' => 'budget-under',
            'status' => 'active',
        ]);

        for ($i = 0; $i < 6; $i++) {
            \App\Models\Message::create([
                'conversation_id' => $conversation->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "short message {$i}",
            ]);
        }

        $history = $this->invokePrivate('buildMessageHistory', $conversation->fresh());

        $this->assertCount(6, $history, 'all 6 short messages fit in budget');
    }

    public function test_message_history_over_budget_drops_oldest(): void
    {
        $tenant = $this->configureTenant([]);
        $conversation = \App\Models\Conversation::create([
            'tenant_id' => $tenant->id,
            'session_id' => 'budget-over',
            'status' => 'active',
        ]);

        // Each message ~1500 chars ≈ 375 tokens. With MAX_HISTORY_TOKENS=4000,
        // budget fits ~10 messages of this size. We create 15 so 5 must drop.
        for ($i = 0; $i < 15; $i++) {
            \App\Models\Message::create([
                'conversation_id' => $conversation->id,
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => str_repeat("msg{$i} ", 250), // ~1500 chars
            ]);
        }

        $history = $this->invokePrivate('buildMessageHistory', $conversation->fresh());

        $this->assertLessThan(15, count($history), 'oldest messages must be dropped when over budget');
        $this->assertGreaterThan(0, count($history), 'at least the most recent message must be kept');

        // The last (newest) message must still be present.
        $lastContent = $history[count($history) - 1]->content;
        $this->assertStringContainsString('msg14', $lastContent);
    }

    public function test_message_history_keeps_newest_even_if_alone_exceeds_budget(): void
    {
        $tenant = $this->configureTenant([]);
        $conversation = \App\Models\Conversation::create([
            'tenant_id' => $tenant->id,
            'session_id' => 'budget-mega',
            'status' => 'active',
        ]);

        // 20000 chars ≈ 5000 tokens — alone larger than MAX_HISTORY_TOKENS.
        \App\Models\Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => str_repeat('big ', 5000),
        ]);

        $history = $this->invokePrivate('buildMessageHistory', $conversation->fresh());

        $this->assertCount(1, $history, 'newest message is kept even if it alone exceeds budget');
    }
```

### Step 2: Run, confirm tests FAIL

```bash
php artisan test --filter='estimate_tokens|message_history'
```

Expected: 4 failures — `estimateTokens` doesn't exist, and `buildMessageHistory` doesn't yet take a budget.

### Step 3: Patch `ChatService`

In `app/Services/LLM/ChatService.php`:

**Add a constant** near the existing constants at the top of the class:

```php
    private const MAX_HISTORY_TOKENS = 4000;
```

**Add the `estimateTokens` helper** anywhere in the class (e.g., near `escapeForPrompt`):

```php
    /**
     * Estimate token count using the standard 4-chars-per-token heuristic.
     * Off by ~20% on real tokenizer output, but good enough for budget
     * trimming where the budget itself has a safety margin.
     */
    private function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
```

**Replace `buildMessageHistory`** (currently lines ~398-414):

```php
    /**
     * Build the conversation message history trimmed to fit within
     * MAX_HISTORY_TOKENS. Walks newest → oldest and stops when including
     * the next-older message would exceed the budget. The newest message
     * is always kept; if it alone exceeds budget, we let the provider's
     * own context-window error trigger the standard failure path.
     *
     * @return array<int, UserMessage|AssistantMessage>
     */
    private function buildMessageHistory(Conversation $conversation): array
    {
        $rows = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        $kept = [];
        $tokensUsed = 0;

        foreach ($rows as $message) {
            $messageTokens = $this->estimateTokens($message->content);
            $isFirst = $kept === [];

            if (! $isFirst && $tokensUsed + $messageTokens > self::MAX_HISTORY_TOKENS) {
                break;
            }

            $kept[] = $message;
            $tokensUsed += $messageTokens;
        }

        return array_map(
            fn (Message $m) => $m->role === 'user'
                ? new UserMessage($m->content)
                : new AssistantMessage($m->content),
            array_reverse($kept),
        );
    }
```

Note: Prism's `UserMessage` and `AssistantMessage` value objects expose the message text as a public readonly `$content` property, NOT a `content()` method. Read with `$m->content`, not `$m->content()`.
```

### Step 4: Run, confirm tests PASS

```bash
php artisan test --filter='estimate_tokens|message_history'
```

Expected: 4 green.

### Step 5: Full suite

```bash
php artisan test
```

Expected: 239 passed (235 baseline + 4 new). The change is backward-compatible for callers — `generateResponse` and `streamResponse` already invoke `buildMessageHistory($conversation)` with the same signature.

### Step 6: Commit

```bash
git add app/Services/LLM/ChatService.php tests/Unit/Services/LLM/ChatServiceTest.php
git commit -m "$(cat <<'EOF'
fix(llm): trim conversation history to fit prompt budget

Closes H-NEW-7. buildMessageHistory walks newest -> oldest and
drops the oldest messages when total estimated tokens would exceed
MAX_HISTORY_TOKENS (4000). The newest message is always kept even
if it alone exceeds the budget — at that point the provider's
context-window error triggers the existing failure path, which is
the correct behavior for malformed input.

Combined with PR #10's bounded knowledge section (<= 7500 chars),
the total prompt size is now predictable and well under Groq's
8k-token context window on llama-3.1-8b-instant.

Token estimation uses the standard 4-chars-per-token heuristic via
a private estimateTokens helper. Off ~20% from real tokenizer
output but the budget itself has safety margin.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: H-NEW-8 — Track failed retry attempts in UsageRecord

The `retry()` closure in `generateResponse` re-submits the same prompt up to 3 times. The provider (Groq) may bill for retries — especially 5xx attempts that crossed the network after starting processing. Currently only the final successful response's `usage` is recorded, so tenants who hit transient errors quietly under-report usage and can exceed Groq quotas without the platform noticing.

The fix: extract a private `dispatchToProvider($systemPrompt, $messages): TextResponse` helper that wraps the actual Prism call. The retry closure calls `$this->dispatchToProvider(...)`. Track failed attempts in a counter, then after the retry chain completes (success OR total failure), write an additional `UsageRecord` covering the estimated prompt-token cost of each failed attempt. The success record (when applicable) continues to use the actual response usage as today.

We use the same `estimateTokens` helper from Task 1 to compute prompt tokens for the system prompt + history + new user message.

**Why the extraction:** Prism's `Prism::fake([...])` cannot throw exceptions — `EmbeddingsResponseFake`/`TextResponseFake` have no `withException` method, and `PrismFake::text()` just dequeues responses without an error path. Testing retry behavior at the integration level requires either a custom `PrismFake` subclass or restructuring `generateResponse` so the provider call is mockable at a higher level. The extraction is one line and pays off: tests partial-mock `ChatService::dispatchToProvider` via Mockery, choosing per-call whether to throw or return a stub response. Cleaner than fighting Prism's fake API.

**Files:**
- Modify: `app/Services/LLM/ChatService.php` — instrument the retry closure; emit a failed-attempt usage record after the retry chain.
- Modify: `tests/Unit/Services/LLM/ChatServiceTest.php` — add 3 new tests for failed-attempt tracking.

### Step 1: Write failing tests

The tests partial-mock `ChatService` so the new `dispatchToProvider` method (extracted in Step 3) can throw or return per call. This sidesteps Prism's lack of an exception-injecting fake.

Append to `tests/Unit/Services/LLM/ChatServiceTest.php`:

```php
    private function makeMockableService(): ChatService&\Mockery\MockInterface
    {
        return \Mockery::mock(ChatService::class, [app(UsageTracker::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
    }

    private function makeTextResponseStub(int $promptTokens, int $completionTokens, string $text = 'hi'): \Prism\Prism\Text\Response
    {
        // Minimal Prism Text\Response with the fields ChatService reads.
        // Adjust to whatever constructor signature the installed Prism version exposes.
        return new \Prism\Prism\Text\Response(
            text: $text,
            usage: new \Prism\Prism\ValueObjects\Usage($promptTokens, $completionTokens),
            steps: [],
            finishReason: \Prism\Prism\Enums\FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            meta: new \Prism\Prism\ValueObjects\Meta(id: 'test', model: 'test'),
            messages: [],
            additionalContent: [],
        );
    }

    public function test_first_attempt_success_records_only_actual_usage(): void
    {
        // When the LLM succeeds on the first try, no failed-attempt
        // UsageRecord is written — only the response's actual usage.
        $tenant = $this->configureTenant([]);
        $conversation = \App\Models\Conversation::create([
            'tenant_id' => $tenant->id,
            'session_id' => 'first-success',
            'status' => 'active',
        ]);

        $service = $this->makeMockableService();
        $service->shouldReceive('dispatchToProvider')
            ->once()
            ->andReturn($this->makeTextResponseStub(100, 20));

        $service->generateResponse($conversation, 'hello');

        $records = \App\Models\UsageRecord::where('tenant_id', $tenant->id)
            ->where('type', 'tokens')
            ->get();

        $this->assertCount(1, $records, 'one usage record on first-attempt success');
        // 100 prompt + 20 completion = 120 total via UsageTracker.
        $this->assertSame(120, (int) $records[0]->quantity);
    }

    public function test_failed_attempts_get_estimated_usage_record(): void
    {
        // First two attempts throw a retryable 503; third succeeds.
        // The success record reflects real usage; an additional record
        // reflects the failed-attempt prompt estimates.
        $tenant = $this->configureTenant([]);
        $conversation = \App\Models\Conversation::create([
            'tenant_id' => $tenant->id,
            'session_id' => 'retry-success',
            'status' => 'active',
        ]);

        $service = $this->makeMockableService();
        $service->shouldReceive('dispatchToProvider')
            ->times(3)
            ->andReturnUsing(function () use (&$callCount) {
                static $n = 0;
                $n++;
                if ($n < 3) {
                    throw new \RuntimeException('HTTP 503 server error');
                }
                return $this->makeTextResponseStub(100, 20);
            });

        // Speed up the retry sleep so the test runs fast.
        // Laravel retry()'s sleepMilliseconds is in ms; tests inherit the
        // production values. If this proves slow, swap to a config-driven
        // sleep, but the 1s+2s = 3s total should be acceptable for one test.

        \Illuminate\Support\Facades\Log::shouldReceive('warning')->zeroOrMoreTimes();
        \Illuminate\Support\Facades\Log::shouldReceive('debug')->zeroOrMoreTimes();
        \Illuminate\Support\Facades\Log::shouldReceive('info')->zeroOrMoreTimes();
        \Illuminate\Support\Facades\Log::shouldReceive('notice')->zeroOrMoreTimes();
        \Illuminate\Support\Facades\Log::shouldReceive('error')->zeroOrMoreTimes();

        $service->generateResponse($conversation, 'hello');

        $records = \App\Models\UsageRecord::where('tenant_id', $tenant->id)
            ->where('type', 'tokens')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $records, 'one success record + one failed-attempts record');

        // One record is the real success usage (120 total).
        $this->assertSame(120, (int) $records[0]->quantity, 'first record is the real success usage');

        // The other is estimated usage for 2 failed attempts. Estimate is
        // 2 * (system_prompt_chars + messages_chars) / 4 tokens. Won't
        // equal 120 in any realistic scenario for this fixture.
        $this->assertGreaterThan(0, (int) $records[1]->quantity);
        $this->assertNotSame(120, (int) $records[1]->quantity);
    }

    public function test_total_failure_still_records_failed_attempt_usage(): void
    {
        // All three attempts fail. No success usage to record, but the
        // failed attempts may have been billed by the provider — write
        // a UsageRecord so ops can reconcile with Groq's invoice.
        $tenant = $this->configureTenant([]);
        $conversation = \App\Models\Conversation::create([
            'tenant_id' => $tenant->id,
            'session_id' => 'total-failure',
            'status' => 'active',
        ]);

        $service = $this->makeMockableService();
        $service->shouldReceive('dispatchToProvider')
            ->times(3)
            ->andThrow(new \RuntimeException('HTTP 503 server error'));

        \Illuminate\Support\Facades\Log::shouldReceive('warning')->zeroOrMoreTimes();
        \Illuminate\Support\Facades\Log::shouldReceive('debug')->zeroOrMoreTimes();
        \Illuminate\Support\Facades\Log::shouldReceive('info')->zeroOrMoreTimes();
        \Illuminate\Support\Facades\Log::shouldReceive('notice')->zeroOrMoreTimes();
        \Illuminate\Support\Facades\Log::shouldReceive('error')->zeroOrMoreTimes();

        $response = $service->generateResponse($conversation, 'hello');

        $this->assertStringContainsString("having trouble", $response, 'fallback returned to user');

        $records = \App\Models\UsageRecord::where('tenant_id', $tenant->id)
            ->where('type', 'tokens')
            ->get();

        $this->assertCount(1, $records, 'failed-attempt usage record written even on total failure');
        $this->assertGreaterThan(0, (int) $records[0]->quantity);
    }
```

**Note on the `Text\Response` constructor:** the installed Prism version's `Text\Response` constructor takes named arguments — adjust the `makeTextResponseStub` shape if Prism's signature differs. Run `php -r "echo (new \ReflectionClass(\Prism\Prism\Text\Response::class))->getConstructor()->getNumberOfParameters();"` to see the parameter count, then read the source if needed.

**Note on `Usage`:** Prism's `Usage` constructor is `(int $promptTokens, int $completionTokens, ?int $cacheWriteInputTokens = null, ?int $cacheReadInputTokens = null, ?int $thoughtTokens = null)`. There is NO `totalTokens` parameter. The third argument is `cacheWriteInputTokens`, not a total. Pass only 2 args; `UsageTracker::recordTokens` falls back to `prompt + completion` when no total is supplied.

### Step 2: Run, confirm tests FAIL

```bash
php artisan test --filter='first_attempt_success|failed_attempts_get|total_failure_still'
```

Expected: 2-3 failures — the failed-attempt UsageRecord write doesn't exist yet, and total-failure path writes 0 records.

### Step 3: Patch `generateResponse`

In `app/Services/LLM/ChatService.php`, modify `generateResponse` to extract `dispatchToProvider`, track failed attempts, and emit the additional UsageRecord. The shape:

```php
    public function generateResponse(
        Conversation $conversation,
        string $userMessage,
        array $context = []
    ): string {
        /** @var Tenant $tenant */
        $tenant = $conversation->tenant;

        Log::debug('[LLM] (IS $) Generating response', [
            'conversation_id' => $conversation->id,
            'tenant_id' => $tenant->id,
            'provider' => $this->provider->value,
            'model' => $this->model,
        ]);

        $systemPrompt = $this->buildSystemPrompt($tenant, $context, $conversation);
        $messages = $this->buildMessageHistory($conversation);
        $messages[] = new UserMessage($userMessage);

        // Per-attempt prompt-token estimate. The same prompt is submitted
        // on every retry, so this is a constant per-call estimate that we
        // multiply by failed-attempt count to charge Groq's likely bill.
        $estimatedPromptTokensPerAttempt = $this->estimateTokens($systemPrompt)
            + array_sum(array_map(
                fn ($m) => $this->estimateTokens($m->content),
                $messages,
            ));

        $failedAttempts = 0;

        try {
            $response = retry(
                times: 3,
                callback: function (int $attempt) use ($systemPrompt, $messages, $conversation, &$failedAttempts, $estimatedPromptTokensPerAttempt) {
                    if ($attempt > 1) {
                        $failedAttempts++;
                        Log::warning('[LLM] (IS $) Retry attempt', [
                            'conversation_id' => $conversation->id,
                            'attempt' => $attempt,
                            'previous_attempt_estimated_prompt_tokens' => $estimatedPromptTokensPerAttempt,
                        ]);
                    }

                    return $this->dispatchToProvider($systemPrompt, $messages);
                },
                sleepMilliseconds: fn (int $attempt) => match ($attempt) {
                    1 => 1000,
                    2 => 2000,
                    default => 4000,
                },
                when: function (\Throwable $e) {
                    $message = $e->getMessage();
                    return str_contains($message, '429')
                        || str_contains($message, '500')
                        || str_contains($message, '503')
                        || str_contains($message, 'Connection')
                        || str_contains($message, 'timeout')
                        || str_contains($message, 'CURL');
                },
            );

            $this->trackUsage($tenant, $conversation, $response->usage);
            $this->trackFailedAttempts($tenant, $conversation, $failedAttempts, $estimatedPromptTokensPerAttempt);

            Log::debug('[LLM] (IS $) Response generated', [
                'conversation_id' => $conversation->id,
                'tokens' => $response->usage->promptTokens + $response->usage->completionTokens,
                'failed_attempts' => $failedAttempts,
            ]);

            return $response->text;
        } catch (\Exception $e) {
            Log::error('[LLM] Response generation failed after retries', [
                'conversation_id' => $conversation->id,
                'failed_attempts' => $failedAttempts + 1, // +1 for the final failure
                'error' => $e->getMessage(),
            ]);

            // The final attempt also failed at the provider — count it for billing.
            $this->trackFailedAttempts(
                $tenant,
                $conversation,
                $failedAttempts + 1,
                $estimatedPromptTokensPerAttempt,
            );

            return $this->getFallbackResponse();
        }
    }

    /**
     * Single provider-call wrapper around Prism, kept as its own method
     * so the retry path is testable: tests partial-mock this method via
     * Mockery to throw on early attempts and return a stub on later ones
     * without fighting Prism's fake API (which has no exception path).
     */
    protected function dispatchToProvider(string $systemPrompt, array $messages): \Prism\Prism\Text\Response
    {
        return Prism::text()
            ->using($this->provider, $this->model)
            ->withSystemPrompt($systemPrompt)
            ->withMessages($messages)
            ->withClientOptions(['timeout' => 60])
            ->asText();
    }
```

`dispatchToProvider` is `protected` (not `private`) so the partial Mockery mock in tests can override it via `shouldAllowMockingProtectedMethods()`. The method does NOT need a corresponding `private` interface anywhere else — Mockery rewires it at runtime.

**Add the `trackFailedAttempts` helper** near `trackUsage`:

```php
    /**
     * Record an estimated UsageRecord for failed retry attempts. The
     * provider (Groq) may bill for 5xx attempts that crossed the network
     * after processing started; tracking them keeps platform usage in
     * line with the actual invoice. Token count is estimated via the
     * 4-chars-per-token heuristic.
     */
    private function trackFailedAttempts(
        Tenant $tenant,
        Conversation $conversation,
        int $failedAttempts,
        int $estimatedPromptTokensPerAttempt,
    ): void {
        if ($failedAttempts <= 0) {
            return;
        }

        $estimatedTotal = $failedAttempts * $estimatedPromptTokensPerAttempt;

        $this->usageTracker->recordTokens(
            $tenant,
            $conversation,
            $estimatedTotal, // prompt tokens
            0,                // no completion tokens for failed attempts
            $estimatedTotal,
        );

        Log::info('[LLM] Failed-attempt usage recorded', [
            'conversation_id' => $conversation->id,
            'tenant_id' => $tenant->id,
            'failed_attempts' => $failedAttempts,
            'estimated_total_tokens' => $estimatedTotal,
        ]);
    }
```

Note: `Log::info` (not `warning`) — a request that ultimately succeeded after retries is a success-with-degradation, not an error. Total-failure paths route through `Log::error` in `generateResponse`'s outer catch, which gives ops the right severity for the actually-broken case.

### Step 4: Run, confirm tests PASS

```bash
php artisan test --filter='first_attempt_success|failed_attempts_get|total_failure_still'
```

Expected: 3 green.

### Step 5: Full suite

```bash
php artisan test
```

Expected: 242 passed (239 from Task 1 + 3 new). Existing tests for `generateResponse` should be unaffected because the failed-attempt path is opt-in (only fires when `$failedAttempts > 0`).

### Step 6: Commit

```bash
git add app/Services/LLM/ChatService.php tests/Unit/Services/LLM/ChatServiceTest.php
git commit -m "$(cat <<'EOF'
fix(llm): record estimated usage for failed retry attempts

Closes H-NEW-8. Previously only the final successful response's
usage was recorded, but Groq may bill for 5xx attempts that crossed
the network. Tenants with transient errors quietly under-reported
usage and could exceed Groq quotas without the platform noticing.

generateResponse now tracks failed retry attempts inside the retry
closure. After the chain completes (success OR total failure), an
additional UsageRecord is written via trackFailedAttempts covering
$failedAttempts * estimatedPromptTokensPerAttempt prompt tokens.
The success-path UsageRecord for the actual response is unchanged.

Token estimation uses the same 4-chars-per-token heuristic from
Task 1. Off ~20% from real tokenizer output, but the goal is to
make Groq's invoice reconcilable, not exact.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Browser smoke + simplify + PR

- [ ] **Step 1: Smoke chat round-trip**

```bash
TENANT_KEY=$(php artisan tinker --execute="echo \App\Models\Tenant::find(1)->api_key;" | tail -1)

curl -s -X POST http://127.0.0.1:8001/api/v1/widget/conversation \
  -H "Content-Type: application/json" \
  -d "{\"api_key\":\"$TENANT_KEY\",\"session_id\":\"smoke-llm-budget\"}"
```

Note the returned `conversation_id`, then:

```bash
curl -s -X POST http://127.0.0.1:8001/api/v1/widget/message \
  -H "Content-Type: application/json" \
  -d "{\"api_key\":\"$TENANT_KEY\",\"conversation_id\":N,\"message\":\"What services do you offer?\"}" | head -c 400
```

Expected: a normal company-info response, identical to pre-change behavior. No errors, no degraded output.

- [ ] **Step 2: Verify UsageRecord shape**

```bash
php artisan tinker --execute="
\$last = \App\Models\UsageRecord::where('type', 'tokens')->latest()->limit(2)->get(['quantity', 'created_at', 'conversation_id']);
echo json_encode(\$last);
"
```

Expected: at least one record from the smoke chat. If the request retried (rare in dev), a second record with smaller `quantity` (the estimated failed-attempt cost) is also present.

- [ ] **Step 3: `/simplify`**

Multi-agent review. Apply high-confidence findings.

- [ ] **Step 4: Second-pass `/simplify`**

Catch issues the first pass introduced.

- [ ] **Step 5: Open PR**

Title: `fix: close H-NEW-7 prompt-budget guard + H-NEW-8 failed-attempt billing`

PR body should call out:
- H-NEW-7: `buildMessageHistory` now caps total estimated tokens at `MAX_HISTORY_TOKENS = 4000`. Combined with PR #10's bounded knowledge section, the total prompt size is now predictable.
- H-NEW-8: failed retry attempts now write a `UsageRecord` with the estimated prompt-token cost so Groq's invoice can be reconciled. Both success and total-failure paths emit this record when retries occurred.
- **Behavior change**: tenants whose conversations are extremely long will see older messages dropped from history. The bot will lose long-range context after ~10-15 average-sized messages. Acceptable per the audit's stated harm (orphan messages from context overflow) being the worse outcome.
- **Behavior change**: tenants who hit transient Groq errors will see slightly higher token usage reported (estimated tokens for the failed attempts). Matches actual provider billing more closely.

---

## Out of scope (followups)

- **Dead `property_exists($usage, 'totalTokens')` branch in `trackUsage`.** Prism's `Usage` value object has no `totalTokens` property — the dead branch always falls through to `prompt + completion`. Plan-review surfaced this; cleanup is intentionally deferred to keep the diff focused on the audit findings. The dead branch doesn't break anything; it's just confusing for future readers.



- **Per-provider token budgets** — the 4000-token constant is hard-coded. A future change could read it from `config/services.php` keyed by provider. Out of scope for v1.
- **Real tokenizer integration** — the 4-chars-per-token heuristic is off by ~20%. Using a real tokenizer library (e.g. `Yethee\Tiktoken`) would be more accurate but adds a dependency. Acceptable for v1 because the budget has safety margin.
- **Per-attempt completion-token tracking** — Prism's exception path may or may not surface partial-completion usage; current design only credits prompt tokens for failed attempts. Acceptable because Groq's typical 5xx happens before completion generation.
- **Summarizing older history instead of dropping** — would preserve long-range context but adds an LLM call per request. Out of scope.
- **15 remaining Medium findings** from the May 2026 audit — separate plans.
