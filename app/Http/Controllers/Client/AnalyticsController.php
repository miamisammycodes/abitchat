<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function __construct(
        private AnalyticsService $analyticsService
    ) {}

    public function index(Request $request): Response
    {
        $tenant = $request->user()->tenant;
        $days = (int) $request->input('days', 30);

        return Inertia::render('Client/Analytics/Index', [
            'stats' => $this->analyticsService->getOverviewStats($tenant, $days),
            'conversationsOverTime' => $this->analyticsService->getConversationsOverTime($tenant, $days),
            'leadsOverTime' => $this->analyticsService->getLeadsOverTime($tenant, $days),
            'tokenUsageOverTime' => $this->analyticsService->getTokenUsageOverTime($tenant, $days),
            'leadScoreDistribution' => $this->analyticsService->getLeadScoreDistribution($tenant),
            'leadStatusDistribution' => $this->analyticsService->getLeadStatusDistribution($tenant),
            'conversationsByHour' => $this->analyticsService->getConversationsByHour($tenant, $days),
            'topQuestions' => $this->analyticsService->getTopQuestions($tenant),
            'recentActivity' => $this->analyticsService->getRecentActivity($tenant),
            'selectedDays' => $days,
        ]);
    }
}
