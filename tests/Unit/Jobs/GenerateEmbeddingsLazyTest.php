<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\KnowledgeItemStatus;
use App\Jobs\GenerateEmbeddings;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Services\Knowledge\EmbeddingService;
use App\Services\Knowledge\KnowledgeItemWorkflow;
use Prism\Prism\Facades\Prism;
use Tests\Support\EmbeddingFakeFactory;
use Tests\TestCase;

class GenerateEmbeddingsLazyTest extends TestCase
{
    public function test_processes_all_chunks_and_marks_item_ready(): void
    {
        $tenant = Tenant::create([
            'name' => 'KB', 'slug' => 'kb-'.uniqid(),
            'status' => 'active', 'trial_ends_at' => now()->addDays(14),
        ]);

        $item = KnowledgeItem::create([
            'tenant_id' => $tenant->id,
            'type' => 'text',
            'title' => 'Lazy test',
            'content' => 'x',
            'status' => 'processing',
        ]);

        // Create more chunks than would comfortably fit in a single in-memory
        // collection if we cared about that constraint — small enough to keep
        // the test fast.
        $now = now();
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = [
                'knowledge_item_id' => $item->id,
                'content' => "chunk {$i}",
                'chunk_index' => $i,
                'embedding' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        KnowledgeChunk::insert($rows);

        // Fake 30 embedding responses with valid 768-dim vectors.
        Prism::fake(EmbeddingFakeFactory::many(30));

        (new GenerateEmbeddings($item))->handle(app(EmbeddingService::class), app(KnowledgeItemWorkflow::class));

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::Ready, $item->status);
        $this->assertSame(
            0,
            $item->chunks()->whereNull('embedding')->count(),
            'every chunk must have its embedding populated after the job runs',
        );
    }
}
