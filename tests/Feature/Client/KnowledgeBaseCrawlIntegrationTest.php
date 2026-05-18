<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Models\CrawlSession;
use App\Models\CrawlUrlBlocklist;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeBaseCrawlIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_filters_by_crawl_session_id(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);
        $session = CrawlSession::factory()->forTenant($tenant)->create();

        KnowledgeItem::factory()->forTenant($tenant)->count(2)->create([
            'type' => 'webpage',
            'metadata' => ['crawl_session_id' => $session->id],
        ]);
        KnowledgeItem::factory()->forTenant($tenant)->count(3)->create();

        // KnowledgeBaseController::index() returns items as a flat mapped array (no pagination),
        // so we assert on the flat count via the Inertia `count` matcher.
        $response = $this->actingAs($user)->get('/knowledge?crawl_session_id='.$session->id);

        $response->assertInertia(fn ($p) => $p->has('items', 2));
    }

    public function test_destroying_webpage_item_adds_to_blocklist_when_confirmed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);
        $item = KnowledgeItem::factory()->forTenant($tenant)->webpage('https://example.com/x', 'https://example.com/x')->create();

        $this->actingAs($user)->delete("/knowledge/{$item->id}", ['blocklist' => true]);

        $this->assertDatabaseHas('crawl_url_blocklist', [
            'tenant_id' => $tenant->id,
            'url_normalized' => 'https://example.com/x',
        ]);
    }

    public function test_destroying_webpage_item_skips_blocklist_when_not_confirmed(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);
        $item = KnowledgeItem::factory()->forTenant($tenant)->webpage('https://example.com/y', 'https://example.com/y')->create();

        $this->actingAs($user)->delete("/knowledge/{$item->id}", ['blocklist' => false]);

        $this->assertDatabaseMissing('crawl_url_blocklist', [
            'tenant_id' => $tenant->id,
            'url_normalized' => 'https://example.com/y',
        ]);
    }

    public function test_destroying_non_webpage_does_not_touch_blocklist(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);
        $item = KnowledgeItem::factory()->forTenant($tenant)->create(['type' => 'text']);

        $this->actingAs($user)->delete("/knowledge/{$item->id}", ['blocklist' => true]);

        $this->assertSame(0, CrawlUrlBlocklist::forTenant($tenant)->count());
    }
}
