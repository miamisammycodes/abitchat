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

class GenerateEmbeddings implements NotTenantAware, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public KnowledgeItem $item
    ) {}

    public function handle(EmbeddingService $embeddingService): void
    {
        $processed = 0;

        try {
            $this->item->chunks()
                ->whereNull('embedding')
                ->lazyById(50)
                ->each(function ($chunk) use ($embeddingService, &$processed) {
                    $embedding = $embeddingService->generate($chunk->content);
                    $chunk->update(['embedding' => $embedding]);
                    $processed++;
                });

            $this->item->markAsReady();

            Log::debug('[Embeddings] (NO $) Embeddings generated; item ready', [
                'item_id' => $this->item->id,
                'processed' => $processed,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Embeddings] Generation failed', [
                'item_id' => $this->item->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[Embeddings] Job failed after retries — marking item failed', [
            'item_id' => $this->item->id,
            'error' => $exception->getMessage(),
        ]);
        $this->item->markAsFailed();
    }
}
