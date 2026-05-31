<?php

declare(strict_types=1);

namespace App\Enums;

enum KnowledgeItemStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
    case SkippedNoContent = 'skipped_no_content';
}
