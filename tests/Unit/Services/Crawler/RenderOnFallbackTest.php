<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\PageRenderer;
use App\Services\Crawler\RenderOnFallback;
use App\Services\Knowledge\ContentSufficiency;
use App\Services\Knowledge\DocumentProcessor;
use Mockery;
use Tests\TestCase;

class RenderOnFallbackTest extends TestCase
{
    private function resolver(PageRenderer $renderer): RenderOnFallback
    {
        return new RenderOnFallback(new DocumentProcessor, new ContentSufficiency, $renderer);
    }

    private string $spaShell = '<html><body><div id="root">Tours Book Bhutan Tour visit us today right now for more info here</div></body></html>';

    private string $realPage = '<html><body><main><h1>Bhutan Tours</h1><p>We run guided cultural and trekking tours across Bhutan with licensed local guides every season of the year.</p></main></body></html>';

    public function test_sufficient_http_body_does_not_render(): void
    {
        $renderer = Mockery::mock(PageRenderer::class);
        $renderer->shouldNotReceive('render');

        $result = $this->resolver($renderer)->resolve('https://x.test', $this->realPage);

        $this->assertTrue($result['sufficient']);
        $this->assertFalse($result['rendered']);
    }

    public function test_insufficient_http_then_render_succeeds(): void
    {
        $renderer = Mockery::mock(PageRenderer::class);
        $renderer->shouldReceive('render')->once()->with('https://x.test')->andReturn($this->realPage);

        $result = $this->resolver($renderer)->resolve('https://x.test', $this->spaShell);

        $this->assertTrue($result['sufficient']);
        $this->assertTrue($result['rendered']);
        $this->assertStringContainsString('Bhutan', $result['text']);
    }

    public function test_insufficient_http_and_render_null_is_insufficient(): void
    {
        $renderer = Mockery::mock(PageRenderer::class);
        $renderer->shouldReceive('render')->once()->andReturn(null);

        $result = $this->resolver($renderer)->resolve('https://x.test', $this->spaShell);

        $this->assertFalse($result['sufficient']);
        $this->assertFalse($result['rendered']);
    }

    public function test_render_still_insufficient(): void
    {
        $renderer = Mockery::mock(PageRenderer::class);
        $renderer->shouldReceive('render')->once()->andReturn($this->spaShell);

        $result = $this->resolver($renderer)->resolve('https://x.test', $this->spaShell);

        $this->assertFalse($result['sufficient']);
        $this->assertTrue($result['rendered']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
