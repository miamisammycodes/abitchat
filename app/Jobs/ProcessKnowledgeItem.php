<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Services\Knowledge\DocumentProcessor;
use App\Services\Knowledge\TextChunker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

    public function handle(DocumentProcessor $processor, TextChunker $chunker): void
    {
        Log::debug('[Knowledge] (NO $) Processing item', [
            'item_id' => $this->item->id,
            'type' => $this->item->type,
        ]);

        $this->item->markAsProcessing();

        try {
            // Extract text content based on type
            $content = $this->extractContent($processor);

            if (empty($content)) {
                throw new \Exception('No content could be extracted');
            }

            // Update item with extracted content
            if ($this->item->type !== 'faq' && $this->item->type !== 'text') {
                $this->item->update(['content' => $content]);
            }

            // Chunk the content
            $chunks = $chunker->chunk($content);

            Log::debug('[Knowledge] (NO $) Content chunked', [
                'item_id' => $this->item->id,
                'chunks_count' => count($chunks),
            ]);

            // Store chunks
            foreach ($chunks as $index => $chunkContent) {
                KnowledgeChunk::create([
                    'knowledge_item_id' => $this->item->id,
                    'content' => $chunkContent,
                    'chunk_index' => $index,
                    'embedding' => null, // Will be filled by embedding job
                ]);
            }

            // Dispatch embedding job for each chunk
            GenerateEmbeddings::dispatch($this->item);

            $this->item->markAsReady();

            Log::debug('[Knowledge] (NO $) Item processed successfully', [
                'item_id' => $this->item->id,
            ]);
        } catch (\Exception $e) {
            Log::error('[Knowledge] Processing failed', [
                'item_id' => $this->item->id,
                'error' => $e->getMessage(),
            ]);

            $this->item->markAsFailed();
            throw $e;
        }
    }

    private function extractContent(DocumentProcessor $processor): string
    {
        return match ($this->item->type) {
            'document' => $processor->extractFromFile($this->item->file_path),
            'webpage' => $processor->extractFromUrl($this->item->source_url),
            'faq', 'text' => $this->item->content ?? '',
            default => '',
        };
    }
}
