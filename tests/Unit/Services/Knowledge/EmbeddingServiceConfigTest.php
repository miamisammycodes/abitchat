<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Exceptions\EmbeddingGenerationException;
use App\Services\Knowledge\EmbeddingService;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\EmbeddingsResponseFake;
use Tests\TestCase;

class EmbeddingServiceConfigTest extends TestCase
{
    public function test_returns_null_for_empty_text(): void
    {
        $this->assertNull(app(EmbeddingService::class)->generate(''));
        $this->assertNull(app(EmbeddingService::class)->generate('   '));
    }

    public function test_throws_when_provider_returns_no_vector(): void
    {
        Prism::fake([
            EmbeddingsResponseFake::make()->withEmbeddings([]),
        ]);

        $this->expectException(EmbeddingGenerationException::class);
        app(EmbeddingService::class)->generate('hello world');
    }
}
