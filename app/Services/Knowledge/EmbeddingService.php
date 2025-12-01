<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

class EmbeddingService
{
    /**
     * Generate embedding for a text chunk.
     *
     * For development, we'll use Ollama's embedding capability.
     * For production, you could switch to OpenAI or VoyageAI.
     */
    public function generate(string $text): ?string
    {
        if (empty(trim($text))) {
            return null;
        }

        Log::debug('[Embeddings] (IS $) Generating embedding', [
            'text_length' => strlen($text),
        ]);

        try {
            // Use Prism's embedding capability
            $response = Prism::embeddings()
                ->using(Provider::Ollama, 'nomic-embed-text')
                ->fromInput($text)
                ->asEmbeddings();

            // Get the embedding vector and encode as JSON for storage
            $embedding = $response->embeddings[0]->embedding ?? null;

            if ($embedding) {
                return json_encode($embedding);
            }

            return null;
        } catch (\Exception $e) {
            Log::warning('[Embeddings] Generation failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            // Fallback: generate a simple hash-based "embedding" for development
            // This won't provide semantic search but allows the system to function
            return $this->generateFallbackEmbedding($text);
        }
    }

    /**
     * Calculate cosine similarity between two embeddings.
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA == 0 || $normB == 0) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }

    /**
     * Find similar chunks based on embedding similarity.
     *
     * @return array<array{chunk_id: int, content: string, similarity: float}>
     */
    public function findSimilar(string $queryText, array $chunks, int $limit = 5): array
    {
        $queryEmbedding = $this->generate($queryText);

        if (! $queryEmbedding) {
            return [];
        }

        $queryVector = json_decode($queryEmbedding, true);

        $similarities = [];

        foreach ($chunks as $chunk) {
            if (empty($chunk['embedding'])) {
                continue;
            }

            $chunkVector = json_decode($chunk['embedding'], true);

            if (! is_array($chunkVector)) {
                continue;
            }

            $similarity = $this->cosineSimilarity($queryVector, $chunkVector);

            $similarities[] = [
                'chunk_id' => $chunk['id'],
                'content' => $chunk['content'],
                'similarity' => $similarity,
            ];
        }

        // Sort by similarity descending
        usort($similarities, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($similarities, 0, $limit);
    }

    /**
     * Generate a simple fallback embedding when the embedding service is unavailable.
     * This uses TF-IDF-like approach for basic keyword matching.
     */
    private function generateFallbackEmbedding(string $text): string
    {
        // Tokenize and normalize
        $words = preg_split('/\W+/', strtolower($text));
        $words = array_filter($words, fn ($w) => strlen($w) > 2);

        // Create a simple bag-of-words representation
        $wordCounts = array_count_values($words);

        // Hash to fixed dimension (128 dimensions for simplicity)
        $embedding = array_fill(0, 128, 0.0);

        foreach ($wordCounts as $word => $count) {
            $hash = crc32((string) $word) % 128;
            $embedding[$hash] += $count;
        }

        // Normalize
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $embedding)));
        if ($norm > 0) {
            $embedding = array_map(fn ($x) => $x / $norm, $embedding);
        }

        return json_encode($embedding);
    }
}
