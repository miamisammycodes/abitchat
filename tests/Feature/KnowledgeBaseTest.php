<?php

namespace Tests\Feature;

use App\Models\KnowledgeItem;
use Tests\TestCase;

class KnowledgeBaseTest extends TestCase
{
    public function test_knowledge_base_requires_authentication(): void
    {
        $response = $this->get('/knowledge');

        $response->assertRedirect('/login');
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
        \Illuminate\Support\Facades\Bus::fake();

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
        \Illuminate\Support\Facades\Bus::fake();

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
        $otherTenant = \App\Models\Tenant::create([
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
