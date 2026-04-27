<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetrievalService
{
    public function __construct(
        private EmbeddingService $embeddingService
    ) {}

    /**
     * Retrieve relevant knowledge chunks for a query using pgvector
     * cosine-distance search, falling back to keyword LIKE if no
     * embedding can be generated.
     *
     * @return array<string>
     */
    public function retrieve(Tenant $tenant, string $query, int $limit = 5): array
    {
        $version = Cache::get("knowledge_version:{$tenant->id}", 0);
        $cacheKey = "knowledge:{$tenant->id}:v{$version}:" . md5($query);

        return Cache::remember($cacheKey, 600, function () use ($tenant, $query, $limit) {
            Log::debug('[RAG] (NO $) Retrieving context', [
                'tenant_id' => $tenant->id,
                'query_length' => strlen($query),
            ]);

            $queryVector = $this->embeddingService->generate($query);

            if ($queryVector === null) {
                Log::debug('[RAG] (NO $) No query embedding, using keyword fallback');

                return $this->retrieveByKeywords($tenant, $query, $limit);
            }

            $rows = DB::table('knowledge_chunks as kc')
                ->join('knowledge_items as ki', 'ki.id', '=', 'kc.knowledge_item_id')
                ->where('ki.tenant_id', $tenant->id)
                ->where('ki.status', 'ready')
                ->whereNotNull('kc.embedding')
                ->orderByRaw('kc.embedding <=> ?::vector', [$queryVector])
                ->limit($limit)
                ->pluck('kc.content');

            if ($rows->isEmpty()) {
                Log::debug('[RAG] (NO $) No vector matches, using keyword fallback');

                return $this->retrieveByKeywords($tenant, $query, $limit);
            }

            Log::debug('[RAG] (NO $) Retrieved chunks', [
                'count' => $rows->count(),
            ]);

            return $rows->all();
        });
    }

    /**
     * Simple keyword-based retrieval as fallback.
     *
     * @return array<string>
     */
    public function retrieveByKeywords(Tenant $tenant, string $query, int $limit = 5): array
    {
        $keywords = $this->extractKeywords($query);

        if (empty($keywords)) {
            return [];
        }

        return DB::table('knowledge_chunks as kc')
            ->join('knowledge_items as ki', 'ki.id', '=', 'kc.knowledge_item_id')
            ->where('ki.tenant_id', $tenant->id)
            ->where('ki.status', 'ready')
            ->where(function ($q) use ($keywords) {
                foreach ($keywords as $keyword) {
                    $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $keyword);
                    $q->orWhere('kc.content', 'ilike', "%{$escaped}%");
                }
            })
            ->limit($limit)
            ->pluck('kc.content')
            ->all();
    }

    /** @return array<int, string> */
    private function extractKeywords(string $text): array
    {
        $stopWords = ['a', 'an', 'the', 'is', 'are', 'was', 'were', 'what', 'when', 'where', 'who', 'why', 'how', 'do', 'does', 'did', 'can', 'could', 'would', 'should', 'will', 'i', 'you', 'he', 'she', 'it', 'we', 'they', 'my', 'your', 'his', 'her', 'its', 'our', 'their', 'this', 'that', 'these', 'those', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by'];

        $words = preg_split('/\W+/', strtolower($text));
        $words = array_filter($words, function ($word) use ($stopWords) {
            return strlen($word) > 2 && ! in_array($word, $stopWords);
        });

        return array_values(array_unique($words));
    }
}
