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
        Schema::table('messages', function (Blueprint $table) {
            $table->char('content_hash', 32)->nullable()->after('content');
        });

        // Backfill — use driver-specific MD5 syntax. SQLite has no native
        // md5; backfill on SQLite skips and relies on the model hook for
        // future writes (acceptable since SQLite is test-only).
        if (DB::connection()->getDriverName() !== 'sqlite' && DB::table('messages')->exists()) {
            DB::table('messages')->whereNull('content_hash')->update([
                'content_hash' => DB::raw('MD5(content)'),
            ]);
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['content_hash']);
            $table->dropColumn('content_hash');
        });
    }
};
