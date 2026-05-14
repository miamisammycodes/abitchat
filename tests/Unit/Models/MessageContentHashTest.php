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
            'name' => 'H', 'slug' => 'h-'.uniqid(),
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
            'name' => 'H2', 'slug' => 'h2-'.uniqid(),
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
