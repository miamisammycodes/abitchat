<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Tenant;
use App\Services\Widget\SessionTokenService;

/**
 * Provides Bearer-token authentication helpers for widget feature tests.
 *
 * Using this trait in setUp forces strict-mode (session_dual_accept=false) so
 * tests exercise the post-cutover code path, not the legacy fallthrough.
 */
trait AuthenticatesWidget
{
    protected function setUpAuthenticatesWidget(): void
    {
        config()->set('widget.session_dual_accept', false);
    }

    /**
     * Create a widget-enabled tenant with sensible defaults.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function createWidgetTenant(array $overrides = []): Tenant
    {
        return Tenant::create(array_merge([
            'name' => 'Acme',
            'slug' => 'acme',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => ['example.com']],
        ], $overrides));
    }

    /**
     * Mint a session token for the given tenant and return HTTP headers
     * suitable for passing to withHeaders() or postJson().
     *
     * Origin is fixed to https://example.com. The tenant's allowed_domains
     * must include 'example.com' — call ensureWidgetOriginAllowed() in setUp.
     *
     * @return array{Origin: string, Authorization: string}
     */
    protected function widgetHeaders(Tenant $tenant): array
    {
        /** @var SessionTokenService $service */
        $service = $this->app->make(SessionTokenService::class);
        $minted = $service->mint($tenant, 'https://example.com', '127.0.0.1');

        return [
            'Origin' => 'https://example.com',
            'Authorization' => "Bearer {$minted['token']}",
        ];
    }

    /**
     * Ensure the tenant allows requests from the helper's fixed origin
     * (example.com). Call this in setUp after creating the tenant.
     */
    protected function ensureWidgetOriginAllowed(Tenant $tenant): void
    {
        /** @var array<string, mixed> $settings */
        $settings = $tenant->settings ?? [];
        /** @var array<int, string> $allowed */
        $allowed = $settings['allowed_domains'] ?? [];

        if (! in_array('example.com', $allowed, true)) {
            $settings['allowed_domains'] = array_values(array_unique([...$allowed, 'example.com']));
            $tenant->update(['settings' => $settings]);
        }
    }
}
