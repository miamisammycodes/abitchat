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
        Schema::table('tenants', function (Blueprint $table) {
            // NOTE: ->after() is a MySQL-ism (no-op on Postgres). Harmless on both.
            $table->timestamp('trial_expiring_notified_at')->nullable()->after('trial_activated_at');
            $table->timestamp('trial_expired_notified_at')->nullable()->after('trial_expiring_notified_at');
        });

        // Backfill so the FIRST scheduled run of trials:send-lifecycle-emails does
        // not email tenants whose Free plan already lapsed before this shipped
        // (they would receive a stale "your plan has ended" email). Only the
        // expired stamp needs backfilling — already-lapsed tenants can't match the
        // "expiring in ~3 days" (future) reminder window anyway.
        DB::table('tenants')
            ->whereIn('plan_id', function ($q) {
                $q->select('id')->from('plans')->where('slug', 'free')->where('price', 0);
            })
            ->whereNotNull('plan_expires_at')
            ->where('plan_expires_at', '<=', now())
            ->update(['trial_expired_notified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['trial_expiring_notified_at', 'trial_expired_notified_at']);
        });
    }
};
