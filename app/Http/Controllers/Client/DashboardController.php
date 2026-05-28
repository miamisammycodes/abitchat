<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Enums\Ability;
use App\Enums\TenantLifecycle;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Ability::ViewDashboard->value);
        $tenant = $this->getTenant();
        $tenant->loadMissing('currentPlan');
        $state = $tenant->lifecycleState();

        $planLabel = match ($state) {
            TenantLifecycle::Active => $tenant->currentPlan?->name ?? 'Active',
            TenantLifecycle::Expired => ($tenant->currentPlan?->name ?? 'Plan').' (expired)',
            TenantLifecycle::LegacyTrial => 'Trial',
            TenantLifecycle::Setup => 'Not started',
        };

        return Inertia::render('Client/Dashboard', [
            'tenant' => [
                'name' => $tenant->name,
                'plan' => $planLabel,
                'api_key' => $state->allowsWidget()
                    ? substr($tenant->api_key, 0, 8).'...'
                    : null,
            ],
            'stats' => [
                'conversations' => $tenant->conversations()->count(),
                'leads' => $tenant->leads()->count(),
                'knowledge_items' => $tenant->knowledgeItems()->count(),
            ],
        ]);
    }
}
