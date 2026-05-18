<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->string('url_normalized', 768)->nullable()->after('source_url');
            $table->index(['tenant_id', 'type', 'url_normalized'], 'kn_items_tenant_type_norm_idx');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table): void {
            $table->dropIndex('kn_items_tenant_type_norm_idx');
            $table->dropColumn('url_normalized');
        });
    }
};
