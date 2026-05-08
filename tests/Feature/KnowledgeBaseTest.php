<?php

namespace Tests\Feature;

use App\Models\KnowledgeItem;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class KnowledgeBaseTest extends TestCase
{
    public function test_knowledge_base_requires_authentication(): void
    {
        $response = $this->get('/knowledge');

        $response->assertRedirect('/login');
    }

    public function test_create_blocked_when_knowledge_items_limit_reached(): void
    {
        $this->actingAsTenantUser();
        Bus::fake();

        $plan = Plan::create([
            'name' => 'Tiny',
            'slug' => 'tiny-knowledge',
            'description' => 'Tiny',
            'price' => 0,
            'billing_period' => 'month',
            'conversations_limit' => 100,
            'messages_per_conversation' => 50,
            'knowledge_items_limit' => 1,
            'tokens_limit' => 1000,
            'leads_limit' => 10,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $this->tenant->update([
            'plan_id' => $plan->id,
            'plan_expires_at' => now()->addDays(7),
        ]);

        KnowledgeItem::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Existing',
            'type' => 'text',
            'content' => 'x',
            'status' => 'ready',
        ]);

        $response = $this->post('/knowledge', [
            'title' => 'Should be blocked',
            'type' => 'text',
            'content' => 'y',
        ]);

        $response->assertRedirect(route('client.billing.plans'));
        $this->assertDatabaseMissing('knowledge_items', ['title' => 'Should be blocked']);
    }

    public function test_webpage_url_rejects_private_address(): void
    {
        $this->actingAsTenantUser();
        Bus::fake();

        $response = $this->post('/knowledge', [
            'title' => 'SSRF probe',
            'type' => 'webpage',
            'source_url' => 'http://169.254.169.254/latest/meta-data/',
        ]);

        $response->assertSessionHasErrors('source_url');
        $this->assertDatabaseMissing('knowledge_items', ['title' => 'SSRF probe']);
    }

    public function test_knowledge_base_index_can_be_rendered(): void
    {
        $this->actingAsTenantUser();

        $response = $this->get('/knowledge');

        $response->assertStatus(200);
    }

    public function test_user_can_create_knowledge_item(): void
    {
        $this->actingAsTenantUser();

        // Mock the job dispatch to avoid tenant issues in tests
        Bus::fake();

        $response = $this->post('/knowledge', [
            'title' => 'Test Text Item',
            'type' => 'text',
            'content' => 'This is a test text content.',
        ]);

        $this->assertDatabaseHas('knowledge_items', [
            'title' => 'Test Text Item',
            'type' => 'text',
            'tenant_id' => $this->tenant->id,
        ]);

        $response->assertRedirect(route('client.knowledge.index'));
    }

    public function test_user_can_view_knowledge_item(): void
    {
        $this->actingAsTenantUser();

        $item = KnowledgeItem::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Test Item',
            'type' => 'text',
            'content' => 'Test content',
            'status' => 'ready',
        ]);

        $response = $this->get("/knowledge/{$item->id}");

        $response->assertStatus(200);
    }

    public function test_user_can_update_knowledge_item(): void
    {
        $this->actingAsTenantUser();

        // Mock the job dispatch to avoid tenant issues in tests
        Bus::fake();

        $item = KnowledgeItem::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Original Title',
            'type' => 'text',
            'content' => 'Original content',
            'status' => 'ready',
        ]);

        $response = $this->put("/knowledge/{$item->id}", [
            'title' => 'Updated Title',
            'content' => 'Updated content',
        ]);

        $this->assertDatabaseHas('knowledge_items', [
            'id' => $item->id,
            'title' => 'Updated Title',
            'content' => 'Updated content',
        ]);

        $response->assertRedirect(route('client.knowledge.index'));
    }

    public function test_user_can_delete_knowledge_item(): void
    {
        $this->actingAsTenantUser();

        $item = KnowledgeItem::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'To Delete',
            'type' => 'text',
            'content' => 'This will be deleted',
            'status' => 'ready',
        ]);

        $response = $this->delete("/knowledge/{$item->id}");

        $this->assertDatabaseMissing('knowledge_items', [
            'id' => $item->id,
        ]);

        $response->assertRedirect(route('client.knowledge.index'));
    }

    public function test_user_cannot_access_other_tenants_knowledge(): void
    {
        $this->actingAsTenantUser();

        // Create another tenant with knowledge item
        $otherTenant = Tenant::create([
            'name' => 'Other Company',
            'slug' => 'other-company',
            'status' => 'active',
        ]);

        $otherItem = KnowledgeItem::create([
            'tenant_id' => $otherTenant->id,
            'title' => 'Other Tenant Item',
            'type' => 'text',
            'content' => 'This belongs to another tenant',
            'status' => 'ready',
        ]);

        $response = $this->get("/knowledge/{$otherItem->id}");

        // Returns 403 (Forbidden) when trying to access other tenant's data
        $response->assertStatus(403);
    }

    public function test_knowledge_item_requires_title(): void
    {
        $this->actingAsTenantUser();

        $response = $this->post('/knowledge', [
            'type' => 'text',
            'content' => 'Content without title',
        ]);

        $response->assertSessionHasErrors('title');
    }

    public function test_knowledge_item_requires_valid_type(): void
    {
        $this->actingAsTenantUser();

        $response = $this->post('/knowledge', [
            'title' => 'Test',
            'type' => 'invalid_type',
            'content' => 'Content',
        ]);

        $response->assertSessionHasErrors('type');
    }
}
