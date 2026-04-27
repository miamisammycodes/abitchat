<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EMBEDDING_DIMENSIONS = 768; // nomic-embed-text

    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_item_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->unsignedInteger('token_count')->default(0);
            $table->unsignedInteger('chunk_index')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['knowledge_item_id', 'chunk_index']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $dimensions = self::EMBEDDING_DIMENSIONS;
            DB::statement("ALTER TABLE knowledge_chunks ADD COLUMN embedding vector({$dimensions})");

            // HNSW index for fast approximate nearest-neighbour search using cosine distance.
            DB::statement('CREATE INDEX knowledge_chunks_embedding_hnsw_idx ON knowledge_chunks USING hnsw (embedding vector_cosine_ops)');
        } else {
            // Non-pgsql connections (e.g. SQLite for tests) get a plain text
            // column so the schema is compatible. Vector search is unavailable.
            Schema::table('knowledge_chunks', function (Blueprint $table) {
                $table->text('embedding')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
