<?php

declare(strict_types=1);

namespace App\Services\Payment\DkBank;

use App\Exceptions\Billing\DkQrGenerationException;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Payment\DkBank\DTO\DkQrSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DkBankQrService
{
    public function __construct(private readonly DkBankClient $client) {}

    public function startQrSession(Tenant $tenant, Plan $plan): DkQrSession
    {
        return DB::transaction(function () use ($tenant, $plan) {
            // ULID is sortable (encodes timestamp) + globally unique, so we can
            // generate the reference up-front in a single insert. No 'TEMP'
            // placeholder + update dance — that pattern collides on the unique
            // index when two merchants click "Generate QR" simultaneously.
            // Support staff can still cross-reference by Transaction.id since
            // both are stored on the same row.
            $referenceNo = 'DKQR-'.strtoupper((string) Str::ulid());

            $transaction = Transaction::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'amount' => $plan->price,
                'payment_method' => 'dk_qr',
                'payment_date' => now(),
                'status' => 'awaiting_payment',
                'dk_reference_no' => $referenceNo,
            ]);

            $response = $this->client->postSigned('/v1/generate_qr', [
                'request_id' => $this->client->generateRequestId(),
                'currency' => 'BTN',
                'bene_account_number' => config('services.dk_bank.beneficiary_account'),
                'amount' => (float) $plan->price,
                'mcc_code' => config('services.dk_bank.mcc_code'),
                'remarks' => "Plan: {$plan->name}",
                'reference_no' => $transaction->dk_reference_no,
            ]);

            if (($response['response_code'] ?? null) !== '0000') {
                throw new DkQrGenerationException(
                    dkResponseCode: $response['response_code'] ?? 'unknown',
                    message: $response['response_description'] ?? 'QR generation failed',
                );
            }

            return new DkQrSession(
                transaction: $transaction,
                qrImageBase64: $response['response_data']['image'],
            );
        });
    }
}
