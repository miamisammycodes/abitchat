<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_item_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->binary('embedding')->nullable();
            $table->unsignedInteger('token_count')->default(0);
            $table->unsignedInteger('chunk_index')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['knowledge_item_id', 'chunk_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
