<?php

declare(strict_types=1);

namespace App\Policies;

use App\Auth\RolePermissions;
use App\Enums\Ability;
use App\Models\KnowledgeItem;
use App\Models\User;

class KnowledgeItemPolicy
{
    public function view(User $user, KnowledgeItem $item): bool
    {
        return $item->tenant_id === $user->tenant_id
            && RolePermissions::can($user, Ability::ManageKnowledgeBase, $user->tenant);
    }

    public function create(User $user): bool
    {
        // No resource arg — ownership enforced on insert via BelongsToTenant
        return RolePermissions::can($user, Ability::ManageKnowledgeBase, $user->tenant);
    }

    public function update(User $user, KnowledgeItem $item): bool
    {
        return $item->tenant_id === $user->tenant_id
            && RolePermissions::can($user, Ability::ManageKnowledgeBase, $user->tenant);
    }

    public function delete(User $user, KnowledgeItem $item): bool
    {
        return $item->tenant_id === $user->tenant_id
            && RolePermissions::can($user, Ability::ManageKnowledgeBase, $user->tenant);
    }
}
