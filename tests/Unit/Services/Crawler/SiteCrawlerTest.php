<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Jobs\ProcessKnowledgeItem;
use App\Models\CrawlSession;
use App\Models\CrawlUrlBlocklist;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Services\Crawler\SiteCrawler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteCrawlerTest extends TestCase
{
    use RefreshDatabase;

    private SiteCrawler $crawler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crawler = app(SiteCrawler::class);
        $this->crawler->setSleeper(static fn () => null);
        Bus::fake();
    }

    public function test_happy_path_indexes_pages(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $session = CrawlSession::factory()->forTenant($tenant)->create([
            'mode' => CrawlMode::Initial,
            'status' => CrawlSessionStatus::Running,
        ]);

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapWith(['https://example.com/about', 'https://example.com/services']),
                200,
            ),
            'https://example.com/about*' => Http::response('<html><body><p>About us page content here, long enough to exceed the minimum chunk size threshold easily.</p></body></html>', 200, ['Last-Modified' => 'Wed, 12 Mar 2025 10:00:00 GMT']),
            'https://example.com/services*' => Http::response('<html><body><p>Services page content here, long enough to exceed the minimum chunk size threshold easily.</p></body></html>', 200),
        ]);

        $this->crawler->crawl($tenant, $session);

        $session->refresh();
        $this->assertSame(CrawlSessionStatus::Completed, $session->status);
        $this->assertGreaterThanOrEqual(2, $session->pages_indexed);
        $this->assertSame(2, KnowledgeItem::forTenant($tenant)->where('type', 'webpage')->count());

        Bus::assertDispatched(ProcessKnowledgeItem::class, 2);
    }

    public function test_budget_cap_truncates_crawl(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $session = CrawlSession::factory()->forTenant($tenant)->create([
            'status' => CrawlSessionStatus::Running,
        ]);

        // Pre-fill knowledge_items to trial cap (10) so the very first crawl page is over.
        KnowledgeItem::factory()->forTenant($tenant)->count(10)->create();

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapWith(['https://example.com/p1', 'https://example.com/p2']),
                200,
            ),
        ]);

        $this->crawler->crawl($tenant, $session);

        $session->refresh();
        $this->assertSame(CrawlSessionStatus::Partial, $session->status);
        $this->assertGreaterThan(0, $session->pages_skipped_budget);
    }

    public function test_diff_skip_for_unchanged_content_hash(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);

        $html = '<html><body><p>Stable content for diff-skip test, well over the minimum chunk threshold so it parses cleanly.</p></body></html>';
        // Pre-existing item with content_hash matching what we're about to fetch.
        KnowledgeItem::factory()
            ->forTenant($tenant)
            ->webpage('https://example.com/about', 'https://example.com/about')
            ->create([
                'metadata' => [
                    'crawl_session_id' => 1,
                    'content_hash' => 'sha256:'.hash('sha256', $html),
                    'last_modified' => null,
                    'etag' => null,
                ],
            ]);

        $session = CrawlSession::factory()->forTenant($tenant)->create([
            'mode' => CrawlMode::Refresh,
            'status' => CrawlSessionStatus::Running,
        ]);

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapWith(['https://example.com/about']),
                200,
            ),
            'https://example.com/about*' => Http::response($html, 200),
        ]);

        $this->crawler->crawl($tenant, $session);

        $session->refresh();
        $this->assertSame(1, $session->pages_skipped_unchanged);
        $this->assertSame(0, $session->pages_indexed);
        Bus::assertNotDispatched(ProcessKnowledgeItem::class);
    }

    public function test_blocklist_silently_skips_url(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        CrawlUrlBlocklist::factory()->forTenant($tenant)->create([
            'url_normalized' => 'https://example.com/admin',
        ]);
        $session = CrawlSession::factory()->forTenant($tenant)->create(['status' => CrawlSessionStatus::Running]);

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapWith(['https://example.com/admin', 'https://example.com/public']),
                200,
            ),
            'https://example.com/public*' => Http::response('<html><body><p>Public page content here, well above the minimum chunk size threshold.</p></body></html>', 200),
        ]);

        $this->crawler->crawl($tenant, $session);

        $this->assertSame(0, KnowledgeItem::forTenant($tenant)->where('url_normalized', 'https://example.com/admin')->count());
        $this->assertSame(1, KnowledgeItem::forTenant($tenant)->where('url_normalized', 'https://example.com/public')->count());
    }

    public function test_robots_disallow_blocks_pages(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $session = CrawlSession::factory()->forTenant($tenant)->create(['status' => CrawlSessionStatus::Running]);

        Http::fake([
            'https://example.com/robots.txt' => Http::response(
                "User-agent: ChatbotIndexer\nDisallow: /admin\n",
                200,
            ),
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapWith(['https://example.com/admin', 'https://example.com/about']),
                200,
            ),
            'https://example.com/about*' => Http::response('<html><body><p>About us page content here, long enough to satisfy the chunk threshold easily for tests.</p></body></html>', 200),
        ]);

        $this->crawler->crawl($tenant, $session);

        $this->assertSame(0, KnowledgeItem::forTenant($tenant)->where('url_normalized', 'https://example.com/admin')->count());
        $session->refresh();
        $this->assertGreaterThanOrEqual(1, $session->pages_failed);
    }

    /**
     * @param  list<string>  $urls
     */
    private function sitemapWith(array $urls): string
    {
        $entries = '';
        foreach ($urls as $u) {
            $entries .= '<url><loc>'.$u.'</loc></url>';
        }

        return '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$entries.'</urlset>';
    }
}
