<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Enums\Role;
use App\Models\CrawlSession;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandleInertiaRequestsShareLatestCrawlSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_receives_latest_crawl_session(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserRole::create([
            'user_id' => $user->id,
            'role' => Role::Owner,
            'tenant_id' => $tenant->id,
        ]);
        $session = CrawlSession::factory()->forTenant($tenant)->create([
            'pages_skipped_no_content' => 3,
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertInertia(fn ($page) => $page->has('latest_crawl_session', fn ($s) => $s
            ->where('id', $session->id)
            ->where('pages_skipped_no_content', 3)
            ->etc()
        ));
    }

    public function test_unrelated_route_does_not_share_session(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        CrawlSession::factory()->forTenant($tenant)->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertInertia(fn ($page) => $page->where('latest_crawl_session', null));
    }
}
