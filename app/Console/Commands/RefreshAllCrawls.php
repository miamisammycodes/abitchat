<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
                foreach ($tenants as $tenant) {
                    if ($this->hasInFlightSession($tenant)) {
                        $skipped++;

                        continue;
                    }
                    CrawlWebsiteJob::dispatch($tenant, 'refresh');
                    $dispatched++;
                }
            });

        $this->info("Dispatched {$dispatched} crawl jobs ({$skipped} skipped — in-flight).");

        return self::SUCCESS;
    }

    private function hasInFlightSession(Tenant $tenant): bool
    {
        return CrawlSession::forTenant($tenant)
            ->whereIn('status', [
                CrawlSessionStatus::Queued->value,
                CrawlSessionStatus::Running->value,
            ])
            ->where('created_at', '>', now()->subHours(self::IN_FLIGHT_WINDOW_HOURS))
            ->exists();
    }
}
