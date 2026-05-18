<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Models\CrawlSession;
use App\Models\Tenant;
use App\Services\Crawler\SiteCrawler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class CrawlWebsiteJob implements NotTenantAware, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 300;

    public function __construct(
        public Tenant $tenant,
        public CrawlMode $mode = CrawlMode::Initial,
    ) {
        $this->onQueue('crawls');
    }

    public function handle(SiteCrawler $crawler): void
    {
        // On retry, mark any in-flight session for this tenant as Failed
        // before starting a fresh session — see spec retry semantics.
        // Queued sessions get cleaned up too in case the previous dispatch
        // never reached the worker (worker crash, OOM kill, restart).
        CrawlSession::forTenant($this->tenant)
            ->whereIn('status', [
                CrawlSessionStatus::Queued,
                CrawlSessionStatus::Running,
            ])
            ->update([
                'status' => CrawlSessionStatus::Failed->value,
                'error_message' => 'Superseded by retry',
                'completed_at' => now(),
            ]);

        $session = CrawlSession::create([
            'tenant_id' => $this->tenant->id,
            'mode' => $this->mode,
            'status' => CrawlSessionStatus::Queued,
        ]);

        Log::debug('[CrawlWebsiteJob] (NO $) Starting crawl', [
            'tenant_id' => $this->tenant->id,
            'session_id' => $session->id,
            'mode' => $this->mode->value,
        ]);

        $crawler->crawl($this->tenant, $session);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[CrawlWebsiteJob] (NO $) Final failure', [
            'tenant_id' => $this->tenant->id,
            'error' => $exception->getMessage(),
        ]);

        $latest = CrawlSession::forTenant($this->tenant)
            ->whereIn('status', [
                CrawlSessionStatus::Queued,
                CrawlSessionStatus::Running,
            ])
            ->latest('id')
            ->first();

        $latest?->update([
            'status' => CrawlSessionStatus::Failed,
            'error_message' => $exception->getMessage(),
            'completed_at' => now(),
        ]);
    }
}
