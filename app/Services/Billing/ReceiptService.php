<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class ReceiptService
{
    public function generatePdf(Transaction $transaction): string
    {
        $transaction->load(['tenant', 'plan']);

        $pdf = Pdf::loadView('pdf.receipt', [
            'transaction' => $transaction,
        ]);

        return $pdf->output();
    }

    public function downloadPdf(Transaction $transaction): Response
    {
        $transaction->load(['tenant', 'plan']);

        $pdf = Pdf::loadView('pdf.receipt', [
            'transaction' => $transaction,
        ]);

        $filename = 'receipt-' . $transaction->transaction_number . '.pdf';

        return $pdf->download($filename);
    }

    public function streamPdf(Transaction $transaction): Response
    {
        $transaction->load(['tenant', 'plan']);

        $pdf = Pdf::loadView('pdf.receipt', [
            'transaction' => $transaction,
        ]);

        $filename = 'receipt-' . $transaction->transaction_number . '.pdf';

        return $pdf->stream($filename);
    }
}
