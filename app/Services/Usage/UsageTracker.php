<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\UsageRecord;
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

    public function recordTokens(
        Tenant $tenant,
        ?Conversation $conversation,
        int $prompt,
        int $completion,
        ?int $total = null,
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
            self::TYPE_TOKENS => (int) UsageRecord::where('tenant_id', $tenant->id)
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
        $plan = $tenant->currentPlan;
        if ($plan && $tenant->hasPlan()) {
            return [
                self::TYPE_CONVERSATIONS => (int) $plan->conversations_limit,
                self::TYPE_LEADS => (int) $plan->leads_limit,
                self::TYPE_TOKENS => (int) $plan->tokens_limit,
                self::TYPE_KNOWLEDGE_ITEMS => (int) $plan->knowledge_items_limit,
            ];
        }

        return config('billing.trial_limits', []);
    }

    /**
     * Remaining quota for the current period.
     * Returns null if the type is unlimited (limit absent, zero, or negative).
     */
    public function remaining(Tenant $tenant, string $type): ?int
    {
        $limit = $this->limitsFor($tenant)[$type] ?? null;
        if ($limit === null || $limit <= 0) {
            return null;
        }
        $used = $this->monthlyUsage($tenant)[$type] ?? 0;
        return max(0, $limit - $used);
    }

    public static function currentPeriod(): string
    {
        return now()->format('Y-m');
    }

    /** @param HasMany<\Illuminate\Database\Eloquent\Model, Tenant> $relation */
    private function countByPeriod(HasMany $relation, string $period): int
    {
        [$year, $month] = explode('-', $period);
        return $relation
            ->whereYear('created_at', (int) $year)
            ->whereMonth('created_at', (int) $month)
            ->count();
    }

    private function forgetCache(Tenant $tenant): void
    {
        Cache::forget($this->cacheKey($tenant));
    }

    private function cacheKey(Tenant $tenant): string
    {
        return "tenant:{$tenant->id}:usage";
    }
}
