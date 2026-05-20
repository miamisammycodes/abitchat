<?php

declare(strict_types=1);

namespace App\Enums\Widget;

enum WidgetAuditEvent: string
{
    case Init = 'widget_init';
    case Request = 'widget_request';
    case Rejected = 'widget_token_rejected';
}
