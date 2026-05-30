<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Enums\KnowledgeItemStatus;
use App\Jobs\ProcessKnowledgeItem;
use App\Models\CrawlSession;
use App\Models\CrawlUrlBlocklist;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Services\Crawler\PageRenderer;
use App\Services\Crawler\SiteCrawler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
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

    public function test_javascript_rendered_shell_is_marked_skipped_no_content(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $session = CrawlSession::factory()->forTenant($tenant)->create([
            'mode' => CrawlMode::Initial,
            'status' => CrawlSessionStatus::Running,
        ]);

        $clean = 'Tours Book Bhutan Tour Official Website for Book Bhutan Tour visit us today now here';
        $shell = '<html><body><div id="root">'.$clean.'</div><script>'.str_repeat('var x=1;', 800).'</script></body></html>';

        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response(
                $this->sitemapWith(['https://example.com/tours']),
                200,
            ),
            'https://example.com/tours*' => Http::response($shell, 200),
        ]);

        $this->crawler->crawl($tenant, $session);

        $session->refresh();
        $item = KnowledgeItem::forTenant($tenant)->where('type', 'webpage')->firstOrFail();

        $this->assertSame(KnowledgeItemStatus::SkippedNoContent, $item->status);
        $this->assertSame('no_content', $item->metadata['skipped_reason'] ?? null);
        $this->assertSame(1, $session->pages_skipped_no_content);
        $this->assertSame(0, $session->pages_indexed);
        $this->assertSame(CrawlSessionStatus::Partial, $session->status);
        Bus::assertNotDispatched(ProcessKnowledgeItem::class);
    }

    public function test_recrawl_to_shell_deletes_stale_chunks(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);

        $oldHtml = '<html><body><p>Old real content that was long enough to index and produce chunks for this page.</p></body></html>';
        $item = KnowledgeItem::factory()
            ->forTenant($tenant)
            ->webpage('https://example.com/tours', 'https://example.com/tours')
            ->create([
                'status' => KnowledgeItemStatus::Ready,
                'metadata' => ['crawl_session_id' => 1, 'content_hash' => 'sha256:'.hash('sha256', $oldHtml)],
            ]);
        $item->chunks()->create(['content' => 'old chunk one', 'chunk_index' => 0, 'embedding' => null]);
        $item->chunks()->create(['content' => 'old chunk two', 'chunk_index' => 1, 'embedding' => null]);

        $session = CrawlSession::factory()->forTenant($tenant)->create([
            'mode' => CrawlMode::Refresh,
            'status' => CrawlSessionStatus::Running,
        ]);

        // Content changed (passes the diff check) but is now a JS shell (fails the gate).
        $shell = '<html><body><div id="root">Tours Book Bhutan Tour visit us today right now for more info here</div></body></html>';
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response($this->sitemapWith(['https://example.com/tours']), 200),
            'https://example.com/tours*' => Http::response($shell, 200),
        ]);

        $this->crawler->crawl($tenant, $session);

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::SkippedNoContent, $item->status);
        $this->assertSame(0, $item->chunks()->count());
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

    public function test_skipped_item_heals_on_recrawl_when_rendering_enabled(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);

        // Pre-existing SkippedNoContent item with an UNCHANGED shell hash.
        $shell = '<html><body><div id="root">Tours visit us today right now for more info here please</div></body></html>';
        $item = KnowledgeItem::factory()->forTenant($tenant)
            ->webpage('https://example.com/tours', 'https://example.com/tours')
            ->create([
                'status' => KnowledgeItemStatus::SkippedNoContent,
                'metadata' => ['crawl_session_id' => 1, 'content_hash' => 'sha256:'.hash('sha256', $shell), 'skipped_reason' => 'no_content'],
            ]);

        // Fake renderer: enabled, and "renders" the shell into a real page.
        $real = '<html><body><main><p>Our Bhutan cultural tours include guided treks, monastery visits, and homestays across the kingdom every season.</p></main></body></html>';
        $renderer = Mockery::mock(PageRenderer::class);
        $renderer->shouldReceive('enabled')->andReturn(true);
        $renderer->shouldReceive('render')->andReturn($real);
        $this->app->instance(PageRenderer::class, $renderer);

        $session = CrawlSession::factory()->forTenant($tenant)->create([
            'mode' => CrawlMode::Refresh,
            'status' => CrawlSessionStatus::Running,
        ]);
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response($this->sitemapWith(['https://example.com/tours']), 200),
            'https://example.com/tours*' => Http::response($shell, 200),
        ]);

        $crawler = app(SiteCrawler::class);
        $crawler->setSleeper(fn () => null);
        $crawler->crawl($tenant, $session);

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::Pending, $item->status); // re-attempted, rendered, queued for embedding
        $this->assertStringContainsString('cultural tours', (string) $item->content);
        $this->assertSame(1, $session->refresh()->pages_indexed);
        Bus::assertDispatched(ProcessKnowledgeItem::class);
    }

    public function test_skipped_item_heals_on_recrawl_when_rendering_enabled_despite_matching_validators(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);

        // Pre-existing SkippedNoContent item with stored ETag (refresh-crawled SPA).
        $shell = '<html><body><div id="root">Tours visit us today right now for more info here please</div></body></html>';
        $item = KnowledgeItem::factory()->forTenant($tenant)
            ->webpage('https://example.com/tours', 'https://example.com/tours')
            ->create([
                'status' => KnowledgeItemStatus::SkippedNoContent,
                'metadata' => [
                    'crawl_session_id' => 1,
                    'content_hash' => 'sha256:'.hash('sha256', $shell),
                    'skipped_reason' => 'no_content',
                    'etag' => '"v1"',
                ],
            ]);

        // Fake renderer: enabled, and "renders" the shell into a real page.
        $real = '<html><body><main><p>Our Bhutan cultural tours include guided treks, monastery visits, and homestays across the kingdom every season.</p></main></body></html>';
        $renderer = Mockery::mock(PageRenderer::class);
        $renderer->shouldReceive('enabled')->andReturn(true);
        $renderer->shouldReceive('render')->andReturn($real);
        $this->app->instance(PageRenderer::class, $renderer);

        $session = CrawlSession::factory()->forTenant($tenant)->create([
            'mode' => CrawlMode::Refresh,
            'status' => CrawlSessionStatus::Running,
        ]);
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response($this->sitemapWith(['https://example.com/tours']), 200),
            // ETag matches the stored validator → HEAD-probe gate would short-circuit
            // were it not for the heal bypass.
            'https://example.com/tours*' => Http::response($shell, 200, ['ETag' => '"v1"']),
        ]);

        $crawler = app(SiteCrawler::class);
        $crawler->setSleeper(fn () => null);
        $crawler->crawl($tenant, $session);

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::Pending, $item->status); // healed despite matching ETag
        $this->assertStringContainsString('cultural tours', (string) $item->content);
        $this->assertSame(1, $session->refresh()->pages_indexed);
        Bus::assertDispatched(ProcessKnowledgeItem::class);
    }

    public function test_render_attempted_skipped_item_is_not_re_rendered_on_unchanged_recrawl(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $shell = '<html><body><div id="root">Tours visit us today right now for more info here please</div></body></html>';
        KnowledgeItem::factory()->forTenant($tenant)
            ->webpage('https://example.com/tours', 'https://example.com/tours')
            ->create([
                'status' => KnowledgeItemStatus::SkippedNoContent,
                'metadata' => [
                    'content_hash' => 'sha256:'.hash('sha256', $shell),
                    'skipped_reason' => 'no_content',
                    'render_attempted_at' => now()->subDay()->toIso8601String(),
                ],
            ]);

        // Rendering on, but the page was already render-attempted + hash unchanged → must NOT re-render.
        $renderer = Mockery::mock(PageRenderer::class);
        $renderer->shouldReceive('enabled')->andReturn(true);
        $renderer->shouldNotReceive('render');
        $this->app->instance(PageRenderer::class, $renderer);

        $session = CrawlSession::factory()->forTenant($tenant)->create(['mode' => CrawlMode::Refresh, 'status' => CrawlSessionStatus::Running]);
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response($this->sitemapWith(['https://example.com/tours']), 200),
            'https://example.com/tours*' => Http::response($shell, 200),
        ]);

        $crawler = app(SiteCrawler::class);
        $crawler->setSleeper(fn () => null);
        $crawler->crawl($tenant, $session);

        $this->assertSame(1, $session->refresh()->pages_skipped_unchanged);
    }

    public function test_failed_heal_render_stamps_render_attempted_at(): void
    {
        // A heal candidate (SkippedNoContent, no render_attempted_at) whose render
        // STILL fails must be stamped so it isn't re-rendered every crawl (P2-8 WRITE).
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $shell = '<html><body><div id="root">Tours visit us today right now for more info here please</div></body></html>';
        $item = KnowledgeItem::factory()->forTenant($tenant)
            ->webpage('https://example.com/tours', 'https://example.com/tours')
            ->create([
                'status' => KnowledgeItemStatus::SkippedNoContent,
                'metadata' => [
                    'content_hash' => 'sha256:'.hash('sha256', $shell),
                    'skipped_reason' => 'no_content',
                    // no render_attempted_at → heal candidate (bypasses dedup)
                ],
            ]);

        // Rendering on; the heal render returns null (still unrenderable).
        $renderer = Mockery::mock(PageRenderer::class);
        $renderer->shouldReceive('enabled')->andReturn(true);
        $renderer->shouldReceive('render')->once()->andReturnNull();
        $this->app->instance(PageRenderer::class, $renderer);

        $session = CrawlSession::factory()->forTenant($tenant)->create(['mode' => CrawlMode::Refresh, 'status' => CrawlSessionStatus::Running]);
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response($this->sitemapWith(['https://example.com/tours']), 200),
            'https://example.com/tours*' => Http::response($shell, 200),
        ]);

        $crawler = app(SiteCrawler::class);
        $crawler->setSleeper(fn () => null);
        $crawler->crawl($tenant, $session);

        $item->refresh();
        $this->assertSame(KnowledgeItemStatus::SkippedNoContent, $item->status);
        $this->assertNotNull($item->metadata['render_attempted_at'] ?? null, 'render_attempted_at must be stamped so the page is not re-rendered next crawl');
        $this->assertSame(1, $session->refresh()->pages_skipped_no_content);
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
