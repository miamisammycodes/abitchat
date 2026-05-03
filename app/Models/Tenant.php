<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
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

        static::saved(function (Tenant $tenant) {
            Cache::forget("tenant:api_key:{$tenant->api_key}");
            Cache::forget("tenant:{$tenant->id}:with_plan");
        });
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Conversation, $this> */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** @return HasMany<KnowledgeItem, $this> */
    public function knowledgeItems(): HasMany
    {
        return $this->hasMany(KnowledgeItem::class);
    }

    /** @return HasMany<UsageRecord, $this> */
    public function usageRecords(): HasMany
    {
        return $this->hasMany(UsageRecord::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function currentPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /** @return HasMany<Transaction, $this> */
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
     * Bundled current-month usage + plan/trial limits, used by the Inertia
     * layout and the billing page. All accounting goes through UsageTracker.
     *
     * @return array<string, array{used: int, limit: int}>
     */
    public function getUsageStats(): array
    {
        /** @var \App\Services\Usage\UsageTracker $tracker */
        $tracker = app(\App\Services\Usage\UsageTracker::class);
        $usage = $tracker->monthlyUsage($this);
        $limits = $tracker->limitsFor($this);

        $out = [];
        foreach (\App\Services\Usage\UsageTracker::TYPES as $type) {
            $out[$type] = [
                'used' => (int) ($usage[$type] ?? 0),
                'limit' => (int) ($limits[$type] ?? 0),
            ];
        }
        return $out;
    }
}
