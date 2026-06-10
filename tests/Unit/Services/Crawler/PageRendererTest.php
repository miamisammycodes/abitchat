<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\PageRenderer;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PageRendererTest extends TestCase
{
    public function test_disabled_by_default_returns_null(): void
    {
        Config::set('services.crawler.js_rendering', false);
        $this->assertFalse(app(PageRenderer::class)->enabled());
        $this->assertNull(app(PageRenderer::class)->render('https://example.com/'));
    }

    public function test_enabled_flag_reflects_config(): void
    {
        Config::set('services.crawler.js_rendering', true);
        Config::set('services.crawler.egress_proxy', '127.0.0.1:8118');
        $this->assertTrue(app(PageRenderer::class)->enabled());
    }

    public function test_enabled_is_false_when_js_rendering_on_but_no_egress_proxy(): void
    {
        Config::set('services.crawler.js_rendering', true);
        Config::set('services.crawler.egress_proxy', null);
        $this->assertFalse((new PageRenderer)->enabled());
    }

    public function test_enabled_is_true_only_when_js_rendering_and_egress_proxy_both_set(): void
    {
        Config::set('services.crawler.js_rendering', true);
        Config::set('services.crawler.egress_proxy', '127.0.0.1:8118');
        $this->assertTrue((new PageRenderer)->enabled());
    }

    public function test_unsafe_url_returns_null_even_when_enabled(): void
    {
        Config::set('services.crawler.js_rendering', true);
        Config::set('services.crawler.egress_proxy', '127.0.0.1:8118');
        $this->assertNull(app(PageRenderer::class)->render('http://127.0.0.1/admin'));
    }
}
