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
            // QR image (base64 PNG) persisted at create time so the wait-page
            // can be re-rendered on refresh or after re-auth without a fresh
            // signed call to DK's /v1/generate_qr.
            $table->text('dk_qr_image_base64')->nullable()->after('dk_status_last_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('dk_qr_image_base64');
        });
    }
};
