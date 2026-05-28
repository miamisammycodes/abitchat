<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantLifecycle: string
{
    case Setup = 'setup';
    case Active = 'active';
    case Expired = 'expired';
    case LegacyTrial = 'legacy_trial';

    /** Widget API + api_key reveal require a live plan. */
    public function allowsWidget(): bool
    {
        return $this === self::Active || $this === self::LegacyTrial;
    }

    /** Knowledge-base writes are allowed everywhere except Expired (view-only). */
    public function allowsKnowledgeWrites(): bool
    {
        return $this !== self::Expired;
    }
}
