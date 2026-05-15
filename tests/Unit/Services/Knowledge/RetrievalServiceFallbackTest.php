<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Knowledge;

use App\Exceptions\EmbeddingGenerationException;
use App\Models\Tenant;
use App\Services\Knowledge\EmbeddingService;
use App\Services\Knowledge\RetrievalService;
use Mockery;
use Tests\TestCase;

class RetrievalServiceFallbackTest extends TestCase
{
    public function test_falls_back_to_keyword_when_embedding_throws(): void
    {
        $tenant = Tenant::create([
            'name' => 'Co',
            'slug' => 'co-'.uniqid(),
            'status' => 'active',
        ]);

        $embedder = Mockery::mock(EmbeddingService::class);
        $embedder->shouldReceive('generate')
            ->andThrow(new EmbeddingGenerationException('ollama down'));
        $this->app->instance(EmbeddingService::class, $embedder);

        $service = app(RetrievalService::class);
        $result = $service->retrieve($tenant, 'what services');

        $this->assertIsArray($result);
    }
}
