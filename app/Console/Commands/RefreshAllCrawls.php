<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Jobs\CrawlWebsiteJob;
use App\Models\CrawlSession;
use App\Models\Tenant;
use Illuminate\Console\Command;

class RefreshAllCrawls extends Command
{
    protected $signature = 'crawls:refresh-all';

    protected $description = 'Dispatch a CrawlWebsiteJob (mode=refresh) for every eligible tenant';

    private const IN_FLIGHT_WINDOW_HOURS = 6;

    public function handle(): int
    {
        $dispatched = 0;
        $skipped = 0;

        Tenant::query()
            ->where('status', 'active')
            ->whereNotNull('website_url')
            ->where('auto_recrawl', true)
            ->chunkById(100, function ($tenants) use (&$dispatched, &$skipped): void {
                $tenantIds = $tenants->pluck('id')->all();

                // Cross-tenant batch lookup: intentionally queries all tenants at once
                // to avoid N+1. This command is a fan-out scheduler — not tenant-scoped.
                // @phpstan-ignore-next-line tenancy.rawTenantId
                $inFlight = CrawlSession::query()
                    ->whereIn('tenant_id', $tenantIds)
                    ->whereIn('status', [
                        CrawlSessionStatus::Queued,
                        CrawlSessionStatus::Running,
                    ])
                    ->where('created_at', '>', now()->subHours(self::IN_FLIGHT_WINDOW_HOURS))
                    ->pluck('tenant_id')
                    ->all();

                $inFlightSet = array_fill_keys($inFlight, true);

                foreach ($tenants as $tenant) {
                    if (isset($inFlightSet[$tenant->id])) {
                        $skipped++;

                        continue;
                    }
                    CrawlWebsiteJob::dispatch($tenant, CrawlMode::Refresh);
                    $dispatched++;
                }
            });

        $this->info("Dispatched {$dispatched} crawl jobs ({$skipped} skipped — in-flight).");

        return self::SUCCESS;
    }
}
