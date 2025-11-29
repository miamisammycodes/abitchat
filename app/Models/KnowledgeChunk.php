<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'knowledge_item_id',
        'content',
        'embedding',
        'token_count',
        'chunk_index',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'token_count' => 'integer',
            'chunk_index' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function knowledgeItem(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class);
    }

    public function hasEmbedding(): bool
    {
        return $this->embedding !== null;
    }
}
