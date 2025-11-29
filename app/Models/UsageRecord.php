<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'type',
        'quantity',
        'recorded_date',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'recorded_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
