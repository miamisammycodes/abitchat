<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\BlockedAddressException;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Services\Crawler\GuardedHttpClient;
use App\Services\Knowledge\DocumentProcessor;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class DocumentProcessorFetchTest extends TestCase
{
    private function makeWebpageItem(string $url): KnowledgeItem
    {
        $tenant = Tenant::create([
            'name' => 'Fetch Co',
            'slug' => 'fetch-co-'.uniqid(),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);

        return KnowledgeItem::create([
            'tenant_id' => $tenant->id,
            'type' => 'webpage',
            'title' => 'Fetch Item',
            'source_url' => $url,
            'status' => 'pending',
        ]);
    }

    public function test_loopback_url_is_rejected_at_fetch_time(): void
    {
        // Simulates DNS rebinding: validation already ran and passed (as
        // it would for a public-resolving hostname), but now the URL points
        // at a private IP. extractFromUrl must guard.
        $item = $this->makeWebpageItem('http://127.0.0.1/admin');
        $processor = app(DocumentProcessor::class);

        $this->expectException(\Throwable::class);
        $processor->chunk($processor->extract($item));
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

        $item = $this->makeWebpageItem('http://1.1.1.1/redir');
        $processor = app(DocumentProcessor::class);

        $this->expectException(\Throwable::class);
        try {
            $processor->chunk($processor->extract($item));
        } finally {
            Http::assertSentCount(1);
            Http::assertNotSent(fn ($req) => str_contains($req->url(), '169.254.169.254'));
        }
    }

    public function test_public_url_is_fetched_normally(): void
    {
        // Use a public IP literal to avoid DNS resolution in tests.
        // Content must be ≥50 chars to survive the minimum chunk filter.
        $body = '<p>Hello from this long enough test page with plenty of content to pass the filter.</p>';
        Http::fake([
            '1.1.1.1/page' => Http::response($body, 200),
        ]);

        $item = $this->makeWebpageItem('http://1.1.1.1/page');
        $processor = app(DocumentProcessor::class);
        $chunks = $processor->chunk($processor->extract($item));

        $combined = implode(' ', $chunks);
        $this->assertStringContainsString('Hello', $combined);
        Http::assertSentCount(1);
    }

    public function test_fetch_url_returns_raw_body_for_public_url(): void
    {
        Http::fake(['1.1.1.1/raw' => Http::response('<html><body><p>Raw body here</p></body></html>', 200)]);

        $body = app(DocumentProcessor::class)->fetchUrl('http://1.1.1.1/raw');

        $this->assertStringContainsString('Raw body here', $body);
        $this->assertStringContainsString('<html>', $body);
    }

    public function test_fetch_url_rejects_loopback(): void
    {
        $this->expectException(\Throwable::class);
        app(DocumentProcessor::class)->fetchUrl('http://127.0.0.1/admin');
    }

    public function test_fetch_url_routes_through_guarded_http_client(): void
    {
        // The guarded client is the only thing that should perform the fetch —
        // prove DocumentProcessor delegates to it (IP-pinned, redirect-revalidating).
        $guard = Mockery::mock(GuardedHttpClient::class);
        $guard->shouldReceive('get')
            ->once()
            ->with('http://1.1.1.1/guarded')
            ->andReturn(new \Illuminate\Http\Client\Response(
                new \GuzzleHttp\Psr7\Response(200, [], '<p>Guarded body content here.</p>')
            ));
        $this->app->instance(GuardedHttpClient::class, $guard);

        $body = $this->app->make(DocumentProcessor::class)->fetchUrl('http://1.1.1.1/guarded');

        $this->assertStringContainsString('Guarded body content here.', $body);
    }

    public function test_fetch_url_propagates_guarded_block_for_rebound_host(): void
    {
        // Simulate the DNS-rebind case the raw Http client missed: name-time
        // validation passed, but the guarded client blocks the resolved private IP.
        $guard = Mockery::mock(GuardedHttpClient::class);
        $guard->shouldReceive('get')
            ->once()
            ->andThrow(new BlockedAddressException('Blocked non-public address: rebind.example'));
        $this->app->instance(GuardedHttpClient::class, $guard);

        $this->expectException(BlockedAddressException::class);
        // Public-literal URL passes the cheap SafeExternalUrl pre-check, then the
        // guarded client re-resolves and blocks.
        $this->app->make(DocumentProcessor::class)->fetchUrl('http://1.1.1.1/rebind');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
