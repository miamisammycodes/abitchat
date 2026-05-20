<?php

declare(strict_types=1);

namespace App\Policies;

use App\Auth\RolePermissions;
use App\Enums\Ability;
use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function view(User $user, Transaction $transaction): bool
    {
        return $transaction->tenant_id === $user->tenant_id
            && RolePermissions::can($user, Ability::ManageBilling, $user->tenant);
    }
}
