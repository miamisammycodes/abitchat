<?php

declare(strict_types=1);

namespace Tests\Feature\Widget;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Knowledge\RetrievalService;
use App\Services\LLM\ChatService;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

class ChatSendMessageOrphanTest extends TestCase
{
    protected Tenant $tenant;

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

    private function postMessage(): TestResponse
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
        $retrieval = Mockery::mock(RetrievalService::class);
        $retrieval->shouldReceive('retrieve')->andReturn([]);
        $this->app->instance(RetrievalService::class, $retrieval);

        $chat = Mockery::mock(ChatService::class);
        $chat->shouldReceive('generateResponse')
            ->once()->andThrow(new \RuntimeException('groq 503'))
            ->ordered();
        $chat->shouldReceive('generateResponse')
            ->once()->andReturn('finally')
            ->ordered();
        $this->app->instance(ChatService::class, $chat);

        $this->postMessage()->assertStatus(500);
        $this->postMessage()->assertOk();

        $count = Message::where('conversation_id', $this->conversation->id)->count();
        $this->assertSame(2, $count, 'Retry must not accumulate orphan user messages');
    }
}
