<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
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
        $admin = Auth::guard('admin')->user();
        $tenant = $request->user()?->tenant;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'admin' => $admin ? [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'role' => $admin->role,
                ] : null,
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
        ];
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

        if (!$tenant) {
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
            $used = (int) ($stat['used'] ?? 0);
            $limit = (int) ($stat['limit'] ?? 0);

            if ($limit <= 0) {
                continue; // unlimited (-1) or unset (0)
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
}
