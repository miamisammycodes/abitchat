<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Thrown when a crawl fetch target or redirect hop resolves to a non-public address. */
class BlockedAddressException extends RuntimeException {}
