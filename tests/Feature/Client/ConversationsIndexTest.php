<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ConversationsIndexTest extends TestCase
{
    public function test_lists_only_current_tenants_conversations(): void
    {
        $this->actingAsTenantUser();
        $other = Tenant::factory()->create();

        Conversation::factory()->for($this->tenant)->count(3)->create();
        Conversation::factory()->for($other)->count(5)->create();

        $this->get('/conversations')
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Vue page lands in Task 8; skip file-existence check until then.
                ->component('Client/Conversations/Index', false)
                ->has('conversations.data', 3)
            );
    }

    public function test_paginates_at_25_per_page(): void
    {
        $this->actingAsTenantUser();
        Conversation::factory()->for($this->tenant)->count(30)->create();

        $this->get('/conversations')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('conversations.data', 25)
                ->where('conversations.per_page', 25)
                ->where('conversations.total', 30)
            );
    }

    public function test_default_filter_excludes_archived(): void
    {
        $this->actingAsTenantUser();
        Conversation::factory()->for($this->tenant)->create(['status' => 'active']);
        Conversation::factory()->for($this->tenant)->closed()->create();
        Conversation::factory()->for($this->tenant)->archived()->create();

        $this->get('/conversations')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations.data', 2));
    }

    public function test_status_all_includes_archived(): void
    {
        $this->actingAsTenantUser();
        Conversation::factory()->for($this->tenant)->create(['status' => 'active']);
        Conversation::factory()->for($this->tenant)->archived()->create();

        $this->get('/conversations?status=all')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations.data', 2));
    }

    public function test_status_archived_returns_only_archived(): void
    {
        $this->actingAsTenantUser();
        Conversation::factory()->for($this->tenant)->create(['status' => 'active']);
        Conversation::factory()->for($this->tenant)->archived()->create();

        $this->get('/conversations?status=archived')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations.data', 1));
    }

    public function test_date_range_filter(): void
    {
        $this->actingAsTenantUser();
        Carbon::setTestNow('2026-04-01 12:00:00');
        Conversation::factory()->for($this->tenant)->create();
        Carbon::setTestNow('2026-04-15 12:00:00');
        Conversation::factory()->for($this->tenant)->create();
        Carbon::setTestNow('2026-05-15 12:00:00');
        Conversation::factory()->for($this->tenant)->create();
        Carbon::setTestNow();

        $this->get('/conversations?from=2026-04-10&to=2026-05-01')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations.data', 1));
    }

    public function test_has_lead_filter_returns_only_linked(): void
    {
        $this->actingAsTenantUser();
        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sam', 'email' => 'sam@example.com',
        ]);
        Conversation::factory()->for($this->tenant)->create(['lead_id' => $lead->id]);
        Conversation::factory()->for($this->tenant)->count(2)->create();

        $this->get('/conversations?has_lead=1')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('conversations.data', 1));
    }

    public function test_index_includes_message_count_and_latest_message_preview(): void
    {
        $this->actingAsTenantUser();
        $conv = Conversation::factory()->for($this->tenant)->create();
        Message::factory()->for($conv)->count(2)->create();
        Message::factory()->for($conv)->fromAssistant()->create([
            'content' => 'Latest reply from the bot',
        ]);

        $this->get('/conversations')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('conversations.data.0.messages_count', 3)
                ->where('conversations.data.0.latest_message.content', 'Latest reply from the bot')
            );
    }

    public function test_unauthenticated_redirects_to_login(): void
    {
        $this->get('/conversations')->assertRedirect('/login');
    }
}
