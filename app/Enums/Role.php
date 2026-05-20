<?php

declare(strict_types=1);

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Owner = 'owner';
    case Manager = 'manager';
    case Agent = 'agent';

    /**
     * Tenant-hierarchy rank. SuperAdmin is orthogonal — DO NOT compare via rank.
     * Higher = more privileged within tenant.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Manager => 2,
            self::Agent => 1,
            self::SuperAdmin => 0,  // orthogonal — never compared via rank
        };
    }

    public function isPlatformLevel(): bool
    {
        return $this === self::SuperAdmin;
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Platform Admin',  // per D-02 specifics — UI label is "Platform Admin"
            self::Owner => 'Owner',
            self::Manager => 'Manager',
            self::Agent => 'Agent',
        };
    }
}
