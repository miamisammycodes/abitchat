<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseInquiry extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'company',
        'message',
        'status',
        'admin_notes',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @param Builder<self> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }

    /** @param Builder<self> $query */
    public function scopeContacted(Builder $query): void
    {
        $query->where('status', 'contacted');
    }

    /** @param Builder<self> $query */
    public function scopeClosed(Builder $query): void
    {
        $query->where('status', 'closed');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isContacted(): bool
    {
        return $this->status === 'contacted';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }
}
