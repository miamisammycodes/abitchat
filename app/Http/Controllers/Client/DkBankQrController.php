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

    public function start(Request $request, Plan $plan): RedirectResponse
    {
        abort_if(! $plan->is_active, 404);

        $tenant = $this->getTenant($request);

        try {
            $session = $this->service->startQrSession($tenant, $plan);
        } catch (DkQrGenerationException) {
            return redirect()
                ->route('client.billing.subscribe', $plan)
                ->with('error', 'Could not generate the DK QR right now. Please use the manual form below.');
        }

        return redirect()->route('client.billing.dk-qr.show', $session->transaction);
    }

    public function show(Request $request, Transaction $transaction): Response|RedirectResponse
    {
        $this->authorize('view', $transaction);

        if ($transaction->payment_method !== 'dk_qr' || $transaction->dk_qr_image_base64 === null) {
            return redirect()
                ->route('client.billing.index')
                ->with('error', 'That QR session is no longer available. Start a new one from the plans page.');
        }

        if ($transaction->isApproved()) {
            return redirect()
                ->route('client.billing.index')
                ->with('success', 'Payment confirmed — your plan is active.');
        }

        $transaction->load('plan');

        return Inertia::render('Client/Billing/DkQrSession', [
            'plan' => $transaction->plan,
            'transaction' => $transaction,
            'qrImageBase64' => $transaction->dk_qr_image_base64,
        ]);
    }

    public function status(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('view', $transaction);
        abort_unless(in_array($transaction->status, ['awaiting_payment', 'approved'], true), 410);

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
        $this->authorize('view', $transaction);
        abort_unless($transaction->status === 'awaiting_payment', 410);

        $validated = $request->validate([
            'rrn' => 'required|alpha_num|min:4|max:32',
        ]);

        $result = $this->service->verifyByRrn($transaction, $validated['rrn']);

        return response()->json([
            'state' => $result->state->value,
            'message' => $result->errorMessage,
        ]);
    }
}
