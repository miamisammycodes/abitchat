<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        Log::debug('[Register] (NO $) Creating tenant', [
            'company' => $request->company_name,
        ]);

        $user = DB::transaction(function () use ($request) {
            $tenant = Tenant::create([
                'name' => $request->company_name,
            ]);

            Log::debug('[Register] (NO $) Tenant created', [
                'tenant_id' => $tenant->id,
                'api_key' => substr($tenant->api_key, 0, 8).'...',
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->password,
                'role' => 'owner',
            ]);

            Log::debug('[Register] (NO $) Owner user created', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('dashboard');
    }
}
