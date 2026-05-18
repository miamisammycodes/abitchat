<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Jobs\CrawlWebsiteJob;
use App\Models\CrawlSession;
use App\Models\Tenant;
use App\Services\Crawler\SiteCrawler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Tests\TestCase;

class CrawlWebsiteJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_implements_not_tenant_aware(): void
    {
        $this->assertInstanceOf(
            NotTenantAware::class,
            new CrawlWebsiteJob(Tenant::factory()->make(), 'initial'),
        );
    }

    public function test_creates_session_then_delegates_to_site_crawler(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);

        $crawler = Mockery::mock(SiteCrawler::class);
        $crawler->shouldReceive('crawl')
            ->once()
            ->with(
                Mockery::on(fn ($t) => $t->id === $tenant->id),
                Mockery::on(fn ($s) => $s instanceof CrawlSession && $s->mode === CrawlMode::Initial),
            );
        $this->app->instance(SiteCrawler::class, $crawler);

        $job = new CrawlWebsiteJob($tenant, 'initial');
        $job->handle(app(SiteCrawler::class));

        $this->assertSame(1, CrawlSession::forTenant($tenant)->count());
        $session = CrawlSession::forTenant($tenant)->first();
        $this->assertSame(CrawlMode::Initial, $session->mode);
    }

    public function test_failed_callback_marks_session_failed(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $session = CrawlSession::factory()->forTenant($tenant)->create([
            'status' => CrawlSessionStatus::Running,
        ]);

        $job = new CrawlWebsiteJob($tenant, 'initial');
        $job->failed(new \RuntimeException('boom'));

        $session->refresh();
        $this->assertSame(CrawlSessionStatus::Failed, $session->status);
        $this->assertStringContainsString('boom', (string) $session->error_message);
    }
}
