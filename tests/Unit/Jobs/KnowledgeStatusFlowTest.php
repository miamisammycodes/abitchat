<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Exceptions\EmbeddingGenerationException;
use App\Jobs\GenerateEmbeddings;
use App\Jobs\ProcessKnowledgeItem;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Services\Knowledge\DocumentProcessor;
use App\Services\Knowledge\EmbeddingService;
use App\Services\Knowledge\TextChunker;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class KnowledgeStatusFlowTest extends TestCase
{
    private function makeItem(): KnowledgeItem
    {
        $tenant = Tenant::create([
            'name' => 'Flow Co',
            'slug' => 'flow-co-' . uniqid(),
            'status' => 'active',
        ]);

        return KnowledgeItem::create([
            'tenant_id' => $tenant->id,
            'type' => 'text',
            'title' => 'Test',
            'content' => 'Long enough content to clear the 50 char minimum chunk filter for chunking.',
            'status' => 'pending',
        ]);
    }

    public function test_process_job_leaves_item_in_processing_until_embeddings_complete(): void
    {
        Queue::fake([GenerateEmbeddings::class]);
        $item = $this->makeItem();

        (new ProcessKnowledgeItem($item))->handle(
            app(DocumentProcessor::class),
            app(TextChunker::class),
        );

        $item->refresh();
        $this->assertSame(
            'processing',
            $item->status,
            'Item must NOT be ready until embeddings complete'
        );
        Queue::assertPushed(GenerateEmbeddings::class);
    }

    public function test_embeddings_job_marks_ready_on_success(): void
    {
        $item = $this->makeItem();
        $item->chunks()->create([
            'content' => 'chunk-a',
            'chunk_index' => 0,
            'embedding' => null,
        ]);
        $item->markAsProcessing();

        $embedder = Mockery::mock(EmbeddingService::class);
        $embedder->shouldReceive('generate')->andReturn('[0.1,0.2,0.3]');

        (new GenerateEmbeddings($item))->handle($embedder);

        $item->refresh();
        $this->assertSame('ready', $item->status);
    }

    public function test_embeddings_job_failed_callback_marks_failed(): void
    {
        $item = $this->makeItem();
        $item->markAsProcessing();

        $job = new GenerateEmbeddings($item);
        $job->failed(new EmbeddingGenerationException('all retries exhausted'));

        $item->refresh();
        $this->assertSame('failed', $item->status);
    }
}
