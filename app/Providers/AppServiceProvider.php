<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\RolePermissions;
use App\Enums\Ability;
use App\Models\Conversation;
use App\Models\KnowledgeItem;
use App\Models\Lead;
use App\Models\Transaction;
use App\Models\User;
use App\Policies\ConversationPolicy;
use App\Policies\KnowledgeItemPolicy;
use App\Policies\LeadPolicy;
use App\Policies\TransactionPolicy;
use App\Services\Crawler\RobotsTxtPolicy;
use App\Services\Widget\SessionTokenService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped binding ensures SiteCrawler and SitemapDiscoverer share the same
        // RobotsTxtPolicy instance (and its per-instance cache) within a single
        // request / queue job, without persisting state across jobs in long-running workers.
        $this->app->scoped(RobotsTxtPolicy::class);

        $this->app->singleton(SessionTokenService::class, function ($app) {
            $key = (string) config('app.key');
            if ($key === '') {
                throw new \RuntimeException('APP_KEY must be set to use widget session tokens');
            }

            return new SessionTokenService($key);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('widget', function (Request $request) {
            // CORS preflight isn't user-initiated and must not consume the user's quota.
            if ($request->isMethod('OPTIONS')) {
                return Limit::none();
            }

            $apiKey = (string) $request->input('api_key', '');
            $ip = (string) $request->ip();
            $key = $apiKey !== '' ? "{$apiKey}:{$ip}" : "no_api_key:{$ip}";

            return Limit::perMinute(20)->by($key);
        });

        RateLimiter::for('dk-rrn-verify', function (Request $request) {
            $transactionId = $request->route('transaction')?->id ?? 'unknown';
            $tenantId = $request->user()?->tenant_id ?? 'unknown';

            return [
                Limit::perHour(5)->by("dk-rrn:tx:{$transactionId}"),
                Limit::perHour(20)->by("dk-rrn:tenant:{$tenantId}"),
            ];
        });

        // Register all 13 ability Gates. Each closure delegates to the RolePermissions decision
        // engine so boot() stays thin. A super-grants-all catch-all is explicitly omitted: every
        // ability must have a defined Gate so the auth.user.can map (Plan 05) is complete.
        foreach (Ability::cases() as $ability) {
            Gate::define(
                $ability->value,
                fn (User $user): bool => RolePermissions::can($user, $ability, $user->tenant),
            );
        }

        // Explicit policy bindings (4 resource models).
        // Laravel auto-discovers by convention, but explicit registration is project convention
        // and required for Larastan visibility of policy return types.
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(Lead::class, LeadPolicy::class);
        Gate::policy(KnowledgeItem::class, KnowledgeItemPolicy::class);
        Gate::policy(Transaction::class, TransactionPolicy::class);
    }
}
