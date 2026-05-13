<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\Usage\UsageTracker;

trait BustsTenantUsageCache
{
    protected static function bootBustsTenantUsageCache(): void
    {
        static::created(function ($model) {
            app(UsageTracker::class)->forgetCacheForTenant($model->tenant_id);
        });
    }
}
