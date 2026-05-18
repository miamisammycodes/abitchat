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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class DkBankQrService
{
    public function __construct(private readonly DkBankClient $client) {}

    public function startQrSession(Tenant $tenant, Plan $plan): DkQrSession
    {
        return DB::transaction(function () use ($tenant, $plan) {
            // ULID up-front so two concurrent "Generate QR" clicks don't race
            // on the unique dk_reference_no index (a TEMP-then-update pattern
            // would collide).
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

    public function verifyByRrn(Transaction $transaction, string $rrn): DkStatusResult
    {
        $rrn = strtoupper(trim($rrn));
        $this->markChecked($transaction);

        $response = $this->postIntraStatus($rrn, $transaction->created_at->toDateString());

        if (($response['response_code'] ?? null) === '3001') {
            return new DkStatusResult(
                state: DkStatusState::Failed,
                errorMessage: 'Reference number not found — double-check from your bank\'s receipt. If you paid within the last few minutes, wait 30 seconds and try again.',
            );
        }

        if (($response['response_code'] ?? null) !== '0000') {
            Log::warning('[DK QR] (IS $) verifyByRrn unexpected response code', [
                'transaction_id' => $transaction->id,
                'response' => $response,
            ]);

            return new DkStatusResult(state: DkStatusState::Failed, errorMessage: 'Verification failed — please try again');
        }

        $data = $response['response_data'][0] ?? null;
        if ($data === null || ($data['status'] ?? null) !== '0') {
            return new DkStatusResult(state: DkStatusState::Failed, errorMessage: 'Reference number not found');
        }

        // Recency guard — RRN's txn_ts must be after we generated the QR
        $txnTs = isset($data['txn_ts']) ? Carbon::parse($data['txn_ts']) : null;
        if ($txnTs === null || $txnTs->lt($transaction->created_at)) {
            Log::warning('[security] (NO $) dk_rrn replay attempt: txn_ts predates QR generation', [
                'transaction_id' => $transaction->id,
                'rrn' => $rrn,
                'txn_ts' => $txnTs?->toDateTimeString(),
                'qr_created_at' => $transaction->created_at->toDateTimeString(),
            ]);

            return new DkStatusResult(state: DkStatusState::Failed, errorMessage: 'Reference number is older than this QR session — likely a typo. Please re-check.');
        }

        if (abs((float) $data['amount'] - (float) $transaction->amount) > 0.01) {
            return new DkStatusResult(state: DkStatusState::Failed, errorMessage: 'Payment amount did not match this plan. Contact support.');
        }
        if ((string) $data['credit_account'] !== (string) config('services.dk_bank.beneficiary_account')) {
            return new DkStatusResult(state: DkStatusState::Failed, errorMessage: 'Payment was not credited to our account. Contact support.');
        }

        // Write the RRN and approve atomically — unique constraint catches replay race
        try {
            DB::transaction(function () use ($transaction, $rrn) {
                $transaction->update(['dk_rrn' => $rrn]);
                $transaction->approveAndActivate(allowedFromStatuses: ['awaiting_payment'], adminId: null);
            });
        } catch (UniqueConstraintViolationException) {
            Log::warning('[security] (NO $) dk_rrn replay attempt: unique constraint violation', [
                'transaction_id' => $transaction->id,
                'rrn' => $rrn,
            ]);

            return new DkStatusResult(
                state: DkStatusState::Failed,
                errorMessage: 'This reference number has already been used for another payment. Contact support if this is unexpected.',
            );
        }

        return new DkStatusResult(
            state: DkStatusState::Paid,
            matchedReferenceNo: $rrn,
            paidAt: $txnTs,
        );
    }

    public function checkDkIntraStatus(Transaction $transaction): DkStatusResult
    {
        $this->markChecked($transaction);

        // Second-date attempt only needed when the QR session straddled a UTC
        // midnight — DK's transaction_date param may strictly bind to the
        // payment day. Avoids a wasted signed HTTPS roundtrip on ~99% of polls.
        $sessionDate = $transaction->created_at->toDateString();
        $candidates = [$sessionDate];
        $today = now()->toDateString();
        if ($today !== $sessionDate) {
            $candidates[] = $today;
        }

        foreach ($candidates as $date) {
            $response = $this->postIntraStatus($transaction->dk_reference_no, $date);
            $result = $this->interpretStatusResponse($response, $transaction);
            if ($result->state !== DkStatusState::Pending) {
                return $result;
            }
        }

        return new DkStatusResult(state: DkStatusState::Pending);
    }

    /**
     * @return array<string, mixed>
     */
    private function postIntraStatus(string $referenceNo, string $transactionDate): array
    {
        return $this->client->postSigned('/v1/intra-transaction/status', [
            'request_id' => $this->client->generateRequestId(),
            'reference_no' => $referenceNo,
            'transaction_date' => $transactionDate,
            'bene_account_number' => config('services.dk_bank.beneficiary_account'),
        ]);
    }

    /**
     * Throttle telemetry writes — once per minute is enough for support queries.
     * Avoids ~40 DB writes per QR session at the 3s polling cadence.
     */
    private function markChecked(Transaction $transaction): void
    {
        $last = $transaction->dk_status_last_checked_at;
        if ($last === null || $last->lt(now()->subMinute())) {
            $transaction->update(['dk_status_last_checked_at' => now()]);
        }
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
