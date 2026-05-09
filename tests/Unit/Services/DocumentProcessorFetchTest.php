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

    public function test_redirect_response_is_not_followed(): void
    {
        // Http::fake never auto-follows redirects regardless of options, so
        // the canonical assertion is "exactly one HTTP request was sent and
        // it surfaced the 30x to the caller as a non-successful response,
        // which extractFromUrl converts into an exception."
        // Use a public IP literal (1.1.1.1) to avoid DNS resolution in tests —
        // SafeExternalUrl::isSafe resolves hostnames via dns_get_record, which
        // would reject unresolvable test hostnames before Http::fake fires.
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
