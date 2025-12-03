<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\LoginRequest;
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
        return Inertia::render('Admin/Auth/Login', [
            'status' => session('status'),
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        Log::debug('[AdminAuth] (NO $) Login attempt', [
            'email' => $request->email,
        ]);

        $request->authenticate();

        $request->session()->regenerate();

        $admin = Auth::guard('admin')->user();

        Log::debug('[AdminAuth] (NO $) Login success', [
            'admin_id' => $admin?->id,
            'role' => $admin?->role,
        ]);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Log::debug('[AdminAuth] (NO $) Logout', [
            'admin_id' => Auth::guard('admin')->id(),
        ]);

        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
