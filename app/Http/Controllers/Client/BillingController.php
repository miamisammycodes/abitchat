<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Billing\ReceiptService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        $tenant = Cache::remember("tenant:{$tenant->id}:with_plan", 300, function () use ($tenant) {
            $tenant->load('currentPlan');

            return $tenant;
        });

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
        abort_if(! $plan->is_active, 404);

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
        abort_if(! $plan->is_active, 404);

        $validated = $request->validate([
            'transaction_number' => 'required|string|max:255',
            'reference_number' => 'required|string|size:6|alpha_num',
            'amount' => "required|numeric|min:{$plan->price}",
            'payment_method' => 'required|in:bob,bnb,dpnb,bdbl,tbank,dk',
            'payment_date' => 'required|date|before_or_equal:today',
            'notes' => 'nullable|string|max:1000',
        ]);

        $tenant = $this->getTenant($request);

        try {
            Transaction::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'transaction_number' => $validated['transaction_number'],
                'reference_number' => strtoupper($validated['reference_number']),
                'amount' => $validated['amount'],
                'payment_method' => $validated['payment_method'],
                'payment_date' => $validated['payment_date'],
                'notes' => $validated['notes'] ?? null,
                'status' => 'pending',
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return back()->withErrors([
                'transaction_number' => 'This transaction number has already been submitted.',
            ]);
        }

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

    /**
     * Download receipt PDF for a transaction
     */
    public function downloadReceipt(Request $request, Transaction $transaction, ReceiptService $receiptService): HttpResponse
    {
        $this->authorize('view', $transaction);

        // Only allow downloading receipts for approved transactions
        if ($transaction->status !== 'approved') {
            abort(403, 'Receipt is only available for approved transactions.');
        }

        return $receiptService->downloadPdf($transaction);
    }

    /**
     * Activate free trial for a tenant
     */
    public function activateTrial(Request $request, Plan $plan): RedirectResponse
    {
        abort_if(! $plan->is_active, 404);

        if ($plan->price > 0) {
            return back()->with('error', 'This plan requires payment.');
        }

        $tenant = $this->getTenant($request);

        return DB::transaction(function () use ($tenant, $plan) {
            $locked = Tenant::whereKey($tenant->id)->lockForUpdate()->first();

            if ($locked->trial_activated_at !== null) {
                return back()->with('error', 'Your free trial has already been used.');
            }

            if ($locked->plan_id && ! $locked->isPlanExpired()) {
                return back()->with('error', 'You already have an active plan.');
            }

            $locked->update([
                'plan_id' => $plan->id,
                'plan_expires_at' => now()->addDays(14),
                'trial_activated_at' => now(),
            ]);

            return redirect()
                ->route('client.billing.index')
                ->with('success', 'Your 14-day free trial has been activated! Enjoy exploring all the features.');
        });
    }
}
