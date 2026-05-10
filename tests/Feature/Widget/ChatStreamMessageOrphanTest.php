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
    protected Tenant $tenant;
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

        // CRITICAL: $response->getContent() returns false on a StreamedResponse
        // and never invokes the streaming closure. Use streamedContent() to
        // actually run the closure synchronously and capture its output.
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
