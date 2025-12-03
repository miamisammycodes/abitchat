<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
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
            'embedUrl' => config('app.url').'/widget/chatbot.js',
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'welcome_message' => 'nullable|string|max:500',
            'primary_color' => 'nullable|string|max:7',
            'position' => 'nullable|in:bottom-right,bottom-left',
            'bot_name' => 'nullable|string|max:50',
            'offline_message' => 'nullable|string|max:500',
        ]);

        $tenant = $this->getTenant($request);

        $settings = $tenant->settings ?? [];
        $settings = array_merge($settings, $validated);

        $tenant->update(['settings' => $settings]);

        return back()->with('success', 'Widget settings updated successfully.');
    }

    public function regenerateApiKey(Request $request)
    {
        $tenant = $this->getTenant($request);
        $tenant->update([
            'api_key' => bin2hex(random_bytes(32)),
        ]);

        return back()->with('success', 'API key regenerated successfully.');
    }
}
