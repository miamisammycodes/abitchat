<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\Billing\TransactionAlreadyProcessed;
use App\Exceptions\Billing\TransactionPlanInactive;
use App\Exceptions\Billing\TransactionRecordMissing;
use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Transaction::with(['tenant:id,name', 'plan:id,name']);

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Search by tenant name or transaction number
        if ($request->has('search') && $request->search) {
            $search = (string) $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('transaction_number', 'like', "%{$search}%")
                    ->orWhereHas('tenant', function ($tq) use ($search) {
                        $tq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // Sort
        $sortField = (string) $request->input('sort', 'created_at');
        $sortDirection = (string) $request->input('direction', 'desc');
        $allowedSorts = ['created_at', 'status', 'amount', 'transaction_number'];

        if (! in_array($sortField, $allowedSorts, true)) {
            $sortField = 'created_at';
        }
        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }

        $query->orderBy($sortField, $sortDirection);

        $transactions = $query->paginate(20)->withQueryString();

        // Counts for tabs
        $counts = [
            'all' => Transaction::count(),
            'pending' => Transaction::where('status', 'pending')->count(),
            'approved' => Transaction::where('status', 'approved')->count(),
            'rejected' => Transaction::where('status', 'rejected')->count(),
        ];

        return Inertia::render('Admin/Transactions/Index', [
            'transactions' => $transactions,
            'counts' => $counts,
            'filters' => [
                'status' => $request->input('status', 'all'),
                'search' => $request->input('search', ''),
                'sort' => $sortField,
                'direction' => $sortDirection,
            ],
        ]);
    }

    public function show(Transaction $transaction): Response
    {
        $transaction->load(['tenant', 'plan']);

        return Inertia::render('Admin/Transactions/Show', [
            'transaction' => $transaction,
        ]);
    }

    public function approve(Request $request, Transaction $transaction): RedirectResponse
    {
        $validated = $request->validate([
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        $admin = Auth::user();

        try {
            $transaction->approveAndActivate(
                allowedFromStatuses: ['pending'],
                adminId: $admin?->id,
                adminNotes: $validated['admin_notes'] ?? null,
            );
        } catch (TransactionAlreadyProcessed) {
            return back()->with('error', 'Transaction has already been processed.');
        } catch (TransactionPlanInactive) {
            return back()->with('error', 'Cannot approve transaction: the plan is no longer active.');
        } catch (TransactionRecordMissing) {
            Log::error('[Admin] Transaction approve hit missing tenant or plan', [
                'transaction_id' => $transaction->id,
            ]);

            return back()->with('error', 'Cannot approve transaction: referenced tenant or plan no longer exists.');
        }

        AdminActivityLog::tryLog('approve_transaction', $transaction, [
            'admin_notes' => $validated['admin_notes'] ?? null,
        ]);

        return back()->with('success', 'Transaction approved and plan activated.');
    }

    public function reject(Request $request, Transaction $transaction): RedirectResponse
    {
        $validated = $request->validate([
            'admin_notes' => 'required|string|max:1000',
        ]);

        $admin = Auth::user();

        try {
            DB::transaction(function () use ($transaction, $validated, $admin) {
                $locked = Transaction::whereKey($transaction->id)->lockForUpdate()->first();

                if (! $locked || $locked->status !== 'pending') {
                    throw new \RuntimeException('ALREADY_PROCESSED');
                }

                $locked->update([
                    'status' => 'rejected',
                    'admin_notes' => $validated['admin_notes'],
                    'approved_by' => $admin?->id,
                    'approved_at' => now(),
                ]);
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'ALREADY_PROCESSED') {
                return back()->with('error', 'Transaction has already been processed.');
            }
            throw $e;
        }

        AdminActivityLog::tryLog('reject_transaction', $transaction, [
            'admin_notes' => $validated['admin_notes'],
        ]);

        return back()->with('success', 'Transaction rejected.');
    }
}
