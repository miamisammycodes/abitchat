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
        Schema::table('usage_records', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'type', 'recorded_date']);
            $table->dropIndex(['tenant_id', 'recorded_date']);
            $table->dropIndex(['type', 'recorded_date']);
        });

        Schema::table('usage_records', function (Blueprint $table) {
            $table->char('period', 7)->nullable()->after('quantity');
            $table->foreignId('conversation_id')->nullable()->after('tenant_id')
                ->constrained()->nullOnDelete();
        });

        // Backfill existing rows. Skip on connections where no rows exist
        // (test/SQLite) — the column is about to become NOT NULL anyway.
        if (DB::table('usage_records')->exists()) {
            $expr = DB::connection()->getDriverName() === 'pgsql'
                ? "to_char(recorded_date, 'YYYY-MM')"
                : "strftime('%Y-%m', recorded_date)";
            DB::table('usage_records')
                ->whereNull('period')
                ->update(['period' => DB::raw($expr)]);
        }

        Schema::table('usage_records', function (Blueprint $table) {
            $table->char('period', 7)->nullable(false)->change();
            $table->dropColumn('recorded_date');

            $table->index(['tenant_id', 'type', 'period']);
            $table->index(['tenant_id', 'period']);
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::table('usage_records', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'type', 'period']);
            $table->dropIndex(['tenant_id', 'period']);
            $table->dropIndex(['conversation_id']);
            $table->dropForeign(['conversation_id']);
            $table->dropColumn(['period', 'conversation_id']);
            $table->date('recorded_date')->nullable();
        });

        if (DB::table('usage_records')->exists()) {
            $expr = DB::connection()->getDriverName() === 'pgsql'
                ? 'created_at::date'
                : "date(created_at)";
            DB::table('usage_records')
                ->whereNull('recorded_date')
                ->update(['recorded_date' => DB::raw($expr)]);
        }

        Schema::table('usage_records', function (Blueprint $table) {
            $table->date('recorded_date')->nullable(false)->change();
            $table->index(['tenant_id', 'type', 'recorded_date']);
            $table->index(['tenant_id', 'recorded_date']);
            $table->index(['type', 'recorded_date']);
        });
    }
};
