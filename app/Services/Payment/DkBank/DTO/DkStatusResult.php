<?php

declare(strict_types=1);

namespace App\Services\Payment\DkBank\DTO;

use Carbon\Carbon;

final readonly class DkStatusResult
{
    public function __construct(
        public DkStatusState $state,
        public ?string $matchedReferenceNo = null,
        public ?Carbon $paidAt = null,
        public ?string $errorMessage = null,
    ) {}
}
