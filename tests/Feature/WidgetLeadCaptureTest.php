<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Tenant;
use Tests\TestCase;

class WidgetLeadCaptureTest extends TestCase
{
    protected Tenant $widgetTenant;

    protected Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Lead Test Co',
            'slug' => 'lead-test-co',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
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

        $existing = $this->postJson('/api/v1/widget/lead', [
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

        $fresh = $this->postJson('/api/v1/widget/lead', [
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

        $this->postJson('/api/v1/widget/lead', [
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

        $this->postJson('/api/v1/widget/lead', [
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
}
