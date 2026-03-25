<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function view(User $user, Lead $lead): bool
    {
        return $lead->tenant_id === $user->tenant_id;
    }

    public function update(User $user, Lead $lead): bool
    {
        return $lead->tenant_id === $user->tenant_id;
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $lead->tenant_id === $user->tenant_id;
    }
}
