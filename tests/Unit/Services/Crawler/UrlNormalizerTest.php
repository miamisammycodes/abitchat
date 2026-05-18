<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\UrlNormalizer;
use PHPUnit\Framework\TestCase;

class UrlNormalizerTest extends TestCase
{
    private UrlNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new UrlNormalizer;
    }

    public function test_lowercases_host(): void
    {
        $this->assertSame(
            'https://example.com/About',
            $this->normalizer->normalize('https://EXAMPLE.com/About'),
        );
    }

    public function test_strips_fragment(): void
    {
        $this->assertSame(
            'https://example.com/page',
            $this->normalizer->normalize('https://example.com/page#section-1'),
        );
    }

    public function test_strips_tracking_params(): void
    {
        $url = 'https://example.com/page?utm_source=foo&utm_medium=bar&fbclid=123&gclid=abc&ref=test&_ga=GA1&mc_eid=eid&mc_cid=cid';
        $this->assertSame(
            'https://example.com/page',
            $this->normalizer->normalize($url),
        );
    }

    public function test_keeps_non_tracking_params(): void
    {
        $this->assertSame(
            'https://example.com/page?id=42',
            $this->normalizer->normalize('https://example.com/page?utm_source=foo&id=42'),
        );
    }

    public function test_collapses_trailing_slash_on_root(): void
    {
        $this->assertSame('https://example.com', $this->normalizer->normalize('https://example.com/'));
    }

    public function test_keeps_trailing_slash_elsewhere(): void
    {
        $this->assertSame('https://example.com/about/', $this->normalizer->normalize('https://example.com/about/'));
    }

    public function test_removes_default_ports(): void
    {
        $this->assertSame('https://example.com/path', $this->normalizer->normalize('https://example.com:443/path'));
        $this->assertSame('http://example.com/path', $this->normalizer->normalize('http://example.com:80/path'));
    }

    public function test_keeps_non_default_ports(): void
    {
        $this->assertSame('https://example.com:8443/path', $this->normalizer->normalize('https://example.com:8443/path'));
    }

    public function test_sorts_query_string_params(): void
    {
        $this->assertSame(
            'https://example.com/page?a=1&b=2&c=3',
            $this->normalizer->normalize('https://example.com/page?c=3&a=1&b=2'),
        );
    }

    public function test_normalizes_www_prefix_for_host_comparison(): void
    {
        // www-strip applies only when checking host equality, NOT in the normalized URL.
        // The normalized URL preserves www so we don't collapse two distinct sites.
        $this->assertSame('https://www.example.com/x', $this->normalizer->normalize('https://www.example.com/x'));
        $this->assertSame('https://example.com/x', $this->normalizer->normalize('https://example.com/x'));
    }

    public function test_handles_invalid_input(): void
    {
        $this->assertSame('not-a-url', $this->normalizer->normalize('not-a-url'));
        $this->assertSame('', $this->normalizer->normalize(''));
    }
}
