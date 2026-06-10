<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\SitemapDiscoverer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SitemapDiscovererTest extends TestCase
{
    private SitemapDiscoverer $discoverer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discoverer = app(SitemapDiscoverer::class);
    }

    public function test_discovers_from_root_sitemap_xml(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://example.com/</loc></url>'
                .'<url><loc>https://example.com/about</loc></url>'
                .'<url><loc>https://example.com/services</loc></url>'
                .'</urlset>',
                200,
            ),
        ]);

        $urls = iterator_to_array($this->discoverer->discover('https://example.com'));

        $this->assertContains('https://example.com/', $urls);
        $this->assertContains('https://example.com/about', $urls);
        $this->assertContains('https://example.com/services', $urls);
    }

    public function test_falls_back_to_bfs_when_no_sitemap(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response('', 404),
            'https://example.com' => Http::response(
                '<html><body><a href="/about">About</a><a href="/blog">Blog</a><a href="https://other.com/x">External</a></body></html>',
                200,
            ),
            'https://example.com/about' => Http::response(
                '<html><body><a href="/team">Team</a></body></html>',
                200,
            ),
            'https://example.com/blog' => Http::response('<html><body></body></html>', 200),
            'https://example.com/team' => Http::response('<html><body></body></html>', 200),
        ]);

        $urls = iterator_to_array($this->discoverer->discover('https://example.com'));

        $this->assertContains('https://example.com', $urls);
        $this->assertContains('https://example.com/about', $urls);
        $this->assertContains('https://example.com/blog', $urls);
        $this->assertContains('https://example.com/team', $urls);

        foreach ($urls as $u) {
            $this->assertStringStartsWith('https://example.com', $u, "External link leaked: {$u}");
        }
    }

    public function test_uses_sitemap_referenced_in_robots(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response(
                "Sitemap: https://example.com/news-sitemap.xml\n",
                200,
            ),
            'https://example.com/news-sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://example.com/news/1</loc></url>'
                .'</urlset>',
                200,
            ),
        ]);

        $urls = iterator_to_array($this->discoverer->discover('https://example.com'));

        $this->assertContains('https://example.com/news/1', $urls);
    }

    public function test_nested_sitemap_listed_under_url_tag_is_recursed_not_yielded(): void
    {
        // laravel-news.com and others list nested sitemaps under <url> instead
        // of <sitemap>. Verify the discoverer recurses on .xml URLs rather than
        // treating them as content pages.
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response(
                '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://example.com/sitemap_articles.xml</loc></url>'
                .'<url><loc>https://example.com/about</loc></url>'
                .'</urlset>',
                200,
            ),
            'https://example.com/sitemap_articles.xml' => Http::response(
                '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://example.com/article/1</loc></url>'
                .'<url><loc>https://example.com/article/2</loc></url>'
                .'</urlset>',
                200,
            ),
        ]);

        $urls = iterator_to_array($this->discoverer->discover('https://example.com'));

        $this->assertContains('https://example.com/article/1', $urls);
        $this->assertContains('https://example.com/article/2', $urls);
        $this->assertContains('https://example.com/about', $urls);
        $this->assertNotContains('https://example.com/sitemap_articles.xml', $urls, 'nested sitemap URL must NOT be yielded as content');
    }

    public function test_bfs_respects_depth_cap(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('', 404),
            'https://example.com/sitemap.xml' => Http::response('', 404),
            'https://example.com' => Http::response('<html><body><a href="/d1">D1</a></body></html>', 200),
            'https://example.com/d1' => Http::response('<html><body><a href="/d2">D2</a></body></html>', 200),
            'https://example.com/d2' => Http::response('<html><body><a href="/d3">D3</a></body></html>', 200),
            'https://example.com/d3' => Http::response('<html><body><a href="/d4">D4</a></body></html>', 200),
            'https://example.com/d4' => Http::response('<html><body></body></html>', 200),
        ]);

        $urls = iterator_to_array($this->discoverer->discover('https://example.com'));

        $this->assertContains('https://example.com/d3', $urls, 'depth 3 should be included');
        $this->assertNotContains('https://example.com/d4', $urls, 'depth 4 exceeds cap');
    }
}
