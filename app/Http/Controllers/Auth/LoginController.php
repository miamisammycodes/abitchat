<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'status' => session('status'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        Log::debug('[Auth] (NO $) Login attempt', [
            'email' => $request->email,
        ]);

        $request->authenticate();

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();

        // Pitfall 5: eager-load roles to avoid N+1
        $user->loadMissing('roles');

        $isSuperAdmin = $user->hasRole(Role::SuperAdmin);
        $hasTenantRole = $user->roles->contains(
            fn (UserRole $ur) => ! $ur->role->isPlatformLevel()
        );

        Log::debug('[Auth] (NO $) Role-aware redirect', [
            'user_id' => $user->id,
            'is_super_admin' => $isSuperAdmin,
            'has_tenant_role' => $hasTenantRole,
        ]);

        return match (true) {
            $isSuperAdmin && $hasTenantRole => redirect()->route('login.choose'),
            $isSuperAdmin => redirect()->intended(route('admin.dashboard')),
            $hasTenantRole => redirect()->intended(route('dashboard')),
            default => redirect('/')->with('error', 'No roles assigned. Contact support.'),
        };
    }

    public function destroy(Request $request): RedirectResponse
    {
        Log::debug('[Auth] (NO $) Logout', [
            'user_id' => Auth::id(),
        ]);

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
