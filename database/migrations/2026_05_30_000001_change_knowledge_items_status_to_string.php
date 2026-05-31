<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE knowledge_items ALTER COLUMN status TYPE varchar(32)');
            DB::statement('ALTER TABLE knowledge_items DROP CONSTRAINT IF EXISTS knowledge_items_status_check');

            return;
        }

        // SQLite (tests) + others: change() rebuilds the table from the Blueprint,
        // which defines a plain string with no enum CHECK constraint.
        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->string('status', 32)->default('pending')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE knowledge_items ADD CONSTRAINT knowledge_items_status_check CHECK (status IN ('pending','processing','ready','failed'))");

            return;
        }

        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->enum('status', ['pending', 'processing', 'ready', 'failed'])->default('pending')->change();
        });
    }
};
