<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use App\Models\KnowledgeItem;
use Tests\TestCase;

class EnsureNotExpiredTest extends TestCase
{
    public function test_expired_tenant_cannot_delete_knowledge(): void
    {
        $free = $this->createFreePlan();
        $this->actingAsSetupTenant();
        $this->tenant->update(['plan_id' => $free->id, 'plan_expires_at' => now()->subDay()]);

        $item = KnowledgeItem::factory()->forTenant($this->tenant)->create();

        $this->delete(route('client.knowledge.destroy', $item))
            ->assertRedirect(route('client.billing.plans'));

        $this->assertDatabaseHas('knowledge_items', ['id' => $item->id]); // NOT deleted
    }

    public function test_setup_tenant_can_delete_knowledge(): void
    {
        $this->createFreePlan();
        $this->actingAsSetupTenant();

        $item = KnowledgeItem::factory()->forTenant($this->tenant)->create();

        $response = $this->delete(route('client.knowledge.destroy', $item));
        // Setup is allowed to delete: NOT redirected to billing, and the row is gone.
        $this->assertNotSame(route('client.billing.plans'), $response->headers->get('Location'));
        $this->assertDatabaseMissing('knowledge_items', ['id' => $item->id]);
    }
}
