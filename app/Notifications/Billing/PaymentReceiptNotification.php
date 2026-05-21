<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Models\Transaction;
use App\Services\Billing\ReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class PaymentReceiptNotification extends Notification implements NotTenantAware, ShouldQueue
{
    use Queueable;

    public function __construct(public Transaction $transaction) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $tx = $this->transaction->loadMissing(['tenant', 'plan']);

        // transaction_number is nullable since the DK QR migration; fall back
        // to the DK reference no, or the row id as a last resort. Used in
        // subject, body, and attachment filename — keep them all in sync.
        $ref = $tx->transaction_number ?? $tx->dk_reference_no ?? "Transaction-{$tx->id}";

        $pdfBytes = app(ReceiptService::class)->generatePdf($tx);

        return (new MailMessage)
            ->subject("Payment receipt — {$ref}")
            ->greeting("Hi {$tx->tenant->name},")
            ->line("We've received your payment for the **{$tx->plan->name}** plan.")
            ->line('**Amount:** Nu. '.number_format((float) $tx->amount, 2))
            ->line("**Reference:** {$ref}")
            ->line('**Date:** '.$tx->approved_at?->format('M j, Y \a\t g:i A'))
            ->action('View transaction', url("/billing/transactions/{$tx->id}/receipt"))
            ->line('A copy of your receipt is attached.')
            ->line('Thanks for using AbitChat!')
            ->attachData(
                $pdfBytes,
                "receipt-{$ref}.pdf",
                ['mime' => 'application/pdf'],
            );
    }
}
