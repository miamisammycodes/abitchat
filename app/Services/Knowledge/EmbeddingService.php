<?php

declare(strict_types=1);

namespace App\Services\Knowledge;

use App\Exceptions\EmbeddingGenerationException;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;

class EmbeddingService
{
    public const DIMENSIONS = 768;

    /**
     * Generate an embedding for the given text and return it as a pgvector
     * literal (e.g. "[0.1,0.2,...]"). Returns null for empty input. Throws
     * EmbeddingGenerationException on provider failure — callers decide
     * whether to surface (background jobs) or fall back (retrieval).
     */
    public function generate(string $text): ?string
    {
        if (empty(trim($text))) {
            return null;
        }

        $providerName = (string) config('services.embeddings.provider', 'ollama');
        $model = (string) config('services.embeddings.model', 'nomic-embed-text');
        $provider = $this->resolveProvider($providerName);

        Log::debug('[Embeddings] (IS $) Generating embedding', [
            'provider' => $providerName,
            'model' => $model,
            'text_length' => strlen($text),
        ]);

        try {
            $response = Prism::embeddings()
                ->using($provider, $model)
                ->fromInput($text)
                ->asEmbeddings();
        } catch (\Throwable $e) {
            Log::error('[Embeddings] Provider call failed', [
                'provider' => $providerName,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            throw new EmbeddingGenerationException(
                "Embedding provider {$providerName} failed: {$e->getMessage()}",
                previous: $e,
            );
        }

        $vector = $response->embeddings[0]->embedding ?? null;

        if (! is_array($vector) || $vector === []) {
            Log::error('[Embeddings] Provider returned empty vector', [
                'provider' => $providerName,
                'model' => $model,
            ]);
            throw new EmbeddingGenerationException(
                "Embedding provider {$providerName} returned no vector",
            );
        }

        if (count($vector) !== self::DIMENSIONS) {
            Log::error('[Embeddings] Provider returned wrong-dimension vector', [
                'provider' => $providerName,
                'model' => $model,
                'expected_dimensions' => self::DIMENSIONS,
                'actual_dimensions' => count($vector),
            ]);
            throw new EmbeddingGenerationException(
                "Embedding provider {$providerName} returned ".count($vector)
                .'-dimension vector; expected '.self::DIMENSIONS,
            );
        }

        return self::toPgVector($vector);
    }

    /**
     * Format a numeric array as a pgvector literal: "[1.0,2.0,...]".
     *
     * @param  array<int, float|int>  $vector
     */
    public static function toPgVector(array $vector): string
    {
        return '['.implode(',', array_map(static fn ($v) => (float) $v, $vector)).']';
    }

    private function resolveProvider(string $name): Provider
    {
        $resolved = match (strtolower($name)) {
            'ollama' => Provider::Ollama,
            'openai' => Provider::OpenAI,
            'voyage', 'voyageai' => Provider::VoyageAI,
            'groq' => Provider::Groq,
            default => null,
        };

        if ($resolved === null) {
            Log::warning('[Embeddings] Unknown provider, falling back to Ollama', [
                'requested' => $name,
            ]);

            return Provider::Ollama;
        }

        return $resolved;
    }
}
