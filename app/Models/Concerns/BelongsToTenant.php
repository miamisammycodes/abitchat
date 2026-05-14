<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Tenant-aware behaviour for any model with a `tenant_id` column.
 *
 * Provides:
 * - `tenant(): BelongsTo` — the canonical relation.
 * - `scopeForTenant(Tenant|int)` — the public query scope; use everywhere
 *   instead of raw `where('tenant_id', ...)`. The Larastan rule
 *   `App\Rules\PHPStan\NoRawTenantIdWhere` blocks raw form on new code.
 * - `creating` boot hook that auto-stamps `tenant_id` from `Auth::user()`
 *   when both: (a) the model has no `tenant_id` set yet, and (b) the
 *   authed user has a `tenant_id`. Admin/console/queue contexts have no
 *   authed user (admin uses a separate `AdminUser` guard) — the hook is
 *   a no-op there; callers must pass `tenant_id` explicitly.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $authUser = Auth::user();
            $authTenantId = $authUser?->getAttribute('tenant_id');

            if ($authTenantId === null) {
                return;
            }

            $model->setAttribute('tenant_id', $authTenantId);
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, Tenant|int $tenant): Builder
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return $query->where("{$this->getTable()}.tenant_id", $tenantId);
    }

    /** @return BelongsTo<Tenant, static> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
