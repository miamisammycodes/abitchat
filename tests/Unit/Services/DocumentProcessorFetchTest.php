<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Knowledge\DocumentProcessor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentProcessorFetchTest extends TestCase
{
    public function test_loopback_url_is_rejected_at_fetch_time(): void
    {
        // Simulates DNS rebinding: validation already ran and passed (as
        // it would for a public-resolving hostname), but now the URL points
        // at a private IP. extractFromUrl must guard.
        $processor = app(DocumentProcessor::class);

        $this->expectException(\Throwable::class);
        $processor->extractFromUrl('http://127.0.0.1/admin');
    }

    public function test_non_2xx_response_surfaces_as_exception(): void
    {
        // The production code sets allow_redirects=false on Guzzle so a 30x
        // is exposed to the caller rather than transparently followed. This
        // test verifies the surface behavior: a 30x makes extractFromUrl
        // throw and no follow-up request is sent. Http::fake doesn't follow
        // redirects regardless of options, so this can't independently bind
        // the allow_redirects setting — code review treats the production
        // code as the ground truth there.
        Http::fake([
            '1.1.1.1/redir' => Http::response('', 302, [
                'Location' => 'http://169.254.169.254/latest/meta-data/',
            ]),
        ]);

        $processor = app(DocumentProcessor::class);

        $this->expectException(\Throwable::class);
        try {
            $processor->extractFromUrl('http://1.1.1.1/redir');
        } finally {
            Http::assertSentCount(1);
            Http::assertNotSent(fn ($req) => str_contains($req->url(), '169.254.169.254'));
        }
    }

    public function test_public_url_is_fetched_normally(): void
    {
        // Use a public IP literal to avoid DNS resolution in tests.
        Http::fake([
            '1.1.1.1/page' => Http::response('<p>Hello</p>', 200),
        ]);

        $processor = app(DocumentProcessor::class);
        $text = $processor->extractFromUrl('http://1.1.1.1/page');

        $this->assertStringContainsString('Hello', $text);
        Http::assertSentCount(1);
    }
}
