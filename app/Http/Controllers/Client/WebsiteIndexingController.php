<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\UpdateWebsiteIndexingRequest;
use App\Jobs\CrawlWebsiteJob;
use App\Models\CrawlSession;
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

        return back()->with('status', 'Website indexing settings saved.');
    }

    public function recrawl(Request $request): RedirectResponse
    {
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

        CrawlWebsiteJob::dispatch($tenant, CrawlMode::Manual);

        return back()->with('status', 'Re-crawl queued.');
    }
}
