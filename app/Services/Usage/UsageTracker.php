<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Models\Conversation;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\UsageRecord;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class UsageTracker
{
    public const TYPE_TOKENS = 'tokens';

    public const TYPE_CONVERSATIONS = 'conversations';

    public const TYPE_LEADS = 'leads';

    public const TYPE_KNOWLEDGE_ITEMS = 'knowledge_items';

    public const TYPES = [
        self::TYPE_TOKENS,
        self::TYPE_CONVERSATIONS,
        self::TYPE_LEADS,
        self::TYPE_KNOWLEDGE_ITEMS,
    ];

    private const CACHE_TTL_SECONDS = 60;

    /**
     * @param  array<string, mixed>  $metadata  Optional tag for the record (e.g. ['source' => 'estimated_retry']).
     */
    public function recordTokens(
        Tenant $tenant,
        ?Conversation $conversation,
        int $prompt,
        int $completion,
        ?int $total = null,
        array $metadata = [],
    ): void {
        $total = $total !== null && $total > 0 ? $total : $prompt + $completion;
        if ($total <= 0) {
            return;
        }

        UsageRecord::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation?->id,
            'type' => self::TYPE_TOKENS,
            'quantity' => $total,
            'period' => self::currentPeriod(),
            'metadata' => $metadata !== [] ? $metadata : null,
        ]);

        $this->forgetCache($tenant);

        Log::debug('[Usage] (NO $) Tokens recorded', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation?->id,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total,
        ]);
    }

    public function usageInPeriod(Tenant $tenant, string $type, string $period): int
    {
        return match ($type) {
            self::TYPE_TOKENS => (int) UsageRecord::forTenant($tenant)
                ->where('type', self::TYPE_TOKENS)
                ->where('period', $period)
                ->sum('quantity'),
            self::TYPE_CONVERSATIONS => $this->countByPeriod($tenant->conversations(), $period),
            self::TYPE_LEADS => $this->countByPeriod($tenant->leads(), $period),
            self::TYPE_KNOWLEDGE_ITEMS => $tenant->knowledgeItems()->count(),
            default => 0,
        };
    }

    /** @return array<string, int> */
    public function monthlyUsage(Tenant $tenant): array
    {
        return Cache::remember(
            $this->cacheKey($tenant),
            self::CACHE_TTL_SECONDS,
            function () use ($tenant): array {
                $period = self::currentPeriod();
                $out = [];
                foreach (self::TYPES as $type) {
                    $out[$type] = $this->usageInPeriod($tenant, $type, $period);
                }

                return $out;
            },
        );
    }

    /** @return array<string, int> */
    public function limitsFor(Tenant $tenant): array
    {
        // Plan limits drive display whenever a plan is attached — even if expired
        // (the lifecycle gate, not the limit numbers, enforces the block).
        if ($tenant->plan_id !== null && $tenant->currentPlan) {
            return $this->planLimits($tenant->currentPlan);
        }

        // Legacy implicit-trial tenants keep the config trial limits.
        if ($tenant->trial_ends_at !== null) {
            return config('billing.trial_limits', []);
        }

        // Setup tenants preview the Free plan's limits.
        return $this->freePlanLimits();
    }

    /** @return array<string, int> */
    private function planLimits(Plan $plan): array
    {
        return [
            self::TYPE_CONVERSATIONS => (int) $plan->conversations_limit,
            self::TYPE_LEADS => (int) $plan->leads_limit,
            self::TYPE_TOKENS => (int) $plan->tokens_limit,
            self::TYPE_KNOWLEDGE_ITEMS => (int) $plan->knowledge_items_limit,
        ];
    }

    /**
     * Free-plan limits for Setup tenants. Cached because limitsFor() runs on
     * every client Inertia request and the Free plan is rarely-changing
     * reference data. Falls back to the configured trial limits if no Free
     * plan row exists.
     *
     * @return array<string, int>
     */
    private function freePlanLimits(): array
    {
        return Cache::remember('plan:free:limits', 300, function (): array {
            $free = Plan::query()->where('slug', 'free')->where('price', 0)->first();

            return $free ? $this->planLimits($free) : (array) config('billing.trial_limits', []);
        });
    }

    /**
     * Remaining quota for the current period.
     * Returns null only when the type is unlimited (limit absent or -1).
     * A limit of 0 means "block all" and returns 0.
     */
    public function remaining(Tenant $tenant, string $type): ?int
    {
        $limit = $this->limitsFor($tenant)[$type] ?? null;
        if ($limit === null || $limit === -1) {
            return null;
        }
        if ($limit === 0) {
            return 0;
        }
        $used = $this->monthlyUsage($tenant)[$type] ?? 0;

        return max(0, $limit - $used);
    }

    /**
     * May this tenant record more usage of the given type?
     *
     * Returns true when the type is unlimited / unknown OR when remaining > 0.
     * Returns false when remaining is finite AND ≤ 0. The `<= 0` check (vs a
     * strict `=== 0`) is defensive: today `remaining()` clamps to ≥ 0, but if
     * an over-consumed tenant somehow lands a negative remainder, the gate
     * must still block. Future grace periods / soft caps / overage allowances
     * would slot in here.
     */
    public function canRecordUsage(Tenant $tenant, string $type): bool
    {
        $remaining = $this->remaining($tenant, $type);

        return $remaining === null || $remaining > 0;
    }

    public static function currentPeriod(): string
    {
        return now()->format('Y-m');
    }

    /**
     * @template TModel of Model
     *
     * @param  HasMany<TModel, Tenant>  $relation
     */
    private function countByPeriod(HasMany $relation, string $period): int
    {
        [$year, $month] = explode('-', $period);
        $start = Carbon::create((int) $year, (int) $month, 1, 0, 0, 0);
        $end = $start->copy()->addMonth();

        return $relation
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->count();
    }

    public function forgetCache(Tenant $tenant): void
    {
        Cache::forget($this->cacheKey($tenant));
    }

    public function forgetCacheForTenant(int $tenantId): void
    {
        Cache::forget($this->cacheKeyForTenantId($tenantId));
    }

    private function cacheKey(Tenant $tenant): string
    {
        return $this->cacheKeyForTenantId($tenant->id);
    }

    private function cacheKeyForTenantId(int $tenantId): string
    {
        return "tenant:{$tenantId}:usage:".self::currentPeriod();
    }
}
