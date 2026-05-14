<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Services\Knowledge\DocumentProcessor;
use App\Services\Knowledge\KnowledgeItemWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class ProcessKnowledgeItem implements NotTenantAware, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public KnowledgeItem $item
    ) {}

    public function handle(DocumentProcessor $processor, KnowledgeItemWorkflow $workflow): void
    {
        Log::debug('[Knowledge] (NO $) Processing item', [
            'item_id' => $this->item->id,
            'type' => $this->item->type,
        ]);

        $workflow->markProcessing($this->item);

        try {
            $chunks = $processor->process($this->item);

            if ($chunks === []) {
                throw new \Exception('No content could be extracted');
            }

            Log::debug('[Knowledge] (NO $) Content chunked', [
                'item_id' => $this->item->id,
                'chunks_count' => count($chunks),
            ]);

            // Replace any prior chunk set atomically — guards against the
            // tries=3 retry path appending a duplicate set, and against a
            // partial-insert state surviving when the transaction throws.
            DB::transaction(function () use ($chunks): void {
                $this->item->chunks()->delete();

                $now = now();
                $rows = [];
                foreach ($chunks as $index => $chunkContent) {
                    $rows[] = [
                        'knowledge_item_id' => $this->item->id,
                        'content' => $chunkContent,
                        'chunk_index' => $index,
                        'embedding' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                KnowledgeChunk::insert($rows);
            });

            GenerateEmbeddings::dispatch($this->item);

            Log::debug('[Knowledge] (NO $) Chunks written; embedding job dispatched', [
                'item_id' => $this->item->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Knowledge] Processing failed', [
                'item_id' => $this->item->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[Knowledge] Job failed after retries — marking item failed', [
            'item_id' => $this->item->id,
            'error' => $exception->getMessage(),
        ]);

        app(KnowledgeItemWorkflow::class)->markFailed($this->item->refresh(), $exception);
    }
}
