<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
            $search = $request->search;
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

        // Sort
        $sortField = $request->input('sort', 'created_at');
        $sortDirection = $request->input('direction', 'desc');
        $allowedSorts = ['name', 'created_at', 'status', 'conversations_count', 'leads_count'];

        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortDirection);
        }

        $clients = $query->paginate(20)->withQueryString();

        return Inertia::render('Admin/Clients/Index', [
            'clients' => $clients,
            'plans' => Plan::active()->ordered()->get(['id', 'name']),
            'filters' => [
                'search' => $request->input('search', ''),
                'status' => $request->input('status', 'all'),
                'plan' => $request->input('plan', 'all'),
                'sort' => $sortField,
                'direction' => $sortDirection,
            ],
        ]);
    }

    public function show(Tenant $client): Response
    {
        $client->load(['currentPlan', 'users']);

        // Get usage stats
        $conversationsCount = Conversation::where('tenant_id', $client->id)->count();
        $conversationsThisMonth = Conversation::where('tenant_id', $client->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $leadsCount = Lead::where('tenant_id', $client->id)->count();
        $leadsThisMonth = Lead::where('tenant_id', $client->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $tokensUsed = UsageRecord::where('tenant_id', $client->id)
            ->where('type', 'tokens')
            ->sum('quantity');
        $tokensThisMonth = UsageRecord::where('tenant_id', $client->id)
            ->where('type', 'tokens')
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('quantity');

        // Recent transactions
        $transactions = Transaction::where('tenant_id', $client->id)
            ->with('plan')
            ->latest()
            ->limit(10)
            ->get();

        // Recent conversations
        $recentConversations = Conversation::where('tenant_id', $client->id)
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

    public function updateStatus(Request $request, Tenant $client): RedirectResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:active,inactive,suspended',
        ]);

        $client->update(['status' => $validated['status']]);

        return back()->with('success', "Client status updated to {$validated['status']}.");
    }

    public function updatePlan(Request $request, Tenant $client): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'expires_at' => 'nullable|date|after:today',
        ]);

        $client->update([
            'plan_id' => $validated['plan_id'],
            'plan_expires_at' => $validated['expires_at'] ?? now()->addMonth(),
        ]);

        $plan = Plan::find($validated['plan_id']);

        return back()->with('success', "Client plan updated to {$plan->name}.");
    }

    public function updateBotPersonality(Request $request, Tenant $client): RedirectResponse
    {
        $validated = $request->validate([
            'bot_type' => 'required|in:support,sales,information,hybrid',
            'bot_tone' => 'required|in:formal,friendly,casual',
            'bot_custom_instructions' => 'nullable|string|max:2000',
        ]);

        $client->update($validated);

        return back()->with('success', 'Bot personality updated successfully.');
    }
}
