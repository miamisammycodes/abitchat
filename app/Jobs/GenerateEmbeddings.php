<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeItem;
use App\Services\Knowledge\EmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class GenerateEmbeddings implements ShouldQueue, NotTenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public KnowledgeItem $item
    ) {}

    public function handle(EmbeddingService $embeddingService): void
    {
        Log::debug('[Embeddings] (IS $) Generating embeddings', [
            'item_id' => $this->item->id,
            'chunks_count' => $this->item->chunks()->count(),
        ]);

        try {
            $chunks = $this->item->chunks()->whereNull('embedding')->get();

            foreach ($chunks as $chunk) {
                $embedding = $embeddingService->generate($chunk->content);

                $chunk->update([
                    'embedding' => $embedding,
                ]);
            }

            Log::debug('[Embeddings] (IS $) Embeddings generated', [
                'item_id' => $this->item->id,
                'processed' => $chunks->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('[Embeddings] Generation failed', [
                'item_id' => $this->item->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
