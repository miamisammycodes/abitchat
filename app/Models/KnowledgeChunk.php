<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeChunk extends Model
{
    protected $fillable = [
        'knowledge_item_id',
        'content',
        'embedding',
        'token_count',
        'chunk_index',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'token_count' => 'integer',
            'chunk_index' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<KnowledgeItem, $this> */
    public function knowledgeItem(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class);
    }

    public function hasEmbedding(): bool
    {
        return $this->embedding !== null;
    }
}
