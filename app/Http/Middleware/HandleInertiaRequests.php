<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Ability;
use App\Enums\Role;
use App\Enums\TenantLifecycle;
use App\Models\CrawlSession;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * Returns null in the testing env so tests do not need to compute and send
     * `X-Inertia-Version` headers. In all other envs, the manifest hash is used.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        if (app()->environment('testing')) {
            return null;
        }

        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        if ($user !== null) {
            // Eager-load roles once per request (N+1 protection — T-16.1.06a-03).
            // loadMissing ensures we don't double-query if roles were already loaded.
            $user->loadMissing('roles');
        }

        $tenant = $user?->tenant;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user !== null ? $this->buildAuthUser($user, $request) : null,
            ],
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ] : null,
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'usageWarnings' => fn () => $this->buildUsage($request, warningsOnly: true),
            'usageStats' => fn () => $this->buildUsage($request, warningsOnly: false),
            'dkBankEnabled' => (bool) config('services.dk_bank.enabled'),
            'latest_crawl_session' => fn () => $this->latestCrawlSession($request),
            'trialStatus' => fn () => $this->buildTrialStatus($request),
        ];
    }

    /**
     * Build the unified auth.user payload.
     *
     * Emits:
     *   - Standard user fields (id, name, email, tenant_id)
     *   - can: flat map of all 13 Ability slugs (snake_case) → bool
     *   - roles: array of role value strings assigned to this user
     *   - has_super_admin_role: bool
     *   - has_tenant_role: bool (any non-platform-level role row exists)
     *   - primary_role: {value, label} derived from URL context
     *
     * NOTE: The can map runs Gate::forUser() per request — intentionally not cached
     * to prevent stale permission leakage (T-16.1.06a-03 threat mitigation).
     *
     * @return array<string, mixed>
     */
    private function buildAuthUser(User $user, Request $request): array
    {
        $hasSuperAdminRole = $user->hasRole(Role::SuperAdmin);

        $hasTenantRole = $user->roles->contains(
            fn ($ur) => ! $ur->role->isPlatformLevel(),
        );

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'tenant_id' => $user->tenant_id,
            'can' => $this->buildAbilityMap($user),
            'roles' => $user->roles->map(fn ($ur) => $ur->role->value)->values()->all(),
            'has_super_admin_role' => $hasSuperAdminRole,
            'has_tenant_role' => $hasTenantRole,
            'primary_role' => $this->resolvePrimaryRole($user, $request, $hasSuperAdminRole),
        ];
    }

    /**
     * Build the flat ability map: snake_case slug → bool.
     *
     * Iterates every Ability case and evaluates the registered Gate for the user.
     * Result is NOT cached — must reflect current role state per request.
     *
     * @return array<string, bool>
     */
    private function buildAbilityMap(User $user): array
    {
        $can = [];

        foreach (Ability::cases() as $ability) {
            $snakeKey = str_replace('-', '_', $ability->value);
            $can[$snakeKey] = Gate::forUser($user)->check($ability->value);
        }

        return $can;
    }

    /**
     * Resolve the primary role for URL-context-aware display.
     *
     * Logic:
     * - On /admin/* routes AND user has SuperAdmin role → SuperAdmin is primary
     * - Otherwise → highest-rank tenant-scoped role (by Role::rank())
     * - If no tenant roles → null
     *
     * @return array{value: string, label: string}|null
     */
    private function resolvePrimaryRole(User $user, Request $request, bool $hasSuperAdminRole): ?array
    {
        $isAdminRoute = str_starts_with($request->path(), 'admin/') || $request->path() === 'admin';

        if ($isAdminRoute && $hasSuperAdminRole) {
            return [
                'value' => Role::SuperAdmin->value,
                'label' => Role::SuperAdmin->label(),
            ];
        }

        // Find highest-rank tenant-scoped role
        $bestRole = null;
        $bestRank = -1;

        foreach ($user->roles as $ur) {
            if ($ur->role->isPlatformLevel()) {
                continue;
            }

            if ($ur->role->rank() > $bestRank) {
                $bestRank = $ur->role->rank();
                $bestRole = $ur->role;
            }
        }

        if ($bestRole !== null) {
            return [
                'value' => $bestRole->value,
                'label' => $bestRole->label(),
            ];
        }

        // SuperAdmin with no tenant role on non-admin route
        if ($hasSuperAdminRole) {
            return [
                'value' => Role::SuperAdmin->value,
                'label' => Role::SuperAdmin->label(),
            ];
        }

        return null;
    }

    /**
     * Return the latest CrawlSession for the authenticated tenant's routes,
     * or null for non-eligible routes (public, admin, etc.).
     *
     * @return array<string, mixed>|null
     */
    private function latestCrawlSession(Request $request): ?array
    {
        $name = $request->route()?->getName() ?? '';
        $eligible = str_starts_with($name, 'dashboard')
            || str_starts_with($name, 'knowledge.')
            || str_starts_with($name, 'widget.');

        if (! $eligible) {
            return null;
        }

        $user = $request->user();
        if ($user === null || $user->tenant_id === null) {
            return null;
        }

        return once(function () use ($user) {
            $session = CrawlSession::query()
                ->forTenant($user->tenant)
                ->latest('id')
                ->first();

            if ($session === null) {
                return null;
            }

            return [
                'id' => $session->id,
                'status' => $session->status->value,
                'mode' => $session->mode->value,
                'pages_indexed' => $session->pages_indexed,
                'pages_discovered' => $session->pages_discovered,
                'pages_skipped_budget' => $session->pages_skipped_budget,
                'error_message' => $session->error_message,
                'started_at' => $session->started_at?->toIso8601String(),
                'completed_at' => $session->completed_at?->toIso8601String(),
            ];
        });
    }

    /**
     * Build per-metric usage info for the authenticated tenant.
     * When $warningsOnly is true, returns only metrics at >=90% (for the
     * banner). Otherwise returns every limited metric (for the always-visible
     * usage strip).
     *
     * @return array<int, array{type: string, label: string, used: int, limit: int, percent: int, severity: string}>
     */
    private function buildUsage(Request $request, bool $warningsOnly): array
    {
        $user = $request->user();
        $tenant = $user?->tenant;

        if (! $tenant) {
            return [];
        }

        $stats = $tenant->getUsageStats();

        $labels = [
            'conversations' => 'conversations',
            'knowledge_items' => 'knowledge items',
            'leads' => 'leads',
            'tokens' => 'tokens',
        ];

        $rows = [];
        foreach ($stats as $type => $stat) {
            $used = $stat['used'];
            $limit = $stat['limit'];

            if ($limit <= 0) {
                continue;
            }

            $percent = (int) min(100, round(($used / $limit) * 100));

            if ($warningsOnly && $percent < 90) {
                continue;
            }

            $severity = match (true) {
                $used >= $limit => 'critical',
                $percent >= 90 => 'warning',
                $percent >= 75 => 'caution',
                default => 'ok',
            };

            $rows[] = [
                'type' => $type,
                'label' => $labels[$type] ?? $type,
                'used' => $used,
                'limit' => $limit,
                'percent' => $percent,
                'severity' => $severity,
            ];
        }

        return $rows;
    }

    /**
     * Free-plan-only banner state. Null for Setup/LegacyTrial/paid tenants.
     *
     * @return array{state: string, days_remaining: int}|null
     */
    private function buildTrialStatus(Request $request): ?array
    {
        $tenant = $request->user()?->tenant;
        if (! $tenant) {
            return null;
        }

        return once(function () use ($tenant): ?array {
            $free = Plan::query()->free()->value('id');
            if ($free === null || $tenant->plan_id !== $free) {
                return null;
            }

            $state = $tenant->lifecycleState();
            if ($state === TenantLifecycle::Active) {
                // diffInDays returns a float; ceil so "expires in 5 days" doesn't
                // truncate to 4 a few microseconds after the row was written.
                return [
                    'state' => 'active',
                    'days_remaining' => (int) max(0, ceil(now()->diffInDays($tenant->plan_expires_at, false))),
                ];
            }
            if ($state === TenantLifecycle::Expired) {
                return ['state' => 'expired', 'days_remaining' => 0];
            }

            return null;
        });
    }
}
