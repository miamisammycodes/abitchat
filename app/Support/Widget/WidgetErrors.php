<?php

declare(strict_types=1);

namespace App\Support\Widget;

final class WidgetErrors
{
    public const SESSION_EXPIRED = 'session_expired';

    public const SESSION_TOKEN_REQUIRED = 'session_token_required';

    public const RATE_LIMITED = 'rate_limited';
}
