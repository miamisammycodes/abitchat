<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Exceptions\BlockedAddressException;
use App\Services\Crawler\GuardedHttpClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GuardedHttpClientTest extends TestCase
{
    private GuardedHttpClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->client = new GuardedHttpClient;
    }

    public function test_returns_a_successful_response_for_a_public_target(): void
    {
        Http::fake(['http://1.1.1.1/page' => Http::response('hello', 200)]);

        $response = $this->client->get('http://1.1.1.1/page');

        $this->assertTrue($response->successful());
        $this->assertSame('hello', $response->body());
    }

    public function test_follows_a_redirect_to_a_public_target(): void
    {
        Http::fake([
            'http://1.1.1.1/start' => Http::response('', 301, ['Location' => 'http://1.0.0.1/end']),
            'http://1.0.0.1/end' => Http::response('done', 200),
        ]);

        $response = $this->client->get('http://1.1.1.1/start');

        $this->assertSame('done', $response->body());
        Http::assertSentCount(2);
    }

    public function test_blocks_and_never_sends_a_redirect_hop_to_a_private_address(): void
    {
        Http::fake([
            'http://1.1.1.1/start' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest']),
            'http://169.254.169.254/*' => Http::response('SECRET', 200),
        ]);

        $this->expectException(BlockedAddressException::class);

        try {
            $this->client->get('http://1.1.1.1/start');
        } finally {
            Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
        }
    }

    public function test_rejects_a_private_initial_url_before_any_request(): void
    {
        Http::fake();

        $this->expectException(BlockedAddressException::class);

        try {
            $this->client->get('http://127.0.0.1/');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_rejects_a_disallowed_scheme(): void
    {
        Http::fake();

        $this->expectException(BlockedAddressException::class);

        try {
            $this->client->get('file:///etc/passwd');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_aborts_after_exceeding_the_redirect_cap(): void
    {
        Http::fake([
            'http://1.1.1.1/*' => Http::response('', 301, ['Location' => 'http://1.1.1.1/next']),
        ]);

        $this->expectException(BlockedAddressException::class);

        $this->client->get('http://1.1.1.1/loop');
    }

    public function test_head_issues_a_head_request(): void
    {
        Http::fake(['http://1.1.1.1/' => Http::response('', 200, ['ETag' => 'abc'])]);

        $response = $this->client->head('http://1.1.1.1/');

        $this->assertSame('abc', $response->header('ETag'));
        Http::assertSent(fn ($request) => $request->method() === 'HEAD');
    }
}
