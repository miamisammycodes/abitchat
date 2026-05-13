<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Usage;

use App\Models\Conversation;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\UsageRecord;
use App\Services\Usage\UsageTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class UsageTrackerTest extends TestCase
{
    private UsageTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tracker = app(UsageTracker::class);
        $this->tenant = Tenant::create([
            'name' => 'T', 'slug' => 't', 'status' => 'active',
        ]);
    }

    public function test_records_tokens_with_period_and_conversation_id(): void
    {
        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'visitor_id' => 'v1',
            'session_id' => 'sess-1',
            'started_at' => now(),
        ]);

        $this->tracker->recordTokens($this->tenant, $conversation, 30, 70, 100);

        $row = UsageRecord::where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(100, $row->quantity);
        $this->assertSame(now()->format('Y-m'), $row->period);
        $this->assertSame($conversation->id, $row->conversation_id);
    }

    public function test_record_tokens_falls_back_to_prompt_plus_completion_when_total_missing(): void
    {
        // Ollama quirk: totalTokens is 0 but prompt/completion are populated
        $this->tracker->recordTokens($this->tenant, null, 60, 40, 0);

        $row = UsageRecord::where('tenant_id', $this->tenant->id)->first();
        $this->assertSame(100, $row->quantity);
    }

    public function test_record_tokens_no_ops_when_all_counts_are_zero(): void
    {
        $this->tracker->recordTokens($this->tenant, null, 0, 0, 0);
        $this->assertSame(0, UsageRecord::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_usage_in_period_only_counts_matching_period(): void
    {
        UsageRecord::create([
            'tenant_id' => $this->tenant->id, 'type' => 'tokens',
            'quantity' => 500, 'period' => '2026-04',
        ]);
        UsageRecord::create([
            'tenant_id' => $this->tenant->id, 'type' => 'tokens',
            'quantity' => 200, 'period' => '2026-05',
        ]);

        $this->assertSame(500, $this->tracker->usageInPeriod($this->tenant, 'tokens', '2026-04'));
        $this->assertSame(200, $this->tracker->usageInPeriod($this->tenant, 'tokens', '2026-05'));
    }

    /**
     * Regression for the year-blind whereMonth bug: counting "this month"
     * across years used to bucket Jan-2026 conversations into Jan-2027.
     */
    public function test_conversation_count_is_year_aware(): void
    {
        Carbon::setTestNow('2027-01-15 10:00:00');
        Conversation::create([
            'tenant_id' => $this->tenant->id, 'visitor_id' => 'v',
            'session_id' => 's-now', 'started_at' => now(),
        ]);

        Carbon::setTestNow('2026-01-15 10:00:00');
        Conversation::create([
            'tenant_id' => $this->tenant->id, 'visitor_id' => 'v',
            'session_id' => 's-old', 'started_at' => now(),
        ]);

        Carbon::setTestNow('2027-01-20 10:00:00');
        $this->assertSame(
            1,
            $this->tracker->usageInPeriod($this->tenant, 'conversations', '2027-01'),
            'Only the 2027 conversation should count for 2027-01',
        );
        Carbon::setTestNow();
    }

    public function test_record_tokens_busts_monthly_usage_cache(): void
    {
        // Prime cache with 0
        $this->assertSame(0, $this->tracker->monthlyUsage($this->tenant)['tokens']);

        // Direct UsageRecord write would NOT bust the cache; tracker write must.
        $this->tracker->recordTokens($this->tenant, null, 25, 25, 50);

        $this->assertSame(50, $this->tracker->monthlyUsage($this->tenant)['tokens']);
    }

    public function test_limits_for_uses_plan_when_present(): void
    {
        $plan = Plan::create([
            'name' => 'Biz', 'slug' => 'biz', 'price' => 0,
            'conversations_limit' => 1000, 'leads_limit' => 500,
            'tokens_limit' => 2_000_000, 'knowledge_items_limit' => 100,
            'is_active' => true,
        ]);
        $this->tenant->update(['plan_id' => $plan->id, 'plan_expires_at' => now()->addMonth()]);

        $limits = $this->tracker->limitsFor($this->tenant->fresh());
        $this->assertSame(1000, $limits['conversations']);
        $this->assertSame(2_000_000, $limits['tokens']);
    }

    public function test_limits_for_falls_back_to_trial_config_when_no_plan(): void
    {
        config(['billing.trial_limits' => [
            'conversations' => 50, 'leads' => 25, 'tokens' => 50_000, 'knowledge_items' => 10,
        ]]);
        $limits = $this->tracker->limitsFor($this->tenant);
        $this->assertSame(50, $limits['conversations']);
        $this->assertSame(50_000, $limits['tokens']);
    }

    public function test_remaining_returns_null_only_for_minus_one_or_missing_limit(): void
    {
        config(['billing.trial_limits' => ['tokens' => 0, 'leads' => -1]]);
        $this->assertSame(0, $this->tracker->remaining($this->tenant, 'tokens'));
        $this->assertNull($this->tracker->remaining($this->tenant, 'leads'));
        $this->assertNull($this->tracker->remaining($this->tenant, 'knowledge_items'));
    }

    public function test_remaining_clamps_at_zero_when_over_limit(): void
    {
        config(['billing.trial_limits' => ['tokens' => 100]]);
        $this->tracker->recordTokens($this->tenant, null, 80, 80, 160);
        $this->assertSame(0, $this->tracker->remaining($this->tenant, 'tokens'));
    }

    public function test_count_by_period_includes_first_second_of_month(): void
    {
        Carbon::setTestNow('2026-05-01 00:00:00');
        Conversation::create([
            'tenant_id' => $this->tenant->id,
            'visitor_id' => 'edge-start',
            'session_id' => 'sess-edge-start',
            'started_at' => now(),
        ]);
        Carbon::setTestNow();

        $this->assertSame(
            1,
            $this->tracker->usageInPeriod($this->tenant, 'conversations', '2026-05'),
            'Conversations at the first second of the month must be included.'
        );
    }

    public function test_count_by_period_excludes_first_second_of_next_month(): void
    {
        Carbon::setTestNow('2026-06-01 00:00:00');
        Conversation::create([
            'tenant_id' => $this->tenant->id,
            'visitor_id' => 'edge-next',
            'session_id' => 'sess-edge-next',
            'started_at' => now(),
        ]);
        Carbon::setTestNow();

        $this->assertSame(
            0,
            $this->tracker->usageInPeriod($this->tenant, 'conversations', '2026-05'),
            'Conversations at 2026-06-01 00:00:00 must NOT be in the May 2026 bucket.'
        );
    }

    public function test_sargable_indexes_exist_on_conversations_and_leads(): void
    {
        $conversationIndexes = collect(\DB::select("PRAGMA index_list('conversations')"))
            ->pluck('name')
            ->all();
        $leadIndexes = collect(\DB::select("PRAGMA index_list('leads')"))
            ->pluck('name')
            ->all();

        $this->assertContains(
            'conversations_tenant_id_created_at_index',
            $conversationIndexes,
            'Sargable countByPeriod relies on (tenant_id, created_at) index on conversations.'
        );
        $this->assertContains(
            'leads_tenant_id_created_at_index',
            $leadIndexes,
            'Sargable countByPeriod relies on (tenant_id, created_at) index on leads.'
        );
    }

    public function test_monthly_usage_does_not_bleed_across_month_boundary(): void
    {
        Carbon::setTestNow('2026-05-31 23:59:30');
        $this->tracker->recordTokens($this->tenant, null, 50, 50, 100);
        $this->assertSame(100, $this->tracker->monthlyUsage($this->tenant)['tokens']);

        // Roll into June (30 s later, still inside the 60 s TTL — this is the
        // real production scenario M-NEW-5 describes). The May cache must NOT
        // be returned for June.
        Carbon::setTestNow('2026-06-01 00:00:00');
        $this->assertSame(
            0,
            $this->tracker->monthlyUsage($this->tenant)['tokens'],
            'June must start with zero usage; May cached value must not bleed across the period boundary.'
        );
        Carbon::setTestNow();
    }
}
