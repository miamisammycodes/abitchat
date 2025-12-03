<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    /**
     * Show billing overview with current plan and usage
     */
    public function index(Request $request): Response
    {
        $tenant = $this->getTenant($request);
        $tenant->load('currentPlan');

        return Inertia::render('Client/Billing/Index', [
            'tenant' => $tenant,
            'currentPlan' => $tenant->currentPlan,
            'usage' => $tenant->getUsageStats(),
            'planExpired' => $tenant->isPlanExpired(),
            'transactions' => $tenant->transactions()
                ->with('plan')
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }

    /**
     * Show available plans
     */
    public function plans(Request $request): Response
    {
        $tenant = $this->getTenant($request);

        return Inertia::render('Client/Billing/Plans', [
            'plans' => Plan::active()->ordered()->get(),
            'currentPlanId' => $tenant->plan_id,
        ]);
    }

    /**
     * Show payment submission form for a plan
     */
    public function subscribe(Request $request, Plan $plan): Response
    {
        $tenant = $this->getTenant($request);

        // Check if there's already a pending transaction for this plan
        $pendingTransaction = $tenant->transactions()
            ->where('plan_id', $plan->id)
            ->where('status', 'pending')
            ->first();

        return Inertia::render('Client/Billing/Subscribe', [
            'plan' => $plan,
            'pendingTransaction' => $pendingTransaction,
        ]);
    }

    /**
     * Submit payment details
     */
    public function submitPayment(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'transaction_number' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'required|in:bank_transfer,upi,card,cash,other',
            'payment_date' => 'required|date|before_or_equal:today',
            'notes' => 'nullable|string|max:1000',
        ]);

        $tenant = $this->getTenant($request);

        // Check for duplicate transaction number
        $exists = Transaction::where('transaction_number', $validated['transaction_number'])->exists();
        if ($exists) {
            return back()->withErrors([
                'transaction_number' => 'This transaction number has already been submitted.',
            ]);
        }

        Transaction::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'transaction_number' => $validated['transaction_number'],
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'payment_date' => $validated['payment_date'],
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
        ]);

        return redirect()
            ->route('client.billing.index')
            ->with('success', 'Payment submitted successfully! We will verify and activate your plan shortly.');
    }

    /**
     * Show transaction history
     */
    public function transactions(Request $request): Response
    {
        $tenant = $this->getTenant($request);

        return Inertia::render('Client/Billing/Transactions', [
            'transactions' => $tenant->transactions()
                ->with('plan')
                ->latest()
                ->paginate(20),
        ]);
    }
}
