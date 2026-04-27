<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Services\Knowledge\EmbeddingService;
use App\Services\Knowledge\RetrievalService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

/**
 * The vector-similarity path requires Postgres + pgvector and is exercised
 * end-to-end at runtime. These unit tests cover the pieces that are
 * portable: keyword extraction, the keyword-fallback flow when embedding
 * generation fails, and cache-key versioning behaviour.
 */
class RetrievalServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenantWithUser();
    }

    private function makeServiceWithFailingEmbeddings(): RetrievalService
    {
        $embedder = Mockery::mock(EmbeddingService::class);
        $embedder->shouldReceive('generate')->andReturn(null);

        return new RetrievalService($embedder);
    }

    private function makeServiceWithSuccessfulEmbeddings(string $vector = '[0.1,0.2,0.3]'): RetrievalService
    {
        $embedder = Mockery::mock(EmbeddingService::class);
        $embedder->shouldReceive('generate')->andReturn($vector);

        return new RetrievalService($embedder);
    }

    private function callExtractKeywords(RetrievalService $service, string $text): array
    {
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('extractKeywords');
        $method->setAccessible(true);

        return (array) $method->invoke($service, $text);
    }

    private function seedReadyKnowledgeWithChunks(array $chunkContents): KnowledgeItem
    {
        $item = KnowledgeItem::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'text',
            'title' => 'Test Item',
            'content' => 'placeholder',
            'status' => 'ready',
        ]);

        foreach ($chunkContents as $i => $content) {
            KnowledgeChunk::create([
                'knowledge_item_id' => $item->id,
                'content' => $content,
                'chunk_index' => $i,
            ]);
        }

        return $item;
    }

    public function test_extract_keywords_drops_stop_words(): void
    {
        $service = $this->makeServiceWithFailingEmbeddings();

        $keywords = $this->callExtractKeywords($service, 'What are the prices for your services?');

        $this->assertNotContains('what', $keywords);
        $this->assertNotContains('are', $keywords);
        $this->assertNotContains('the', $keywords);
        $this->assertNotContains('for', $keywords);
        $this->assertContains('prices', $keywords);
        $this->assertContains('services', $keywords);
    }

    public function test_extract_keywords_drops_words_under_three_chars(): void
    {
        $service = $this->makeServiceWithFailingEmbeddings();

        $keywords = $this->callExtractKeywords($service, 'AI ML go fly bot');

        // 'ai', 'ml', 'go' are <= 2 chars → filtered
        $this->assertNotContains('ai', $keywords);
        $this->assertNotContains('ml', $keywords);
        $this->assertNotContains('go', $keywords);
        $this->assertContains('fly', $keywords);
        $this->assertContains('bot', $keywords);
    }

    public function test_extract_keywords_lowercases_and_deduplicates(): void
    {
        $service = $this->makeServiceWithFailingEmbeddings();

        $keywords = $this->callExtractKeywords($service, 'Refund REFUND refund Refund');

        $this->assertSame(['refund'], $keywords);
    }

    public function test_extract_keywords_returns_empty_for_stop_word_only_query(): void
    {
        $service = $this->makeServiceWithFailingEmbeddings();

        $keywords = $this->callExtractKeywords($service, 'what are the');

        $this->assertSame([], $keywords);
    }

    public function test_keyword_fallback_returns_empty_when_no_significant_keywords(): void
    {
        $service = $this->makeServiceWithFailingEmbeddings();
        $this->seedReadyKnowledgeWithChunks(['Some content about refunds and pricing']);

        // Query is all stop words → no keywords → empty result, no DB query needed.
        $result = $service->retrieveByKeywords($this->tenant, 'what are the');

        $this->assertSame([], $result);
    }

    public function test_keyword_fallback_finds_matching_chunk_content(): void
    {
        $service = $this->makeServiceWithFailingEmbeddings();
        $this->seedReadyKnowledgeWithChunks([
            'Our refund policy allows returns within 14 days.',
            'Shipping is free over $50.',
        ]);

        $result = $service->retrieveByKeywords($this->tenant, 'tell me about refund policy');

        $this->assertCount(1, $result);
        $this->assertStringContainsString('refund policy', $result[0]);
    }

    public function test_keyword_fallback_excludes_chunks_from_unready_items(): void
    {
        $service = $this->makeServiceWithFailingEmbeddings();

        // Ready item — should match.
        $this->seedReadyKnowledgeWithChunks(['Refund window is 14 days.']);

        // Processing item — must NOT match.
        $processing = KnowledgeItem::create([
            'tenant_id' => $this->tenant->id,
            'type' => 'text',
            'title' => 'Processing',
            'content' => 'placeholder',
            'status' => 'processing',
        ]);
        KnowledgeChunk::create([
            'knowledge_item_id' => $processing->id,
            'content' => 'Refund unavailable on processing items.',
            'chunk_index' => 0,
        ]);

        $result = $service->retrieveByKeywords($this->tenant, 'refund');

        $this->assertCount(1, $result);
        $this->assertStringContainsString('Refund window', $result[0]);
    }

    public function test_keyword_fallback_is_scoped_to_tenant(): void
    {
        $service = $this->makeServiceWithFailingEmbeddings();
        $this->seedReadyKnowledgeWithChunks(['Refund within 14 days for tenant A.']);

        // Other tenant with overlapping keyword — must NOT leak.
        $otherTenant = \App\Models\Tenant::create([
            'name' => 'Other Co',
            'slug' => 'other-co',
            'status' => 'active',
        ]);
        $otherItem = KnowledgeItem::create([
            'tenant_id' => $otherTenant->id,
            'type' => 'text',
            'title' => 'Other',
            'content' => 'placeholder',
            'status' => 'ready',
        ]);
        KnowledgeChunk::create([
            'knowledge_item_id' => $otherItem->id,
            'content' => 'Refund policy of competitor tenant.',
            'chunk_index' => 0,
        ]);

        $result = $service->retrieveByKeywords($this->tenant, 'refund');

        $this->assertCount(1, $result);
        $this->assertStringContainsString('tenant A', $result[0]);
    }

    public function test_retrieve_falls_back_to_keywords_when_embedding_generation_fails(): void
    {
        // EmbeddingService returns null → vector path is skipped.
        $service = $this->makeServiceWithFailingEmbeddings();
        $this->seedReadyKnowledgeWithChunks(['Refund within 14 days.']);

        // Use a unique query so we don't collide with any cached value from
        // earlier tests (Cache is array-driven in tests but resets per test).
        $result = $service->retrieve($this->tenant, 'tell me about refund');

        $this->assertCount(1, $result);
        $this->assertStringContainsString('Refund within 14 days.', $result[0]);
    }

    public function test_retrieve_caches_results_per_query(): void
    {
        Cache::flush();
        $service = $this->makeServiceWithFailingEmbeddings();
        $this->seedReadyKnowledgeWithChunks(['Cached content fragment.']);

        // First call populates cache.
        $first = $service->retrieve($this->tenant, 'cached fragment');

        // Mutate the underlying chunk so a fresh query would return different
        // content. The cache should still serve the original answer.
        KnowledgeChunk::query()->update(['content' => 'completely different content']);

        $second = $service->retrieve($this->tenant, 'cached fragment');

        $this->assertSame($first, $second);
    }

    public function test_retrieve_busts_cache_when_knowledge_version_changes(): void
    {
        Cache::flush();
        $service = $this->makeServiceWithFailingEmbeddings();
        $this->seedReadyKnowledgeWithChunks(['Original content fragment.']);

        $first = $service->retrieve($this->tenant, 'fragment search');
        $this->assertCount(1, $first);

        // Bump the knowledge version (simulating an upload/delete).
        Cache::increment("knowledge_version:{$this->tenant->id}");

        // Replace underlying data — version bump should force a DB re-read.
        KnowledgeChunk::query()->update(['content' => 'Updated fragment payload.']);

        $second = $service->retrieve($this->tenant, 'fragment search');

        $this->assertCount(1, $second);
        $this->assertStringContainsString('Updated fragment', $second[0]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
