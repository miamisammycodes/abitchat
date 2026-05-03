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

    public function test_archive_flips_status_to_archived(): void
    {
        $this->actingAsTenantUser();
        $conv = Conversation::factory()->for($this->tenant)->create(['status' => 'active']);

        $this->put("/conversations/{$conv->id}/archive")
            ->assertRedirect('/conversations');

        $this->assertSame('archived', $conv->fresh()->status);
    }

    public function test_unarchive_flips_archived_back_to_active(): void
    {
        $this->actingAsTenantUser();
        $conv = Conversation::factory()->for($this->tenant)->archived()->create();

        $this->put("/conversations/{$conv->id}/unarchive")
            ->assertRedirect('/conversations');

        $this->assertSame('active', $conv->fresh()->status);
    }

    public function test_archive_404s_on_cross_tenant(): void
    {
        $this->actingAsTenantUser();
        $other = Tenant::factory()->create();
        $conv = Conversation::factory()->for($other)->create(['status' => 'active']);

        $this->put("/conversations/{$conv->id}/archive")->assertNotFound();
        $this->assertSame('active', $conv->fresh()->status);
    }

    public function test_export_streams_txt_with_correct_filename(): void
    {
        $this->actingAsTenantUser();
        $conv = Conversation::factory()->for($this->tenant)->create();
        Message::factory()->for($conv)->create([
            'content' => 'Hi there', 'created_at' => '2026-05-03 10:32:14',
        ]);

        $response = $this->get("/conversations/{$conv->id}/export");
        $response->assertOk();
        $response->assertHeader('content-type', 'text/plain; charset=UTF-8');
        $this->assertStringContainsString(
            "attachment; filename=conversation-{$conv->id}.txt",
            $response->headers->get('content-disposition'),
        );
    }

    public function test_export_contains_visitor_and_assistant_lines_in_order(): void
    {
        $this->actingAsTenantUser();
        $conv = Conversation::factory()->for($this->tenant)->create();
        Message::factory()->for($conv)->create([
            'content' => 'What services?', 'created_at' => '2026-05-03 10:32:14',
        ]);
        Message::factory()->for($conv)->fromAssistant()->create([
            'content' => 'We build websites', 'created_at' => '2026-05-03 10:32:18',
        ]);

        $body = $this->get("/conversations/{$conv->id}/export")->streamedContent();

        $this->assertStringContainsString('[10:32:14] Visitor: What services?', $body);
        $this->assertStringContainsString('[10:32:18] Assistant: We build websites', $body);
        $this->assertLessThan(
            strpos($body, 'We build websites'),
            strpos($body, 'What services?'),
        );
    }

    public function test_export_404s_on_cross_tenant(): void
    {
        $this->actingAsTenantUser();
        $other = Tenant::factory()->create();
        $conv = Conversation::factory()->for($other)->create();

        $this->get("/conversations/{$conv->id}/export")->assertNotFound();
    }
}
