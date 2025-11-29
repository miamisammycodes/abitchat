<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
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

        $user = Auth::user();

        Log::debug('[Auth] (NO $) Login success', [
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
        ]);

        return redirect()->intended(route('dashboard'));
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
