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
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('bot_type')->default('hybrid')->after('settings');
            $table->string('bot_tone')->default('friendly')->after('bot_type');
            $table->text('bot_custom_instructions')->nullable()->after('bot_tone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['bot_type', 'bot_tone', 'bot_custom_instructions']);
        });
    }
};
