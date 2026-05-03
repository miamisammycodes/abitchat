<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class ForgotPasswordController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        Log::debug('[PasswordReset] (NO $) Reset requested', [
            'email' => $request->email,
        ]);

        // Always issue the same generic response regardless of whether the
        // email is registered. Branching on $status would let an attacker
        // enumerate valid accounts.
        Password::sendResetLink($request->only('email'));

        return back()->with('status', __('passwords.sent'));
    }
}
