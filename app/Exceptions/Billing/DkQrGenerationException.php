<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

final class DkQrGenerationException extends RuntimeException
{
    public function __construct(public readonly string $dkResponseCode, string $message)
    {
        parent::__construct($message);
    }
}
