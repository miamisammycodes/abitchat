<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'billing_period',
        'conversations_limit',
        'messages_per_conversation',
        'knowledge_items_limit',
        'tokens_limit',
        'leads_limit',
        'features',
        'is_active',
        'is_contact_sales',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'conversations_limit' => 'integer',
        'messages_per_conversation' => 'integer',
        'knowledge_items_limit' => 'integer',
        'tokens_limit' => 'integer',
        'leads_limit' => 'integer',
        'features' => 'array',
        'is_active' => 'boolean',
        'is_contact_sales' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** @return HasMany<Tenant, $this> */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<self> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('price');
    }

    /**
     * Check if limit is unlimited (-1)
     */
    public function isUnlimited(string $field): bool
    {
        return $this->{$field} === -1;
    }

    /**
     * Get formatted price
     */
    public function getFormattedPriceAttribute(): string
    {
        if ($this->is_contact_sales) {
            return 'Contact Us';
        }

        if ($this->price == 0) {
            return 'Free';
        }

        return 'Nu. '.number_format((float) $this->price, 0).'/'.$this->billing_period;
    }
}
