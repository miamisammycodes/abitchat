<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;

final class CleanupAbandonedDkQrSessions extends Command
{
    protected $signature = 'dk:cleanup-abandoned-qr';

    protected $description = 'Mark DK QR sessions older than 24h with no payment as rejected';

    public function handle(): int
    {
        $count = Transaction::query()
            ->where('status', 'awaiting_payment')
            ->where('payment_method', 'dk_qr')
            ->where('created_at', '<', now()->subDay())
            ->update([
                'status' => 'rejected',
                'admin_notes' => 'auto-expired: no payment received within 24h',
            ]);

        $this->info("Cleaned up {$count} abandoned DK QR session(s).");

        return self::SUCCESS;
    }
}
