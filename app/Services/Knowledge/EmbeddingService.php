<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

class EmbeddingService
{
    public const DIMENSIONS = 768;

    /**
     * Generate an embedding for the given text and return it as a pgvector
     * literal (e.g. "[0.1,0.2,...]"). Returns null on failure — callers
     * should fall back to keyword search rather than poison the cache with
     * a degraded embedding.
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
            $response = Prism::embeddings()
                ->using(Provider::Ollama, 'nomic-embed-text')
                ->fromInput($text)
                ->asEmbeddings();

            $vector = $response->embeddings[0]->embedding ?? null;

            if (!is_array($vector) || $vector === []) {
                return null;
            }

            return self::toPgVector($vector);
        } catch (\Throwable $e) {
            Log::warning('[Embeddings] Generation failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Format a numeric array as a pgvector literal: "[1.0,2.0,...]".
     *
     * @param array<int, float|int> $vector
     */
    public static function toPgVector(array $vector): string
    {
        return '[' . implode(',', array_map(static fn ($v) => (float) $v, $vector)) . ']';
    }
}
