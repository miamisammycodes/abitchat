<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Enums\Ability;
use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\UpdateWebsiteIndexingRequest;
use App\Jobs\CrawlWebsiteJob;
use App\Models\CrawlSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WebsiteIndexingController extends Controller
{
    private const MANUAL_COOLDOWN_MINUTES = 60;

    public function update(UpdateWebsiteIndexingRequest $request): RedirectResponse
    {
        $tenant = $this->getTenant($request);
        $tenant->update([
            'website_url' => $request->website_url,
            'auto_recrawl' => $request->boolean('auto_recrawl'),
        ]);

        return back()->with('success', 'Website indexing settings saved.');
    }

    public function recrawl(Request $request): RedirectResponse
    {
        $this->authorize(Ability::ManageTenantSettings->value);
        $tenant = $this->getTenant($request);

        if ($tenant->website_url === null) {
            return back()->withErrors(['website_url' => 'No website URL set.']);
        }

        // Cooldown check covers both started crawls (created_at window) AND queued/running
        // sessions whose worker hasn't picked them up yet (started_at would still be NULL).
        // Using created_at (always set on dispatch) closes the NULL-bypass loophole.
        $recent = CrawlSession::query()
            ->forTenant($tenant)
            ->where(function ($q): void {
                $q->where('created_at', '>', now()->subMinutes(self::MANUAL_COOLDOWN_MINUTES))
                    ->orWhereIn('status', [
                        CrawlSessionStatus::Queued,
                        CrawlSessionStatus::Running,
                    ]);
            })
            ->exists();

        if ($recent) {
            return back()->withErrors(['cooldown' => 'Please wait — your last crawl started less than an hour ago.']);
        }

        try {
            CrawlWebsiteJob::dispatch($tenant, CrawlMode::Manual);
        } catch (\Throwable $e) {
            \Log::error('[Crawl] (NO $) Queue dispatch failed', [
                'tenant_id' => $tenant->id,
                'exception' => $e->getMessage(),
            ]);

            return back()->withErrors(['queue' => 'Could not queue the re-crawl right now. Please try again in a moment.']);
        }

        return back()->with('success', 'Re-crawl queued.');
    }

    public function latestStatus(Request $request): JsonResponse
    {
        $session = CrawlSession::query()
            ->forTenant($this->getTenant($request))
            ->latest('id')
            ->first();

        if ($session === null) {
            return response()->json(['session' => null]);
        }

        return response()->json([
            'session' => [
                'id' => $session->id,
                'status' => $session->status->value,
                'mode' => $session->mode->value,
                'pages_indexed' => $session->pages_indexed,
                'pages_discovered' => $session->pages_discovered,
                'pages_skipped_budget' => $session->pages_skipped_budget,
                'error_message' => $session->error_message,
                'started_at' => $session->started_at?->toIso8601String(),
                'completed_at' => $session->completed_at?->toIso8601String(),
            ],
        ]);
    }
}
