<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
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
        $domains = collect($validated['allowed_domains'] ?? [])
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
        $tenant = $this->getTenant($request);
        $oldKey = $tenant->api_key;

        $tenant->update([
            'api_key' => bin2hex(random_bytes(32)),
        ]);

        Cache::forget("tenant:api_key:{$oldKey}");

        return back()->with('success', 'API key regenerated successfully.');
    }
}
