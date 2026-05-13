<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\Usage\UsageTracker;
use Illuminate\Database\Eloquent\Model;

trait BustsTenantUsageCache
{
    protected static function bootBustsTenantUsageCache(): void
    {
        static::created(function (Model $model) {
            app(UsageTracker::class)->forgetCacheForTenant((int) $model->tenant_id);
        });
    }
}
