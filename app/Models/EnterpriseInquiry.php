<?php

declare(strict_types=1);

namespace App\Models;

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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeContacted($query)
    {
        return $query->where('status', 'contacted');
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
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
