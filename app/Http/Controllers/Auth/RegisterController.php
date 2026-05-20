<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\CrawlMode;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Jobs\CrawlWebsiteJob;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
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
        return Inertia::render('Auth/Register', [
            'trialKnowledgeItemsLimit' => (int) config('billing.trial_limits.knowledge_items', 10),
        ]);
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        Log::debug('[Register] (NO $) Creating tenant', [
            'company' => $request->company_name,
            'has_website_url' => $request->filled('website_url'),
        ]);

        $tenant = DB::transaction(function () use ($request) {
            $tenant = Tenant::create([
                'name' => $request->company_name,
                'website_url' => $request->website_url,
                'auto_recrawl' => true,
                'trial_ends_at' => now()->addDays(14),
            ]);

            Log::debug('[Register] (NO $) Tenant created', [
                'tenant_id' => $tenant->id,
                'api_key' => substr($tenant->api_key, 0, 8).'...',
                'trial_ends_at' => $tenant->trial_ends_at->toDateString(),
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->password,
            ]);

            Log::debug('[Register] (NO $) Owner user created', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            UserRole::create([
                'user_id' => $user->id,
                'role' => Role::Owner,
                'tenant_id' => $tenant->id,
            ]);

            Log::debug('[Register] (NO $) Registered owner role', [
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
            ]);

            event(new Registered($user));
            Auth::login($user);

            return $tenant;
        });

        if ($tenant->website_url) {
            CrawlWebsiteJob::dispatch($tenant, CrawlMode::Initial);
        }

        return redirect()->route('dashboard');
    }
}
