<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('api_key_hash')->nullable()->after('api_key');
            $table->unique('api_key_hash');
        });

        // Backfill existing rows. Uses PHP (not raw SQL SHA2/CONCAT) to ensure
        // compatibility with SQLite (test env) and MySQL (production).
        Tenant::withTrashed()->chunkById(200, function ($tenants) {
            foreach ($tenants as $t) {
                if ($t->api_key) {
                    $t->timestamps = false;
                    $t->update(['api_key_hash' => hash('sha256', $t->api_key.config('app.key'))]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropUnique(['api_key_hash']);
            $table->dropColumn('api_key_hash');
        });
    }
};
