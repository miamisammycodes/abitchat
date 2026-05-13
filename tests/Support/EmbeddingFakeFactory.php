<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Knowledge\EmbeddingService;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Prism\Prism\ValueObjects\Embedding;

class EmbeddingFakeFactory
{
    /**
     * One fake embedding response carrying a single vector of the given
     * dimension, filled with the given value. Used to stub Prism in tests.
     */
    public static function single(int $dimensions = EmbeddingService::DIMENSIONS, float $value = 0.01): EmbeddingsResponseFake
    {
        return EmbeddingsResponseFake::make()
            ->withEmbeddings([Embedding::fromArray(array_fill(0, $dimensions, $value))]);
    }

    /**
     * N fake responses each with one vector of the given dimension — for
     * jobs that call the embedding service in a loop.
     *
     * @return array<int, EmbeddingsResponseFake>
     */
    public static function many(int $count, int $dimensions = EmbeddingService::DIMENSIONS, float $value = 0.01): array
    {
        if ($count <= 0) {
            return [];
        }

        return array_map(
            fn () => self::single($dimensions, $value),
            range(1, $count),
        );
    }
}
