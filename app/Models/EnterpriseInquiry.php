<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EnterpriseInquiry extends Model
{
    use BelongsToTenant;

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
