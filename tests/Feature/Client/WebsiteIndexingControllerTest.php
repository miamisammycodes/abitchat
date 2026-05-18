<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Enums\CrawlSessionStatus;
use App\Jobs\CrawlWebsiteJob;
use App\Models\CrawlSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class WebsiteIndexingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_changes_website_url(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => null]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);

        $response = $this->actingAs($user)->patch('/widget-settings/website-indexing', [
            'website_url' => 'https://newsite.com',
            'auto_recrawl' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('https://newsite.com', $tenant->fresh()->website_url);
        $this->assertTrue($tenant->fresh()->auto_recrawl);
    }

    public function test_manual_recrawl_dispatches_job(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);

        $response = $this->actingAs($user)->post('/widget-settings/website-indexing/recrawl');

        $response->assertSessionHasNoErrors();
        Bus::assertDispatched(CrawlWebsiteJob::class, fn (CrawlWebsiteJob $job) => $job->mode === 'manual');
    }

    public function test_manual_recrawl_blocked_within_cooldown(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);
        CrawlSession::factory()->forTenant($tenant)->create([
            'status' => CrawlSessionStatus::Completed,
            'started_at' => now()->subMinutes(30),
            'created_at' => now()->subMinutes(30),
        ]);

        $response = $this->actingAs($user)->post('/widget-settings/website-indexing/recrawl');

        $response->assertSessionHasErrors('cooldown');
        Bus::assertNotDispatched(CrawlWebsiteJob::class);
    }

    public function test_manual_recrawl_blocked_by_queued_session_even_with_null_started_at(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);
        // A previous click queued a job that the worker hasn't picked up yet.
        // started_at is NULL — older cooldown-by-started_at logic would let
        // a second click slip through. New logic blocks via status check.
        CrawlSession::factory()->forTenant($tenant)->create([
            'status' => CrawlSessionStatus::Queued,
            'started_at' => null,
            'created_at' => now()->subHours(8), // outside the time-based window
        ]);

        $response = $this->actingAs($user)->post('/widget-settings/website-indexing/recrawl');

        $response->assertSessionHasErrors('cooldown');
        Bus::assertNotDispatched(CrawlWebsiteJob::class);
    }

    public function test_manual_recrawl_allowed_after_cooldown(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);
        CrawlSession::factory()->forTenant($tenant)->create([
            'status' => CrawlSessionStatus::Completed,
            'started_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
        ]);

        $response = $this->actingAs($user)->post('/widget-settings/website-indexing/recrawl');

        $response->assertSessionHasNoErrors();
        Bus::assertDispatched(CrawlWebsiteJob::class);
    }

    public function test_clearing_website_url_does_not_dispatch(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create(['website_url' => 'https://old.com']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);

        $this->actingAs($user)->patch('/widget-settings/website-indexing', [
            'website_url' => null,
            'auto_recrawl' => false,
        ]);

        $this->assertNull($tenant->fresh()->website_url);
        Bus::assertNotDispatched(CrawlWebsiteJob::class);
    }
}
