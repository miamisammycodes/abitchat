<?php

declare(strict_types=1);

namespace App\Enums;

enum CrawlSessionStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
}
