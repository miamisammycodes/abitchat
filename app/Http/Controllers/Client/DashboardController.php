<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $tenant = $this->getTenant();

        $planLabel = match (true) {
            $tenant->hasPlan() => $tenant->currentPlan->name,
            $tenant->isOnTrial() => 'Trial',
            default => 'No plan',
        };

        return Inertia::render('Client/Dashboard', [
            'tenant' => [
                'name' => $tenant->name,
                'plan' => $planLabel,
                'api_key' => substr($tenant->api_key, 0, 8).'...',
            ],
            'stats' => [
                'conversations' => $tenant->conversations()->count(),
                'leads' => $tenant->leads()->count(),
                'knowledge_items' => $tenant->knowledgeItems()->count(),
            ],
        ]);
    }
}
