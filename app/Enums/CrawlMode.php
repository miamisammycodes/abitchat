<?php

declare(strict_types=1);

namespace App\Enums;

enum CrawlMode: string
{
    case Initial = 'initial';
    case Refresh = 'refresh';
    case Manual = 'manual';
}
