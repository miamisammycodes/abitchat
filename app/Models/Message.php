<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'content_hash',
        'tokens_used',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::saving(function (Message $message) {
            // Only recompute when content actually changed. Without this guard,
            // every unrelated update (tokens_used, metadata) re-writes the hash
            // column with the same value and marks it dirty. isDirty returns
            // true on a fresh unsaved model when content has been set, so this
            // covers both create and update paths.
            if ($message->isDirty('content')) {
                $message->content_hash = md5((string) $message->content);
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tokens_used' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isFromUser(): bool
    {
        return $this->role === 'user';
    }

    public function isFromAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    public function isSystem(): bool
    {
        return $this->role === 'system';
    }
}
