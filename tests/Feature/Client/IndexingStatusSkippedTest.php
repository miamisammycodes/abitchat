<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Enums\CrawlMode;
use App\Enums\CrawlSessionStatus;
use App\Enums\Role;
use App\Models\CrawlSession;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndexingStatusSkippedTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_status_exposes_pages_skipped_no_content(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserRole::create(['user_id' => $user->id, 'role' => Role::Owner, 'tenant_id' => $tenant->id]);

        CrawlSession::factory()->forTenant($tenant)->create([
            'mode' => CrawlMode::Manual,
            'status' => CrawlSessionStatus::Partial,
            'pages_indexed' => 2,
            'pages_skipped_no_content' => 3,
        ]);

        $response = $this->actingAs($user)->getJson(route('widget.indexing.status'));

        $response->assertOk()->assertJsonPath('session.pages_skipped_no_content', 3);
    }
}
