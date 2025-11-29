<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UsageRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $admin = Auth::guard('admin')->user();

        // Basic stats
        $totalTenants = Tenant::count();
        $activeTenants = Tenant::where('status', 'active')->count();
        $totalUsers = User::count();
        $totalConversations = Conversation::count();
        $conversationsToday = Conversation::whereDate('created_at', today())->count();
        $totalLeads = Lead::count();
        $leadsThisWeek = Lead::where('created_at', '>=', now()->startOfWeek())->count();

        // Token usage
        $totalTokens = UsageRecord::where('type', 'tokens')->sum('quantity');
        $tokensThisMonth = UsageRecord::where('type', 'tokens')
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('quantity');

        // Revenue (from approved transactions)
        $totalRevenue = Transaction::where('status', 'approved')->sum('amount');
        $revenueThisMonth = Transaction::where('status', 'approved')
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('amount');

        // Pending transactions count
        $pendingTransactions = Transaction::where('status', 'pending')->count();

        // Recent activity
        $recentTenants = Tenant::latest()->limit(5)->get(['id', 'name', 'status', 'created_at']);
        $recentTransactions = Transaction::with(['tenant:id,name', 'plan:id,name'])
            ->latest()
            ->limit(5)
            ->get();

        // Top clients by conversations
        $topClients = Tenant::select('tenants.id', 'tenants.name')
            ->withCount('conversations')
            ->orderByDesc('conversations_count')
            ->limit(5)
            ->get();

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'tenants' => [
                    'total' => $totalTenants,
                    'active' => $activeTenants,
                ],
                'users' => $totalUsers,
                'conversations' => [
                    'total' => $totalConversations,
                    'today' => $conversationsToday,
                ],
                'leads' => [
                    'total' => $totalLeads,
                    'thisWeek' => $leadsThisWeek,
                ],
                'tokens' => [
                    'total' => $totalTokens,
                    'thisMonth' => $tokensThisMonth,
                ],
                'revenue' => [
                    'total' => $totalRevenue,
                    'thisMonth' => $revenueThisMonth,
                ],
                'pendingTransactions' => $pendingTransactions,
            ],
            'recentTenants' => $recentTenants,
            'recentTransactions' => $recentTransactions,
            'topClients' => $topClients,
        ]);
    }
}
