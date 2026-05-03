<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageRecord extends Model
{
    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'type',
        'quantity',
        'period',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isTokenUsage(): bool
    {
        return $this->type === 'tokens';
    }

    public function isMessageUsage(): bool
    {
        return $this->type === 'messages';
    }

    public function isConversationUsage(): bool
    {
        return $this->type === 'conversations';
    }

    public function isStorageUsage(): bool
    {
        return $this->type === 'storage';
    }
}
