<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\UsageRecord;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    /**
     * Get overview stats for the dashboard
     *
     * @return array<string, int|float>
     */
    public function getOverviewStats(Tenant $tenant, int $days = 30): array
    {
        $startDate = now()->subDays($days)->startOfDay();

        $conversations = Conversation::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $startDate);

        $leads = Lead::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $startDate);

        $totalConversations = (clone $conversations)->count();
        $totalLeads = (clone $leads)->count();

        // Resolution rate (conversations without escalation)
        $resolvedConversations = (clone $conversations)
            ->where('status', '!=', 'escalated')
            ->count();
        $resolutionRate = $totalConversations > 0
            ? round(($resolvedConversations / $totalConversations) * 100, 1)
            : 0;

        // Lead capture rate
        $leadCaptureRate = $totalConversations > 0
            ? round(($totalLeads / $totalConversations) * 100, 1)
            : 0;

        // Token usage
        $tokenUsage = UsageRecord::where('tenant_id', $tenant->id)
            ->where('type', 'tokens')
            ->where('created_at', '>=', $startDate)
            ->sum('quantity');

        // Average messages per conversation
        $avgMessages = (clone $conversations)->withCount('messages')->get()->avg('messages_count') ?? 0;

        return [
            'total_conversations' => $totalConversations,
            'total_leads' => $totalLeads,
            'resolution_rate' => $resolutionRate,
            'lead_capture_rate' => $leadCaptureRate,
            'token_usage' => (int) $tokenUsage,
            'avg_messages_per_conversation' => round($avgMessages, 1),
        ];
    }

    /**
     * Get conversations over time
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConversationsOverTime(Tenant $tenant, int $days = 30): array
    {
        $startDate = now()->subDays($days)->startOfDay();

        $data = Conversation::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $result = [];
        for ($i = $days; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $result[] = [
                'date' => $date,
                'label' => Carbon::parse($date)->format('M d'),
                'count' => $data->get($date)?->count ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Get leads over time
     *
     * @return array<int, array<string, mixed>>
     */
    public function getLeadsOverTime(Tenant $tenant, int $days = 30): array
    {
        $startDate = now()->subDays($days)->startOfDay();

        $data = Lead::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $result = [];
        for ($i = $days; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $result[] = [
                'date' => $date,
                'label' => Carbon::parse($date)->format('M d'),
                'count' => $data->get($date)?->count ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Get token usage over time
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTokenUsageOverTime(Tenant $tenant, int $days = 30): array
    {
        $startDate = now()->subDays($days)->startOfDay();

        $data = UsageRecord::where('tenant_id', $tenant->id)
            ->where('type', 'tokens')
            ->where('created_at', '>=', $startDate)
            ->selectRaw('DATE(created_at) as date, SUM(quantity) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $result = [];
        for ($i = $days; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $result[] = [
                'date' => $date,
                'label' => Carbon::parse($date)->format('M d'),
                'total' => (int) ($data->get($date)?->total ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Get lead score distribution
     *
     * @return array<string, int>
     */
    public function getLeadScoreDistribution(Tenant $tenant): array
    {
        $leads = Lead::where('tenant_id', $tenant->id)->get();

        $distribution = [
            'hot' => $leads->where('score', '>=', 70)->count(),
            'warm' => $leads->whereBetween('score', [40, 69])->count(),
            'cold' => $leads->where('score', '<', 40)->count(),
        ];

        return $distribution;
    }

    /**
     * Get lead status distribution
     *
     * @return array<string, int>
     */
    public function getLeadStatusDistribution(Tenant $tenant): array
    {
        return Lead::where('tenant_id', $tenant->id)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    /**
     * Get top questions (most common user messages)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTopQuestions(Tenant $tenant, int $limit = 10): array
    {
        return Message::whereHas('conversation', fn ($q) => $q->where('tenant_id', $tenant->id))
            ->where('role', 'user')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('content, COUNT(*) as count')
            ->groupBy('content')
            ->orderByDesc('count')
            ->limit($limit)
            ->get()
            ->map(fn ($m) => [
                'question' => strlen($m->content) > 100 ? substr($m->content, 0, 100).'...' : $m->content,
                'count' => $m->count,
            ])
            ->toArray();
    }

    /**
     * Get conversations by hour (peak times)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConversationsByHour(Tenant $tenant, int $days = 30): array
    {
        $startDate = now()->subDays($days)->startOfDay();

        // Use strftime for SQLite compatibility (also works with MySQL if needed)
        $driver = DB::connection()->getDriverName();
        $hourExpression = $driver === 'sqlite'
            ? "CAST(strftime('%H', created_at) AS INTEGER)"
            : 'HOUR(created_at)';

        $data = Conversation::where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $startDate)
            ->selectRaw("{$hourExpression} as hour, COUNT(*) as count")
            ->groupBy('hour')
            ->orderBy('hour')
            ->pluck('count', 'hour')
            ->toArray();

        $result = [];
        for ($h = 0; $h < 24; $h++) {
            $result[] = [
                'hour' => $h,
                'label' => sprintf('%02d:00', $h),
                'count' => $data[$h] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Get recent activity feed
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentActivity(Tenant $tenant, int $limit = 10): array
    {
        $conversations = Conversation::where('tenant_id', $tenant->id)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($c) => [
                'type' => 'conversation',
                'id' => $c->id,
                'description' => 'New conversation started',
                'status' => $c->status,
                'created_at' => $c->created_at->toIso8601String(),
                'time_ago' => $c->created_at->diffForHumans(),
            ]);

        $leads = Lead::where('tenant_id', $tenant->id)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn ($l) => [
                'type' => 'lead',
                'id' => $l->id,
                'description' => "Lead captured: {$l->name}",
                'score' => $l->score,
                'created_at' => $l->created_at->toIso8601String(),
                'time_ago' => $l->created_at->diffForHumans(),
            ]);

        return $conversations->concat($leads)
            ->sortByDesc('created_at')
            ->take($limit)
            ->values()
            ->toArray();
    }
}
