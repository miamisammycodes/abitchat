<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Enums\KnowledgeItemStatus;
use App\Exceptions\EmbeddingGenerationException;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetrievalService
{
    public function __construct(
        private EmbeddingService $embeddingService,
        private KnowledgeCache $cache,
    ) {}

    /**
     * Retrieve relevant knowledge chunks for a query using pgvector
     * cosine-distance search, falling back to keyword LIKE if no
     * embedding can be generated.
     *
     * @return array<int, string>
     */
    public function retrieve(Tenant $tenant, string $query, int $limit = 5): array
    {
        $cached = $this->cache->get($tenant, $query);
        if ($cached !== null) {
            return $cached;
        }

        Log::debug('[RAG] (NO $) Retrieving context', [
            'tenant_id' => $tenant->id,
            'query_length' => strlen($query),
        ]);

        try {
            $queryVector = $this->embeddingService->generate($query);
        } catch (EmbeddingGenerationException $e) {
            Log::warning('[Retrieval] (IS $) Embedding failed, falling back to keyword search', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            $queryVector = null;
        }

        if ($queryVector === null) {
            Log::debug('[RAG] (NO $) No query embedding, using keyword fallback');

            $chunks = $this->retrieveByKeywords($tenant, $query, $limit);
            $this->cache->put($tenant, $query, $chunks);

            return $chunks;
        }

        $readyItemIds = KnowledgeItem::query()
            ->forTenant($tenant)
            ->where('status', KnowledgeItemStatus::Ready)
            ->select('id');

        $chunks = KnowledgeChunk::query()
            ->whereIn('knowledge_item_id', $readyItemIds)
            ->whereNotNull('embedding')
            ->orderByRaw('embedding <=> ?::vector', [$queryVector])
            ->limit($limit)
            ->pluck('content')
            ->all();

        if ($chunks === []) {
            Log::debug('[RAG] (NO $) No vector matches, using keyword fallback');

            $chunks = $this->retrieveByKeywords($tenant, $query, $limit);
        }

        Log::debug('[RAG] (NO $) Retrieved chunks', [
            'count' => count($chunks),
        ]);

        $this->cache->put($tenant, $query, $chunks);

        return $chunks;
    }

    /**
     * Simple keyword-based retrieval as fallback.
     *
     * @return array<int, string>
     */
    public function retrieveByKeywords(Tenant $tenant, string $query, int $limit = 5): array
    {
        $keywords = $this->extractKeywords($query);

        if ($keywords === []) {
            return [];
        }

        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $readyItemIds = KnowledgeItem::query()
            ->forTenant($tenant)
            ->where('status', KnowledgeItemStatus::Ready)
            ->select('id');

        return KnowledgeChunk::query()
            ->whereIn('knowledge_item_id', $readyItemIds)
            ->where(function ($q) use ($keywords, $operator): void {
                foreach ($keywords as $keyword) {
                    $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $keyword);
                    $q->orWhere('content', $operator, "%{$escaped}%");
                }
            })
            ->limit($limit)
            ->pluck('content')
            ->all();
    }

    /** @return array<int, string> */
    private function extractKeywords(string $text): array
    {
        $stopWords = ['a', 'an', 'the', 'is', 'are', 'was', 'were', 'what', 'when', 'where', 'who', 'why', 'how', 'do', 'does', 'did', 'can', 'could', 'would', 'should', 'will', 'i', 'you', 'he', 'she', 'it', 'we', 'they', 'my', 'your', 'his', 'her', 'its', 'our', 'their', 'this', 'that', 'these', 'those', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by'];

        $words = preg_split('/\W+/', strtolower($text));
        $words = array_filter($words, function (string $word) use ($stopWords): bool {
            return strlen($word) > 2 && ! in_array($word, $stopWords);
        });

        return array_values(array_unique($words));
    }
}
