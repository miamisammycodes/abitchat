<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Enums\Role;
use App\Jobs\CrawlWebsiteJob;
use App\Models\CrawlSession;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class WebsiteIndexingControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_changes_website_url(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => null]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->patch('/widget-settings/website-indexing', [
            'website_url' => 'https://newsite.com',
            'auto_recrawl' => true,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', 'Website indexing settings saved.');
        $this->assertSame('https://newsite.com', $tenant->fresh()->website_url);
        $this->assertTrue($tenant->fresh()->auto_recrawl);
    }

    public function test_manual_recrawl_dispatches_job(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->post('/widget-settings/website-indexing/recrawl');

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', 'Re-crawl queued.');
        Bus::assertDispatched(CrawlWebsiteJob::class, fn (CrawlWebsiteJob $job) => $job->mode === CrawlMode::Manual);
    }

    public function test_manual_recrawl_returns_queue_error_when_dispatch_fails(): void
    {
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $tenant->id]);

        // Swap the Bus with a Mockery mock that throws on dispatch — simulates
        // queue store unreachable (Redis down, DB queue table locked, etc.).
        // Do NOT pair this with Bus::fake() — fake() installs BusFake, and
        // shouldReceive on a swapped facade behaves inconsistently.
        \Illuminate\Support\Facades\Bus::shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('Queue store unreachable'));

        $response = $this->actingAs($user)->post('/widget-settings/website-indexing/recrawl');

        $response->assertSessionHasErrors('queue');
        $response->assertSessionDoesntHaveErrors(['cooldown', 'website_url']);
    }

    public function test_manual_recrawl_blocked_within_cooldown(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create(['website_url' => 'https://example.com']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $tenant->id]);
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
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $tenant->id]);
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
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $tenant->id]);
        CrawlSession::factory()->forTenant($tenant)->create([
            'status' => CrawlSessionStatus::Completed,
            'started_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
        ]);

        $response = $this->actingAs($user)->post('/widget-settings/website-indexing/recrawl');

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success', 'Re-crawl queued.');
        Bus::assertDispatched(CrawlWebsiteJob::class);
    }

    public function test_clearing_website_url_does_not_dispatch(): void
    {
        Bus::fake();
        $tenant = Tenant::factory()->create(['website_url' => 'https://old.com']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $tenant->id]);

        $this->actingAs($user)->patch('/widget-settings/website-indexing', [
            'website_url' => null,
            'auto_recrawl' => false,
        ]);

        $this->assertNull($tenant->fresh()->website_url);
        Bus::assertNotDispatched(CrawlWebsiteJob::class);
    }

    public function test_latest_status_returns_null_when_no_session(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $tenant->id]);

        $response = $this->actingAs($user)->getJson('/widget-settings/website-indexing/status');

        $response->assertOk()->assertExactJson(['session' => null]);
    }

    public function test_latest_status_returns_latest_session_for_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $tenant->id]);
        CrawlSession::factory()->forTenant($tenant)->create([
            'status' => CrawlSessionStatus::Completed,
            'pages_indexed' => 5,
        ]);
        $latest = CrawlSession::factory()->forTenant($tenant)->create([
            'status' => CrawlSessionStatus::Running,
            'pages_indexed' => 3,
            'pages_discovered' => 10,
        ]);

        $response = $this->actingAs($user)->getJson('/widget-settings/website-indexing/status');

        $response->assertOk()->assertJsonPath('session.id', $latest->id)
            ->assertJsonPath('session.status', 'running')
            ->assertJsonPath('session.pages_indexed', 3)
            ->assertJsonPath('session.pages_discovered', 10);
    }

    public function test_latest_status_is_tenant_scoped(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        UserRole::create(['user_id' => $userA->id, 'role' => Role::Owner, 'tenant_id' => $tenantA->id]);
        CrawlSession::factory()->forTenant($tenantB)->create([
            'status' => CrawlSessionStatus::Running,
        ]);

        $response = $this->actingAs($userA)->getJson('/widget-settings/website-indexing/status');

        $response->assertOk()->assertExactJson(['session' => null]);
    }
}
