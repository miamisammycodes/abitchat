<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('dk_reference_no', 32)->nullable()->unique()->after('reference_number');
            $table->string('dk_rrn', 32)->nullable()->unique()->after('dk_reference_no');
            $table->timestamp('dk_status_last_checked_at')->nullable()->after('admin_notes');
        });

        // The original migration declared transaction_number as NOT NULL because
        // every transaction came from the manual-entry form. With DK QR, the row
        // is created BEFORE payment (status=awaiting_payment) — no bank-issued
        // number exists yet. Relax to nullable.
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('transaction_number')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Backfill any NULL transaction_numbers (created by DK QR flow) before
        // re-applying NOT NULL, otherwise the down migration would fail.
        \DB::table('transactions')
            ->whereNull('transaction_number')
            ->update(['transaction_number' => 'BACKFILL-'.\Illuminate\Support\Str::random(8)]);

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['dk_reference_no']);
            $table->dropUnique(['dk_rrn']);
            $table->dropColumn(['dk_reference_no', 'dk_rrn', 'dk_status_last_checked_at']);
            $table->string('transaction_number')->nullable(false)->change();
        });
    }
};
