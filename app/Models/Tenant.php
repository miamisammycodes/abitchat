<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Usage\UsageTracker;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Multitenancy\Models\Tenant as BaseTenant;

/**
 * @property string|null $api_key_hash SHA-256 HMAC of api_key using APP_KEY as pepper; maintained by model hooks.
 */
class Tenant extends BaseTenant
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Max length of bot_custom_instructions, applied both as a write-time
     * validation rule and as an injection-time truncation cap. Shared so
     * the two paths can't drift apart.
     */
    public const MAX_CUSTOM_INSTRUCTIONS_CHARS = 1000;

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'website_url',
        'auto_recrawl',
        'api_key',
        'api_key_hash',
        'plan',
        'plan_id',
        'plan_expires_at',
        'status',
        'settings',
        'trial_ends_at',
        'trial_activated_at',
        'bot_type',
        'bot_tone',
        'bot_custom_instructions',
    ];

    protected $casts = [
        'settings' => 'array',
        'trial_ends_at' => 'datetime',
        'plan_expires_at' => 'datetime',
        'trial_activated_at' => 'datetime',
        'auto_recrawl' => 'boolean',
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
            // Always compute hash from the final api_key (generated or provided).
            // Must run AFTER the api_key assignment above so the hash is correct.
            $tenant->api_key_hash = hash('sha256', $tenant->api_key.config('app.key'));
        });

        // Covers api_key rotation at any point after creation.
        static::saving(function (Tenant $tenant) {
            if ($tenant->isDirty('api_key') && $tenant->api_key) {
                $tenant->api_key_hash = hash('sha256', $tenant->api_key.config('app.key'));
            }
        });

        static::saved(function (Tenant $tenant) {
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

    public function extendPlan(Plan $plan): void
    {
        $months = $plan->billing_period === Plan::BILLING_YEARLY ? 12 : 1;

        $fresh = static::whereKey($this->id)->lockForUpdate()->firstOrFail();

        $base = $fresh->plan_expires_at && $fresh->plan_expires_at->isFuture()
            ? $fresh->plan_expires_at
            : now();

        $fresh->update([
            'plan_id' => $plan->id,
            'plan_expires_at' => $base->copy()->addMonths($months),
        ]);

        $this->refresh();
    }

    /**
     * Bundled current-month usage + plan/trial limits, used by the Inertia
     * layout and the billing page. All accounting goes through UsageTracker.
     *
     * @return array<string, array{used: int, limit: int}>
     */
    public function getUsageStats(): array
    {
        /** @var UsageTracker $tracker */
        $tracker = app(UsageTracker::class);
        $usage = $tracker->monthlyUsage($this);
        $limits = $tracker->limitsFor($this);

        $out = [];
        foreach (UsageTracker::TYPES as $type) {
            $out[$type] = [
                'used' => (int) ($usage[$type] ?? 0),
                'limit' => (int) ($limits[$type] ?? 0),
            ];
        }

        return $out;
    }
}
