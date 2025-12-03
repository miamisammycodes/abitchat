<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    /**
     * Get the authenticated user as a User model.
     * Only use this in client routes where User is guaranteed.
     */
    protected function getUser(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

        return $user;
    }

    /**
     * Get the authenticated user's tenant.
     * Only use this in client routes where User is guaranteed.
     */
    protected function getTenant(?Request $request = null): Tenant
    {
        $user = $request?->user() ?? Auth::user();
        assert($user instanceof User);

        $tenant = $user->tenant;
        assert($tenant instanceof Tenant);

        return $tenant;
    }
}
