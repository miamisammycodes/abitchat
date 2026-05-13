<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
        });
    }
};
