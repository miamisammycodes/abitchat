<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Exceptions\Billing\DkQrGenerationException;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Transaction;
use App\Services\Payment\DkBank\DkBankQrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DkBankQrController extends Controller
{
    public function __construct(private readonly DkBankQrService $service) {}

    public function start(Request $request, Plan $plan): Response|RedirectResponse
    {
        abort_if(! $plan->is_active, 404);

        $tenant = $this->getTenant($request);

        try {
            $session = $this->service->startQrSession($tenant, $plan);
        } catch (DkQrGenerationException $e) {
            return redirect()
                ->route('client.billing.subscribe', $plan)
                ->with('error', 'Could not generate the DK QR right now. Please use the manual form below.')
                ->with('dk_failed', true);
        }

        return Inertia::render('Client/Billing/DkQrSession', [
            'plan' => $plan,
            'transaction' => $session->transaction,
            'qrImageBase64' => $session->qrImageBase64,
        ]);
    }

    public function status(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeOwnership($request, $transaction);
        abort_if($transaction->status !== 'awaiting_payment' && $transaction->status !== 'approved', 410);

        if ($transaction->status === 'approved') {
            return response()->json(['state' => 'paid']);
        }

        $result = $this->service->checkDkIntraStatus($transaction);

        return response()->json([
            'state' => $result->state->value,
            'message' => $result->errorMessage,
        ]);
    }

    public function verifyRrn(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeOwnership($request, $transaction);
        abort_if($transaction->status !== 'awaiting_payment', 410);

        $validated = $request->validate([
            'rrn' => 'required|alpha_num|min:4|max:32',
        ]);

        $result = $this->service->verifyByRrn($transaction, $validated['rrn']);

        return response()->json([
            'state' => $result->state->value,
            'message' => $result->errorMessage,
        ]);
    }

    private function authorizeOwnership(Request $request, Transaction $transaction): void
    {
        abort_if($transaction->tenant_id !== $this->getTenant($request)->id, 403);
    }
}
