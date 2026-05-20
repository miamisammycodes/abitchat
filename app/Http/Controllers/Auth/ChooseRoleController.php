<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ChooseRoleController extends Controller
{
    /**
     * Show the role chooser page for dual-role users (super_admin + tenant role).
     * Single-role users are redirected directly to their canonical home.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $user->loadMissing('roles');

        $isSuperAdmin = $user->hasRole(Role::SuperAdmin);
        $hasTenantRole = $user->roles->contains(
            fn (UserRole $ur) => ! $ur->role->isPlatformLevel()
        );

        // Single-role users bypass the chooser
        if (! $isSuperAdmin || ! $hasTenantRole) {
            if ($isSuperAdmin) {
                return redirect()->route('admin.dashboard');
            }

            return redirect()->route('dashboard');
        }

        $tenantName = $user->tenant?->name;

        $availableContexts = [
            [
                'context' => 'admin',
                'label' => 'Platform Admin',
                'description' => 'Manage tenants, plans, and platform settings.',
            ],
            [
                'context' => 'tenant',
                'label' => $tenantName ?: 'your workspace',
                'description' => 'Continue to your workspace dashboard.',
            ],
        ];

        return Inertia::render('Auth/ChooseRole', [
            'availableContexts' => $availableContexts,
        ]);
    }

    /**
     * Process the role context selection and redirect to the appropriate dashboard.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'context' => ['required', 'string', 'in:admin,tenant'],
        ]);

        $user = $request->user();
        $user->loadMissing('roles');

        $context = $validated['context'];

        if ($context === 'admin') {
            if (! $user->hasRole(Role::SuperAdmin)) {
                abort(403, 'You do not hold a platform admin role.');
            }

            Log::debug('[Auth] (NO $) ChooseRole: selected admin context', [
                'user_id' => $user->id,
            ]);

            return redirect()->route('admin.dashboard');
        }

        // context === 'tenant'
        $hasTenantRole = $user->roles->contains(
            fn (UserRole $ur) => ! $ur->role->isPlatformLevel()
        );

        if (! $hasTenantRole) {
            abort(403, 'You do not hold a tenant role. Contact support.');
        }

        Log::debug('[Auth] (NO $) ChooseRole: selected tenant context', [
            'user_id' => $user->id,
        ]);

        return redirect()->route('dashboard');
    }
}
