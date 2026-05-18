<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\RobotsTxtPolicy;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RobotsTxtPolicyTest extends TestCase
{
    public function test_missing_robots_returns_permissive_policy(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response('Not found', 404),
        ]);

        $policy = (new RobotsTxtPolicy)->fetchFor('https://example.com');

        $this->assertTrue($policy->isAllowed('https://example.com/anything'));
        $this->assertSame(1, $policy->crawlDelaySeconds());
    }

    public function test_disallow_for_our_user_agent(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response(
                "User-agent: ChatbotIndexer\nDisallow: /admin\n",
                200,
            ),
        ]);

        $policy = (new RobotsTxtPolicy)->fetchFor('https://example.com');

        $this->assertFalse($policy->isAllowed('https://example.com/admin'));
        $this->assertFalse($policy->isAllowed('https://example.com/admin/users'));
        $this->assertTrue($policy->isAllowed('https://example.com/about'));
    }

    public function test_specific_user_agent_overrides_wildcard(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response(
                "User-agent: *\nDisallow: /\n\nUser-agent: ChatbotIndexer\nAllow: /public\n",
                200,
            ),
        ]);

        $policy = (new RobotsTxtPolicy)->fetchFor('https://example.com');

        $this->assertTrue($policy->isAllowed('https://example.com/public/page'));
        $this->assertFalse($policy->isAllowed('https://example.com/private'));
    }

    public function test_crawl_delay_parsed(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response(
                "User-agent: ChatbotIndexer\nCrawl-delay: 5\n",
                200,
            ),
        ]);

        $policy = (new RobotsTxtPolicy)->fetchFor('https://example.com');

        $this->assertSame(5, $policy->crawlDelaySeconds());
    }

    public function test_returns_sitemap_urls(): void
    {
        Http::fake([
            'https://example.com/robots.txt' => Http::response(
                "Sitemap: https://example.com/sitemap.xml\nSitemap: https://example.com/news.xml\n",
                200,
            ),
        ]);

        $policy = (new RobotsTxtPolicy)->fetchFor('https://example.com');

        $this->assertSame(
            ['https://example.com/sitemap.xml', 'https://example.com/news.xml'],
            $policy->sitemapUrls(),
        );
    }
}
