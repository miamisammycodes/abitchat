<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\CrawlSessionStatus;
use App\Jobs\CrawlWebsiteJob;
use App\Models\CrawlSession;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RefreshAllCrawlsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_job_for_eligible_tenants(): void
    {
        Bus::fake();

        $eligible = Tenant::factory()->create([
            'status' => 'active',
            'website_url' => 'https://example.com',
            'auto_recrawl' => true,
        ]);
        Tenant::factory()->create([
            'status' => 'active',
            'website_url' => null,
            'auto_recrawl' => true,
        ]);
        Tenant::factory()->create([
            'status' => 'active',
            'website_url' => 'https://example.com',
            'auto_recrawl' => false,
        ]);
        Tenant::factory()->create([
            'status' => 'suspended',
            'website_url' => 'https://example.com',
            'auto_recrawl' => true,
        ]);

        $this->artisan('crawls:refresh-all')->assertExitCode(0);

        Bus::assertDispatched(CrawlWebsiteJob::class, 1);
        Bus::assertDispatched(CrawlWebsiteJob::class, function (CrawlWebsiteJob $job) use ($eligible) {
            return $job->tenant->id === $eligible->id && $job->mode === 'refresh';
        });
    }

    public function test_skips_tenant_with_recent_in_flight_session(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'website_url' => 'https://example.com',
            'auto_recrawl' => true,
        ]);
        CrawlSession::factory()->forTenant($tenant)->create([
            'status' => CrawlSessionStatus::Running,
            'created_at' => now()->subHours(2),
        ]);

        $this->artisan('crawls:refresh-all')->assertExitCode(0);

        Bus::assertNotDispatched(CrawlWebsiteJob::class);
    }

    public function test_dispatches_when_prior_session_is_stale(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'website_url' => 'https://example.com',
            'auto_recrawl' => true,
        ]);
        CrawlSession::factory()->forTenant($tenant)->create([
            'status' => CrawlSessionStatus::Running,
            'created_at' => now()->subHours(8),
        ]);

        $this->artisan('crawls:refresh-all')->assertExitCode(0);

        Bus::assertDispatched(CrawlWebsiteJob::class, 1);
    }
}
