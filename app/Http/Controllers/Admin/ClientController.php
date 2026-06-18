<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\UsageRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Tenant::with('currentPlan')
            ->withCount(['users', 'conversations', 'leads']);

        // Search
        if ($request->has('search') && $request->search) {
            $search = (string) $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by plan
        if ($request->has('plan') && $request->plan !== 'all') {
            $query->where('plan_id', $request->plan);
        }

        // Trashed filter — preserve default of active-only.
        $trashed = $request->input('trashed');
        if ($trashed === 'with') {
            $query->withTrashed();
        } elseif ($trashed === 'only') {
            $query->onlyTrashed();
        }

        // Sort
        $sortField = (string) $request->input('sort', 'created_at');
        $sortDirection = (string) $request->input('direction', 'desc');
        $allowedSorts = ['name', 'created_at', 'status', 'conversations_count', 'leads_count'];

        if (! in_array($sortField, $allowedSorts, true)) {
            $sortField = 'created_at';
        }
        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }

        $query->orderBy($sortField, $sortDirection);

        $clients = $query->paginate(20)->withQueryString();

        return Inertia::render('Admin/Clients/Index', [
            'clients' => $clients,
            'plans' => Plan::active()->ordered()->get(['id', 'name']),
            'filters' => [
                'search' => $request->input('search', ''),
                'status' => $request->input('status', 'all'),
                'plan' => $request->input('plan', 'all'),
                'trashed' => $request->input('trashed', ''),
                'sort' => $sortField,
                'direction' => $sortDirection,
            ],
        ]);
    }

    public function show(Tenant $client): Response
    {
        $client->load(['currentPlan', 'users']);

        // Get usage stats
        $conversationsCount = Conversation::forTenant($client)->count();
        $conversationsThisMonth = Conversation::forTenant($client)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $leadsCount = Lead::forTenant($client)->count();
        $leadsThisMonth = Lead::forTenant($client)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $tokensUsed = UsageRecord::forTenant($client)
            ->where('type', 'tokens')
            ->sum('quantity');
        $tokensThisMonth = UsageRecord::forTenant($client)
            ->where('type', 'tokens')
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('quantity');

        // Recent transactions
        $transactions = Transaction::forTenant($client)
            ->with('plan')
            ->latest()
            ->limit(10)
            ->get();

        // Recent conversations
        $recentConversations = Conversation::forTenant($client)
            ->withCount('messages')
            ->latest()
            ->limit(10)
            ->get();

        return Inertia::render('Admin/Clients/Show', [
            'client' => $client,
            'stats' => [
                'conversations' => [
                    'total' => $conversationsCount,
                    'thisMonth' => $conversationsThisMonth,
                ],
                'leads' => [
                    'total' => $leadsCount,
                    'thisMonth' => $leadsThisMonth,
                ],
                'tokens' => [
                    'total' => $tokensUsed,
                    'thisMonth' => $tokensThisMonth,
                ],
            ],
            'transactions' => $transactions,
            'recentConversations' => $recentConversations,
            'plans' => Plan::active()->ordered()->get(),
            'botTypes' => [
                ['value' => 'support', 'label' => 'Support Bot', 'description' => 'Focuses on answering questions and providing help. Does not push sales.'],
                ['value' => 'sales', 'label' => 'Sales Bot', 'description' => 'Proactively engages visitors, highlights benefits, and encourages conversions.'],
                ['value' => 'information', 'label' => 'Information Bot', 'description' => 'Provides neutral, factual responses without sales pressure.'],
                ['value' => 'hybrid', 'label' => 'Hybrid Bot', 'description' => 'Dynamically switches between support and sales based on conversation signals.'],
            ],
            'botTones' => [
                ['value' => 'formal', 'label' => 'Formal', 'description' => 'Professional language with respectful distance.'],
                ['value' => 'friendly', 'label' => 'Friendly', 'description' => 'Warm, conversational, and approachable.'],
                ['value' => 'casual', 'label' => 'Casual', 'description' => 'Very relaxed, peer-like communication style.'],
            ],
        ]);
    }

    public function restore(int $id): RedirectResponse
    {
        // Route uses {id} (not {client}) so the global Route::bind('client')
        // — which calls Tenant::findOrFail under the default soft-delete
        // scope — doesn't 404 on the row we need to restore.
        $tenant = Tenant::onlyTrashed()->findOrFail($id);
        $tenant->restore();

        AdminActivityLog::tryLog('restore_client', $tenant);

        return redirect()
            ->route('admin.clients.show', $tenant->id)
            ->with('success', 'Tenant restored.');
    }

    public function updateStatus(Request $request, Tenant $client): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:active,inactive,suspended',
        ]);

        $client->update(['status' => $validated['status']]);

        AdminActivityLog::tryLog('update_client_status', $client, ['status' => $validated['status']]);

        return back()->with('success', "Client status updated to {$validated['status']}.");
    }

    public function updatePlan(Request $request, Tenant $client): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'expires_at' => 'nullable|date|after:today',
        ]);

        /** @var Plan $plan */
        $plan = Plan::findOrFail($validated['plan_id']);

        if (! empty($validated['expires_at'])) {
            $client->update([
                'plan_id' => $plan->id,
                'plan_expires_at' => $validated['expires_at'],
            ]);
        } else {
            $client->extendPlan($plan);
        }

        AdminActivityLog::tryLog('update_client_plan', $client, [
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
        ]);

        return back()->with('success', "Client plan updated to {$plan->name}.");
    }

    public function updateBotPersonality(Request $request, Tenant $client): RedirectResponse
    {
        $validated = $request->validate([
            'bot_type' => 'required|in:support,sales,information,hybrid',
            'bot_tone' => 'required|in:formal,friendly,casual',
            'bot_custom_instructions' => 'nullable|string|max:'.Tenant::MAX_CUSTOM_INSTRUCTIONS_CHARS,
        ]);

        $client->update($validated);

        AdminActivityLog::tryLog('update_client_bot_personality', $client, [
            'bot_type' => $validated['bot_type'],
            'bot_tone' => $validated['bot_tone'],
        ]);

        return back()->with('success', 'Bot personality updated successfully.');
    }
}
