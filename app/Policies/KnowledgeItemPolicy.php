<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\KnowledgeItem;
use App\Models\User;

class KnowledgeItemPolicy
{
    public function view(User $user, KnowledgeItem $item): bool
    {
        return $item->tenant_id === $user->tenant_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, KnowledgeItem $item): bool
    {
        return $item->tenant_id === $user->tenant_id;
    }

    public function delete(User $user, KnowledgeItem $item): bool
    {
        return $item->tenant_id === $user->tenant_id;
    }
}
