<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Multitenancy\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'api_key',
        'plan',
        'plan_id',
        'plan_expires_at',
        'status',
        'settings',
        'trial_ends_at',
        'bot_type',
        'bot_tone',
        'bot_custom_instructions',
    ];

    protected $casts = [
        'settings' => 'array',
        'trial_ends_at' => 'datetime',
        'plan_expires_at' => 'datetime',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            if (empty($tenant->api_key)) {
                $tenant->api_key = Str::random(64);
            }
            if (empty($tenant->slug)) {
                $tenant->slug = Str::slug($tenant->name);
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function knowledgeItems(): HasMany
    {
        return $this->hasMany(KnowledgeItem::class);
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    public function currentPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function isOnTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPlanExpired(): bool
    {
        return $this->plan_expires_at && $this->plan_expires_at->isPast();
    }

    public function hasPlan(): bool
    {
        return $this->plan_id !== null && ! $this->isPlanExpired();
    }

    /**
     * Check if tenant has reached a specific limit
     */
    public function hasReachedLimit(string $type): bool
    {
        $plan = $this->currentPlan;

        if (! $plan) {
            return true; // No plan = limit reached
        }

        $limitField = match ($type) {
            'conversations' => 'conversations_limit',
            'knowledge_items' => 'knowledge_items_limit',
            'leads' => 'leads_limit',
            'tokens' => 'tokens_limit',
            default => null,
        };

        if (! $limitField) {
            return false;
        }

        $limit = $plan->{$limitField};

        // -1 means unlimited
        if ($limit === -1) {
            return false;
        }

        $currentUsage = match ($type) {
            'conversations' => $this->conversations()->whereMonth('created_at', now()->month)->count(),
            'knowledge_items' => $this->knowledgeItems()->count(),
            'leads' => $this->leads()->whereMonth('created_at', now()->month)->count(),
            'tokens' => $this->usageRecords()->where('type', 'tokens')->whereMonth('created_at', now()->month)->sum('quantity'),
            default => 0,
        };

        return $currentUsage >= $limit;
    }

    /**
     * Get current usage stats
     */
    public function getUsageStats(): array
    {
        $plan = $this->currentPlan;

        return [
            'conversations' => [
                'used' => $this->conversations()->whereMonth('created_at', now()->month)->count(),
                'limit' => $plan?->conversations_limit ?? 0,
            ],
            'knowledge_items' => [
                'used' => $this->knowledgeItems()->count(),
                'limit' => $plan?->knowledge_items_limit ?? 0,
            ],
            'leads' => [
                'used' => $this->leads()->whereMonth('created_at', now()->month)->count(),
                'limit' => $plan?->leads_limit ?? 0,
            ],
            'tokens' => [
                'used' => (int) $this->usageRecords()->where('type', 'tokens')->whereMonth('created_at', now()->month)->sum('quantity'),
                'limit' => $plan?->tokens_limit ?? 0,
            ],
        ];
    }
}
