<?php

declare(strict_types=1);

namespace App\Services\Payment\DkBank;

use App\Exceptions\Billing\DkQrGenerationException;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Services\Payment\DkBank\DTO\DkQrSession;
use App\Services\Payment\DkBank\DTO\DkStatusResult;
use App\Services\Payment\DkBank\DTO\DkStatusState;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function checkDkIntraStatus(Transaction $transaction): DkStatusResult
    {
        $transaction->update(['dk_status_last_checked_at' => now()]);

        $candidates = [
            $transaction->created_at->toDateString(),
            $transaction->created_at->copy()->addDay()->toDateString(),
        ];

        foreach ($candidates as $date) {
            $response = $this->client->postSigned('/v1/intra-transaction/status', [
                'request_id' => $this->client->generateRequestId(),
                'reference_no' => $transaction->dk_reference_no,
                'transaction_date' => $date,
                'bene_account_number' => config('services.dk_bank.beneficiary_account'),
            ]);

            $result = $this->interpretStatusResponse($response, $transaction);
            if ($result->state !== DkStatusState::Pending) {
                return $result;
            }
        }

        return new DkStatusResult(state: DkStatusState::Pending);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function interpretStatusResponse(array $response, Transaction $transaction): DkStatusResult
    {
        if (($response['response_code'] ?? null) === '3001') {
            return new DkStatusResult(state: DkStatusState::Pending);
        }

        if (($response['response_code'] ?? null) !== '0000') {
            Log::warning('[DK QR] (IS $) Status check returned unexpected code', [
                'transaction_id' => $transaction->id,
                'response' => $response,
            ]);

            return new DkStatusResult(state: DkStatusState::Pending);
        }

        $data = $response['response_data'][0] ?? null;
        if ($data === null || ($data['status'] ?? null) !== '0') {
            return new DkStatusResult(state: DkStatusState::Pending);
        }

        $reportedAmount = (float) ($data['amount'] ?? 0);
        $expectedAmount = (float) $transaction->amount;
        if (abs($reportedAmount - $expectedAmount) > 0.01) {
            Log::warning('[DK QR] (NO $) Status amount mismatch', [
                'transaction_id' => $transaction->id,
                'expected' => $expectedAmount,
                'reported' => $reportedAmount,
            ]);

            return new DkStatusResult(state: DkStatusState::Failed, errorMessage: 'Amount mismatch');
        }

        $reportedCredit = (string) ($data['credit_account'] ?? '');
        $expectedCredit = (string) config('services.dk_bank.beneficiary_account');
        if ($reportedCredit !== $expectedCredit) {
            Log::warning('[DK QR] (NO $) Status credit_account mismatch', [
                'transaction_id' => $transaction->id,
                'expected' => $expectedCredit,
                'reported' => $reportedCredit,
            ]);

            return new DkStatusResult(state: DkStatusState::Failed, errorMessage: 'Credit account mismatch');
        }

        $transaction->approveAndActivate(allowedFromStatuses: ['awaiting_payment'], adminId: null);

        return new DkStatusResult(
            state: DkStatusState::Paid,
            matchedReferenceNo: $transaction->dk_reference_no,
            paidAt: isset($data['txn_ts']) ? Carbon::parse($data['txn_ts']) : null,
        );
    }
}
