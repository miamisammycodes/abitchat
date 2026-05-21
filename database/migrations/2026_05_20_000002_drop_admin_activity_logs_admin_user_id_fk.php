<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_activity_logs', function (Blueprint $table) {
            $table->dropForeign(['admin_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('admin_activity_logs', function (Blueprint $table) {
            $table->foreign('admin_user_id')
                ->references('id')
                ->on('admin_users')
                ->cascadeOnDelete();
        });
    }
};
