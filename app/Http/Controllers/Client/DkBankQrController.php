<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Exceptions\Billing\DkQrGenerationException;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Payment\DkBank\DkBankQrService;
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
}
