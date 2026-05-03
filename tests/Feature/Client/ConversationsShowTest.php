<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ConversationsShowTest extends TestCase
{
    public function test_show_renders_conversation_with_messages_in_order(): void
    {
        $this->actingAsTenantUser();
        $conv = Conversation::factory()->for($this->tenant)->create();
        Message::factory()->for($conv)->create([
            'content' => 'first', 'created_at' => now()->subMinutes(5),
        ]);
        Message::factory()->for($conv)->fromAssistant()->create([
            'content' => 'second', 'created_at' => now()->subMinutes(4),
        ]);

        $this->get("/conversations/{$conv->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Client/Conversations/Show', false)
                ->where('conversation.id', $conv->id)
                ->has('conversation.messages', 2)
                ->where('conversation.messages.0.content', 'first')
                ->where('conversation.messages.1.content', 'second')
            );
    }

    public function test_show_includes_lead_when_linked(): void
    {
        $this->actingAsTenantUser();
        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sam', 'email' => 'sam@example.com', 'score' => 75,
        ]);
        $conv = Conversation::factory()->for($this->tenant)->create(['lead_id' => $lead->id]);

        $this->get("/conversations/{$conv->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('conversation.lead.name', 'Sam')
                ->where('conversation.lead.email', 'sam@example.com')
            );
    }

    public function test_show_lead_is_null_when_not_linked(): void
    {
        $this->actingAsTenantUser();
        $conv = Conversation::factory()->for($this->tenant)->create();

        $this->get("/conversations/{$conv->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('conversation.lead', null));
    }

    public function test_show_404s_on_other_tenants_conversation(): void
    {
        $this->actingAsTenantUser();
        $other = Tenant::factory()->create();
        $conv = Conversation::factory()->for($other)->create();

        $this->get("/conversations/{$conv->id}")->assertNotFound();
    }
}
