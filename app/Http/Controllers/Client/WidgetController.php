<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Enums\Ability;
use App\Http\Controllers\Controller;
use App\Models\CrawlSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WidgetController extends Controller
{
    public function index(Request $request): Response
    {
        $tenant = $this->getTenant($request);

        return Inertia::render('Client/Widget/Index', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'api_key' => $tenant->api_key,
                'settings' => $tenant->settings ?? [],
            ],
            'embedUrl' => $request->getSchemeAndHttpHost().'/widget/chatbot.js',
            'apiUrl' => $request->getSchemeAndHttpHost(),
            'website_url' => $tenant->website_url,
            'auto_recrawl' => (bool) $tenant->auto_recrawl,
            'last_crawl_session' => CrawlSession::query()->forTenant($tenant)->latest('id')->first()?->only([
                'id', 'status', 'mode', 'pages_indexed', 'pages_discovered', 'started_at', 'completed_at',
            ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize(Ability::ManageTenantSettings->value);
        $validated = $request->validate([
            'welcome_message' => 'nullable|string|max:500',
            'primary_color' => 'nullable|string|max:7',
            'position' => 'nullable|in:bottom-right,bottom-left',
            'bot_name' => 'nullable|string|max:50',
            'offline_message' => 'nullable|string|max:500',
            'allowed_domains' => 'nullable|array|max:50',
            'allowed_domains.*' => 'string|max:253|regex:/^[a-z0-9.-]+$/i',
        ]);

        $tenant = $this->getTenant($request);

        $settings = $tenant->settings ?? [];
        /** @var array<int, string> $rawDomains */
        $rawDomains = $validated['allowed_domains'] ?? [];
        $domains = collect($rawDomains)
            ->map(fn (string $d) => strtolower(trim($d)))
            ->filter()
            ->values()
            ->all();
        unset($validated['allowed_domains']);

        $settings = array_merge($settings, $validated, ['allowed_domains' => $domains]);

        $tenant->update(['settings' => $settings]);

        return back()->with('success', 'Widget settings updated successfully.');
    }

    public function regenerateApiKey(Request $request): RedirectResponse
    {
        $this->authorize(Ability::ManageTenantSettings->value);
        // Cache invalidation is owned by Tenant::saved hook (CR-02 fix) —
        // the model layer evicts the old api_key-keyed cache slot uniformly
        // across all rotation paths. Per CLAUDE.md "no dual-system support."
        $tenant = $this->getTenant($request);

        $tenant->update([
            'api_key' => bin2hex(random_bytes(32)),
        ]);

        return back()->with('success', 'API key regenerated successfully.');
    }
}
