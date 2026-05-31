<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_sessions', function (Blueprint $table): void {
            $table->unsignedInteger('pages_skipped_no_content')->default(0)->after('pages_skipped_unchanged');
        });
    }

    public function down(): void
    {
        Schema::table('crawl_sessions', function (Blueprint $table): void {
            $table->dropColumn('pages_skipped_no_content');
        });
    }
};
