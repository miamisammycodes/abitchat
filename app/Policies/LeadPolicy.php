<?php

declare(strict_types=1);

namespace App\Policies;

use App\Auth\RolePermissions;
use App\Enums\Ability;
use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function view(User $user, Lead $lead): bool
    {
        return $lead->tenant_id === $user->tenant_id
            && RolePermissions::can($user, Ability::ManageLeads, $user->tenant);
    }

    public function create(User $user): bool
    {
        // No resource arg — ownership enforced on insert via BelongsToTenant
        return RolePermissions::can($user, Ability::ManageLeads, $user->tenant);
    }

    public function update(User $user, Lead $lead): bool
    {
        return $lead->tenant_id === $user->tenant_id
            && RolePermissions::can($user, Ability::ManageLeads, $user->tenant);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $lead->tenant_id === $user->tenant_id
            && RolePermissions::can($user, Ability::ManageLeads, $user->tenant);
    }
}
