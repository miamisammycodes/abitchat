<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\KnowledgeChunk;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

class RetrievalService
{
    public function __construct(
        private EmbeddingService $embeddingService
    ) {}

    /**
     * Retrieve relevant knowledge chunks for a query.
     *
     * @return array<string>
     */
    public function retrieve(Tenant $tenant, string $query, int $limit = 5): array
    {
        Log::debug('[RAG] (NO $) Retrieving context', [
            'tenant_id' => $tenant->id,
            'query_length' => strlen($query),
        ]);

        // Get all chunks for this tenant with embeddings
        $chunks = KnowledgeChunk::whereHas('knowledgeItem', function ($q) use ($tenant) {
            $q->where('tenant_id', $tenant->id)
              ->where('status', 'ready');
        })
        ->whereNotNull('embedding')
        ->get()
        ->map(function ($chunk) {
            return [
                'id' => $chunk->id,
                'content' => $chunk->content,
                'embedding' => $chunk->embedding,
            ];
        })
        ->toArray();

        // If no chunks with embeddings, fall back to keyword search
        if (empty($chunks)) {
            Log::debug('[RAG] (NO $) No chunks with embeddings, using keyword fallback');
            return $this->retrieveByKeywords($tenant, $query, $limit);
        }

        // Find similar chunks
        $similar = $this->embeddingService->findSimilar($query, $chunks, $limit);

        Log::debug('[RAG] (NO $) Retrieved chunks', [
            'total_chunks' => count($chunks),
            'similar_found' => count($similar),
        ]);

        // Return just the content strings
        return array_map(fn($item) => $item['content'], $similar);
    }

    /**
     * Simple keyword-based retrieval as fallback.
     *
     * @return array<string>
     */
    public function retrieveByKeywords(Tenant $tenant, string $query, int $limit = 5): array
    {
        // Extract keywords from query
        $keywords = $this->extractKeywords($query);

        if (empty($keywords)) {
            return [];
        }

        $chunks = KnowledgeChunk::whereHas('knowledgeItem', function ($q) use ($tenant) {
            $q->where('tenant_id', $tenant->id)
              ->where('status', 'ready');
        })
        ->where(function ($q) use ($keywords) {
            foreach ($keywords as $keyword) {
                $q->orWhere('content', 'like', "%{$keyword}%");
            }
        })
        ->limit($limit)
        ->get();

        return $chunks->pluck('content')->toArray();
    }

    private function extractKeywords(string $text): array
    {
        // Remove common stop words and extract significant words
        $stopWords = ['a', 'an', 'the', 'is', 'are', 'was', 'were', 'what', 'when', 'where', 'who', 'why', 'how', 'do', 'does', 'did', 'can', 'could', 'would', 'should', 'will', 'i', 'you', 'he', 'she', 'it', 'we', 'they', 'my', 'your', 'his', 'her', 'its', 'our', 'their', 'this', 'that', 'these', 'those', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by'];

        $words = preg_split('/\W+/', strtolower($text));
        $words = array_filter($words, function ($word) use ($stopWords) {
            return strlen($word) > 2 && !in_array($word, $stopWords);
        });

        return array_values(array_unique($words));
    }
}
