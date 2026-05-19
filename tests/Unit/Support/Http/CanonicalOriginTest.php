<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Http;

use App\Support\Http\CanonicalOrigin;
use Tests\TestCase;

class CanonicalOriginTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // CanonicalOrigin::from (raw string input)
    // ---------------------------------------------------------------------------

    public function test_from_plain_http_url(): void
    {
        $this->assertSame('http://example.com', CanonicalOrigin::from('http://example.com'));
    }

    public function test_from_plain_https_url(): void
    {
        $this->assertSame('https://example.com', CanonicalOrigin::from('https://example.com'));
    }

    public function test_from_url_with_port(): void
    {
        $this->assertSame('http://localhost:8080', CanonicalOrigin::from('http://localhost:8080'));
    }

    public function test_from_url_strips_path(): void
    {
        $this->assertSame('https://example.com', CanonicalOrigin::from('https://example.com/some/path?q=1'));
    }

    public function test_from_url_with_port_strips_path(): void
    {
        $this->assertSame('http://localhost:3000', CanonicalOrigin::from('http://localhost:3000/app/page'));
    }

    public function test_from_missing_scheme_returns_null(): void
    {
        $this->assertNull(CanonicalOrigin::from('example.com/path'));
    }

    public function test_from_missing_host_returns_null(): void
    {
        $this->assertNull(CanonicalOrigin::from('://no-host'));
    }

    public function test_from_null_returns_null(): void
    {
        $this->assertNull(CanonicalOrigin::from(null));
    }

    // ---------------------------------------------------------------------------
    // CanonicalOrigin::fromParts (pre-parsed array input)
    // ---------------------------------------------------------------------------

    public function test_from_parts_happy_path(): void
    {
        $parts = ['scheme' => 'https', 'host' => 'example.com'];
        $this->assertSame('https://example.com', CanonicalOrigin::fromParts($parts));
    }

    public function test_from_parts_with_port(): void
    {
        $parts = ['scheme' => 'http', 'host' => 'localhost', 'port' => 8001];
        $this->assertSame('http://localhost:8001', CanonicalOrigin::fromParts($parts));
    }

    public function test_from_parts_missing_scheme_returns_null(): void
    {
        $parts = ['host' => 'example.com'];
        $this->assertNull(CanonicalOrigin::fromParts($parts));
    }

    public function test_from_parts_missing_host_returns_null(): void
    {
        $parts = ['scheme' => 'https'];
        $this->assertNull(CanonicalOrigin::fromParts($parts));
    }

    public function test_from_parts_empty_array_returns_null(): void
    {
        $this->assertNull(CanonicalOrigin::fromParts([]));
    }
}
