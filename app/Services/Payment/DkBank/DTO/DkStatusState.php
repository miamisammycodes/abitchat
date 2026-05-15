<?php

declare(strict_types=1);

namespace App\Services\Payment\DkBank\DTO;

enum DkStatusState: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Failed = 'failed';
}
