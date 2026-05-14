<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KnowledgeItemStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\BustsTenantUsageCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property KnowledgeItemStatus $status
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $failed_at
 */
class KnowledgeItem extends Model
{
    use BelongsToTenant, BustsTenantUsageCache;

    protected $fillable = [
        'tenant_id',
        'title',
        'type',
        'content',
        'source_url',
        'file_path',
        'status',
        'metadata',
        'error_message',
        'failed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'status' => KnowledgeItemStatus::class,
            'failed_at' => 'datetime',
        ];
    }

    /** @return HasMany<KnowledgeChunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function isPending(): bool
    {
        return $this->status === KnowledgeItemStatus::Pending;
    }

    public function isProcessing(): bool
    {
        return $this->status === KnowledgeItemStatus::Processing;
    }

    public function isReady(): bool
    {
        return $this->status === KnowledgeItemStatus::Ready;
    }

    public function isFailed(): bool
    {
        return $this->status === KnowledgeItemStatus::Failed;
    }
}
