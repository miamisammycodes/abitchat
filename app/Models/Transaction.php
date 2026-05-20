<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\Billing\TransactionAlreadyProcessed;
use App\Exceptions\Billing\TransactionPlanInactive;
use App\Exceptions\Billing\TransactionRecordMissing;
use App\Exceptions\Billing\TransactionStatusNotAllowed;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Transaction extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'transaction_number',
        'reference_number',
        'dk_reference_no',
        'dk_rrn',
        'amount',
        'payment_method',
        'payment_date',
        'status',
        'notes',
        'admin_notes',
        'approved_by',
        'approved_at',
        'dk_status_last_checked_at',
        'dk_qr_image_base64',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
        'approved_at' => 'datetime',
        'dk_status_last_checked_at' => 'datetime',
    ];

    // QR image is ~16KB base64; keep it out of default serialization so list
    // payloads don't carry it. Callers that need it (DkBankQrController::show)
    // read the attribute directly and pass it as an explicit prop.
    protected $hidden = ['dk_qr_image_base64'];

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @param Builder<self> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', 'pending');
    }

    /** @param Builder<self> $query */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', 'approved');
    }

    /** @param Builder<self> $query */
    public function scopeRejected(Builder $query): void
    {
        $query->where('status', 'rejected');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'awaiting_payment' => 'sky',
            'pending' => 'amber',
            'approved' => 'emerald',
            'rejected' => 'red',
            default => 'gray',
        };
    }

    public function getPaymentMethodLabelAttribute(): string
    {
        return match ($this->payment_method) {
            'bank_transfer' => 'Bank Transfer',
            'upi' => 'UPI',
            'card' => 'Card',
            'cash' => 'Cash',
            'dk_qr' => 'DK Bank QR',
            default => $this->payment_method ?? 'Unknown',
        };
    }

    /**
     * Atomically approve this transaction and activate the associated plan
     * for the tenant. Used by both admin approve action (from 'pending')
     * and the DK QR auto-verify flow (from 'awaiting_payment').
     *
     * @param  list<string>  $allowedFromStatuses  e.g. ['pending'] for admin, ['awaiting_payment'] for auto
     */
    public function approveAndActivate(
        array $allowedFromStatuses,
        ?int $adminId = null,
        ?string $adminNotes = null,
    ): void {
        DB::transaction(function () use ($allowedFromStatuses, $adminId, $adminNotes) {
            $locked = self::with(['tenant', 'plan'])
                ->whereKey($this->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new TransactionRecordMissing("Transaction {$this->id} not found");
            }
            if (! in_array($locked->status, $allowedFromStatuses, true)) {
                if (in_array($locked->status, ['approved', 'rejected'], true)) {
                    throw new TransactionAlreadyProcessed("Transaction {$this->id} is {$locked->status}");
                }
                throw new TransactionStatusNotAllowed("Transaction {$this->id} status {$locked->status} not in allowed list");
            }
            if (! $locked->tenant || ! $locked->plan) {
                throw new TransactionRecordMissing("Tenant or plan missing for transaction {$this->id}");
            }
            if (! $locked->plan->is_active) {
                throw new TransactionPlanInactive("Plan {$locked->plan->id} is not active");
            }

            $locked->update([
                'status' => 'approved',
                'admin_notes' => $adminNotes,
                'approved_by' => $adminId,
                'approved_at' => now(),
            ]);

            $locked->tenant->extendPlan($locked->plan);

            $this->refresh();
        });
    }
}
