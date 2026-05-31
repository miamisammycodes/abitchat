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
        $this->assertTrue(app(PageRenderer::class)->enabled());
    }

    public function test_unsafe_url_returns_null_even_when_enabled(): void
    {
        Config::set('services.crawler.js_rendering', true);
        $this->assertNull(app(PageRenderer::class)->render('http://127.0.0.1/admin'));
    }
}
