<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Services\Crawler\RenderOnFallback;
use App\Services\Knowledge\ContentSufficiency;
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

    public function handle(DocumentProcessor $processor, KnowledgeItemWorkflow $workflow, ContentSufficiency $gate, RenderOnFallback $resolver): void
    {
        Log::debug('[Knowledge] (NO $) Processing item', [
            'item_id' => $this->item->id,
            'type' => $this->item->type,
        ]);

        $workflow->markProcessing($this->item);

        try {
            [$text, $sufficient] = $this->resolveContent($processor, $gate, $resolver);

            if (! $sufficient) {
                $this->markSkipped($workflow);

                return;
            }

            if (in_array($this->item->type, ['webpage', 'document'], true)) {
                $this->item->update(['content' => $text]);
            }

            $chunks = $processor->chunk($text);

            if ($chunks === []) {
                $this->markSkipped($workflow);

                return;
            }

            // Replace any prior chunk set atomically — guards the tries=3 retry
            // path from appending duplicates.
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

    /**
     * Resolve clean text + sufficiency. A manual webpage add (source_url, no
     * stored content) fetches the body and runs render-on-fallback; every other
     * case uses the already-clean content via DocumentProcessor::extract.
     *
     * @return array{0: string, 1: bool}
     */
    private function resolveContent(DocumentProcessor $processor, ContentSufficiency $gate, RenderOnFallback $resolver): array
    {
        $item = $this->item;

        if ($item->type === 'webpage'
            && ($item->content === null || $item->content === '')
            && $item->source_url !== null) {
            $body = $processor->fetchUrl($item->source_url);
            $resolution = $resolver->resolve($item->source_url, $body);

            return [$resolution['text'], $resolution['sufficient']];
        }

        $text = $processor->extract($item);

        return [$text, $gate->isSufficient($text)];
    }

    private function markSkipped(KnowledgeItemWorkflow $workflow): void
    {
        $this->item->chunks()->delete();
        $this->item->forceFill([
            'metadata' => array_merge((array) $this->item->metadata, [
                'skipped_reason' => 'no_content',
                'skipped_at' => now()->toIso8601String(),
            ]),
        ])->save();

        $workflow->markSkippedNoContent($this->item);

        Log::debug('[Knowledge] (NO $) Item skipped — no readable content', [
            'item_id' => $this->item->id,
        ]);
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
