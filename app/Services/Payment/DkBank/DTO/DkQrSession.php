<?php

declare(strict_types=1);

namespace App\Services\Payment\DkBank\DTO;

use App\Models\Transaction;

final readonly class DkQrSession
{
    public function __construct(
        public Transaction $transaction,
        public string $qrImageBase64,
    ) {}
}
