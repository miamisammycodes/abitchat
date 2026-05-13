<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Jobs\ProcessKnowledgeItem;
use App\Models\Plan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class KnowledgeQueueDispatchTest extends TestCase
{
    private function makePlan(): Plan
    {
        return Plan::create([
            'name' => 'P', 'slug' => 'p-' . uniqid(),
            'price' => 0, 'billing_period' => 'monthly',
            'conversations_limit' => 100,
            'leads_limit' => 50,
            'tokens_limit' => 10000,
            'knowledge_items_limit' => 100,
            'is_active' => true,
        ]);
    }

    public function test_text_item_dispatches_process_job_after_response(): void
    {
        Bus::fake();
        $this->actingAsTenantUser();
        $plan = $this->makePlan();
        $this->tenant->update(['plan_id' => $plan->id, 'plan_expires_at' => now()->addMonth()]);

        $this->post(route('client.knowledge.store'), [
            'type' => 'text',
            'title' => 'Smoke',
            'content' => 'hello world',
        ])->assertRedirect();

        Bus::assertDispatchedAfterResponse(ProcessKnowledgeItem::class);
    }
}
