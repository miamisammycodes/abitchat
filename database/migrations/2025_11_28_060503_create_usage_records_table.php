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
        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['tokens', 'messages', 'conversations', 'storage'])->default('tokens');
            $table->unsignedBigInteger('quantity')->default(0);
            $table->date('recorded_date');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'recorded_date']);
            $table->index(['tenant_id', 'recorded_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};
