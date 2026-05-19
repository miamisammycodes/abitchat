<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\Leads\LeadService;
use Tests\Concerns\AuthenticatesWidget;
use Tests\TestCase;

class WidgetLeadCaptureTest extends TestCase
{
    use AuthenticatesWidget;

    protected Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Lead Test Co',
            'slug' => 'lead-test-co',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'settings' => ['allowed_domains' => ['example.com']],
        ]);

        $this->conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'session_id' => 'sess-lead-test',
            'status' => 'active',
        ]);

    }

    public function test_response_does_not_disclose_whether_email_already_exists(): void
    {
        Lead::create([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'name' => 'Original',
            'email' => 'known@example.com',
            'status' => 'new',
            'score' => 0,
        ]);

        $existing = $this->withHeaders($this->widgetHeaders($this->tenant))
            ->postJson('/api/v1/widget/lead', [
                'api_key' => $this->tenant->api_key,
                'conversation_id' => $this->conversation->id,
                'email' => 'known@example.com',
                'name' => 'someone else',
            ])->assertStatus(200)->json();

        $newConversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'session_id' => 'sess-lead-test-2',
            'status' => 'active',
        ]);

        $fresh = $this->withHeaders($this->widgetHeaders($this->tenant))
            ->postJson('/api/v1/widget/lead', [
                'api_key' => $this->tenant->api_key,
                'conversation_id' => $newConversation->id,
                'email' => 'never-seen-'.uniqid().'@example.com',
                'name' => 'fresh visitor',
            ])->assertStatus(200)->json();

        $this->assertArrayNotHasKey('is_new', $existing);
        $this->assertArrayNotHasKey('is_new', $fresh);
    }

    public function test_existing_lead_fields_not_overwritten_by_widget_input(): void
    {
        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'name' => 'Original Name',
            'email' => 'protected@example.com',
            'phone' => '+1-555-1111',
            'company' => 'Original Co',
            'custom_fields' => ['role' => 'user'],
            'status' => 'new',
            'score' => 0,
        ]);

        $this->withHeaders($this->widgetHeaders($this->tenant))
            ->postJson('/api/v1/widget/lead', [
                'api_key' => $this->tenant->api_key,
                'conversation_id' => $this->conversation->id,
                'email' => 'protected@example.com',
                'name' => 'HACKED',
                'phone' => '+1-555-9999',
                'company' => 'Evil Corp',
                'custom_fields' => ['role' => 'admin', 'injected' => 'yes'],
            ])->assertStatus(200);

        $lead->refresh();
        $this->assertSame('Original Name', $lead->name);
        $this->assertSame('+1-555-1111', $lead->phone);
        $this->assertSame('Original Co', $lead->company);
        $this->assertSame(['role' => 'user'], $lead->custom_fields);
    }

    public function test_existing_lead_blank_fields_can_be_filled_by_widget(): void
    {
        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'name' => null,
            'email' => 'partial@example.com',
            'phone' => null,
            'status' => 'new',
            'score' => 0,
        ]);

        $this->withHeaders($this->widgetHeaders($this->tenant))
            ->postJson('/api/v1/widget/lead', [
                'api_key' => $this->tenant->api_key,
                'conversation_id' => $this->conversation->id,
                'email' => 'partial@example.com',
                'name' => 'Now Provided',
                'phone' => '+1-555-2222',
            ])->assertStatus(200);

        $lead->refresh();
        $this->assertSame('Now Provided', $lead->name);
        $this->assertSame('+1-555-2222', $lead->phone);
    }

    public function test_capture_from_conversation_re_reads_lead_id_under_lock(): void
    {
        $service = app(LeadService::class);

        // Stale in-memory copy of the conversation (no lead yet).
        $stale = Conversation::find($this->conversation->id);
        $this->assertNull($stale->lead_id);

        // Simulate the first concurrent request having committed a lead and
        // linked it. The stale instance still shows lead_id = null.
        $firstLead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'name' => 'First',
            'email' => 'first@example.com',
            'status' => 'new',
            'score' => 0,
        ]);
        Conversation::whereKey($this->conversation->id)->update([
            'lead_id' => $firstLead->id,
        ]);

        // Second request now calls captureFromConversation with the stale
        // conversation. With the fix, it must re-read lead_id under lock and
        // update the existing lead instead of creating a duplicate.
        $returned = $service->captureFromConversation($stale, [
            'email' => 'first@example.com',
            'name' => 'Second Attempt',
        ]);

        $this->assertNotNull($returned);
        $this->assertSame($firstLead->id, $returned->id);
        $this->assertSame(
            1,
            Lead::where('tenant_id', $this->tenant->id)->count(),
            'captureFromConversation must not create a duplicate lead on a conversation that already has one.'
        );
    }
}
