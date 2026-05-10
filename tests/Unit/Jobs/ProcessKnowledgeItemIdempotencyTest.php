<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateEmbeddings;
use App\Jobs\ProcessKnowledgeItem;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Services\Knowledge\DocumentProcessor;
use App\Services\Knowledge\TextChunker;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ProcessKnowledgeItemIdempotencyTest extends TestCase
{
    private function makeItem(): KnowledgeItem
    {
        $tenant = Tenant::create([
            'name' => 'Idem Co',
            'slug' => 'idem-co',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => []],
        ]);

        return KnowledgeItem::create([
            'tenant_id' => $tenant->id,
            'type' => 'text',
            'title' => 'Test',
            'content' => 'Some content body for chunking.',
            'status' => 'pending',
        ]);
    }

    public function test_running_the_job_twice_does_not_duplicate_chunks(): void
    {
        Queue::fake([GenerateEmbeddings::class]);
        $item = $this->makeItem();

        $chunker = Mockery::mock(TextChunker::class);
        $chunker->shouldReceive('chunk')->andReturn(['chunk-a', 'chunk-b']);
        $processor = Mockery::mock(DocumentProcessor::class);

        // First invocation — clean state.
        (new ProcessKnowledgeItem($item))->handle($processor, $chunker);
        $this->assertSame(2, KnowledgeChunk::where('knowledge_item_id', $item->id)->count());

        // Second invocation — simulates a Laravel retry. Must produce the
        // same 2 chunks, not 4.
        (new ProcessKnowledgeItem($item))->handle($processor, $chunker);
        $this->assertSame(
            2,
            KnowledgeChunk::where('knowledge_item_id', $item->id)->count(),
            'Re-running the job must not append duplicate chunks'
        );
    }

    public function test_chunk_content_matches_after_retry(): void
    {
        Queue::fake([GenerateEmbeddings::class]);
        $item = $this->makeItem();

        $first = Mockery::mock(TextChunker::class);
        $first->shouldReceive('chunk')->andReturn(['old-1', 'old-2']);
        (new ProcessKnowledgeItem($item))->handle(Mockery::mock(DocumentProcessor::class), $first);

        $second = Mockery::mock(TextChunker::class);
        $second->shouldReceive('chunk')->andReturn(['new-1', 'new-2', 'new-3']);
        (new ProcessKnowledgeItem($item))->handle(Mockery::mock(DocumentProcessor::class), $second);

        $contents = KnowledgeChunk::where('knowledge_item_id', $item->id)
            ->orderBy('chunk_index')
            ->pluck('content')
            ->all();
        $this->assertSame(['new-1', 'new-2', 'new-3'], $contents);
    }
}
