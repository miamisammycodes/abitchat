<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Leads;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Services\Leads\LeadScoring;
use Tests\TestCase;

class LeadScoringTest extends TestCase
{
    private LeadScoring $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LeadScoring;
        $this->createTenantWithUser();
    }

    private function makeLead(array $attrs = [], ?Conversation $conversation = null): Lead
    {
        return Lead::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $conversation?->id,
            'status' => 'new',
            'source' => 'widget',
        ], $attrs));
    }

    private function makeConversationWithMessages(array $userMessages): Conversation
    {
        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'session_id' => 'test-session-'.uniqid(),
            'status' => 'active',
        ]);

        foreach ($userMessages as $content) {
            Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => $content,
            ]);
        }

        return $conversation;
    }

    /* ---------- Contact-info signals ---------- */

    public function test_score_is_zero_for_lead_with_no_signals(): void
    {
        $lead = $this->makeLead();

        $this->assertSame(0, $this->service->score($lead));
    }

    public function test_email_adds_twenty_points(): void
    {
        $lead = $this->makeLead(['email' => 'visitor@example.com']);

        $this->assertSame(20, $this->service->score($lead));
    }

    public function test_phone_adds_fifteen_points(): void
    {
        $lead = $this->makeLead(['phone' => '+1234567890']);

        $this->assertSame(15, $this->service->score($lead));
    }

    public function test_name_adds_ten_points(): void
    {
        $lead = $this->makeLead(['name' => 'Visitor Name']);

        $this->assertSame(10, $this->service->score($lead));
    }

    public function test_company_adds_ten_points(): void
    {
        $lead = $this->makeLead(['company' => 'Acme Inc']);

        $this->assertSame(10, $this->service->score($lead));
    }

    public function test_full_contact_info_combines_signals(): void
    {
        $lead = $this->makeLead([
            'name' => 'Visitor Name',
            'email' => 'visitor@example.com',
            'phone' => '+1234567890',
            'company' => 'Acme',
        ]);

        // 20 + 15 + 10 + 10 = 55
        $this->assertSame(55, $this->service->score($lead));
    }

    /* ---------- Intent signals ---------- */

    public function test_pricing_keyword_adds_twenty_five_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'What is the pricing for your service?',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 25 (pricing) + 2 (one user message) = 27
        $this->assertSame(27, $this->service->score($lead, $conversation));
    }

    public function test_demo_keyword_adds_thirty_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'Can I book a demo?',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 30 (demo) + 2 (one user message) = 32
        $this->assertSame(32, $this->service->score($lead, $conversation));
    }

    public function test_timeline_keyword_adds_twenty_five_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'I need this asap, please.',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 25 (timeline) + 2 = 27
        $this->assertSame(27, $this->service->score($lead, $conversation));
    }

    public function test_competitor_keyword_adds_twenty_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'How do you compare versus your competitor?',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 20 (competitor) + 2 = 22
        $this->assertSame(22, $this->service->score($lead, $conversation));
    }

    public function test_contact_keyword_adds_ten_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'Please reach out to me directly.',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 10 (contact) + 2 = 12
        $this->assertSame(12, $this->service->score($lead, $conversation));
    }

    public function test_purchase_keyword_adds_fifteen_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'Where do I sign up to buy this?',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 'sign up' AND 'buy' both in purchase dictionary, but signal fires once.
        // 15 (purchase) + 2 = 17
        $this->assertSame(17, $this->service->score($lead, $conversation));
    }

    public function test_negative_sentiment_subtracts_ten_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'This is terrible service.',
        ]);
        $lead = $this->makeLead(['email' => 'visitor@example.com'], $conversation);

        // 20 (email) + 2 (one message) - 10 (negative) = 12
        $this->assertSame(12, $this->service->score($lead, $conversation));
    }

    /* ---------- Engagement signals ---------- */

    public function test_each_user_message_adds_two_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'one', 'two', 'three',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 3 messages × 2 = 6, no other signals fire
        $this->assertSame(6, $this->service->score($lead, $conversation));
    }

    public function test_long_conversation_kicks_in_at_five_messages(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'one', 'two', 'three', 'four', 'five',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 5 × 2 = 10 + 5 (long_conversation) = 15
        $this->assertSame(15, $this->service->score($lead, $conversation));
    }

    public function test_long_conversation_does_not_kick_in_below_five_messages(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'one', 'two', 'three', 'four',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 4 × 2 = 8, no long_conversation bonus
        $this->assertSame(8, $this->service->score($lead, $conversation));
    }

    public function test_return_visitor_adds_ten_when_lead_has_multiple_conversations(): void
    {
        $firstConvo = $this->makeConversationWithMessages(['hi']);
        $lead = $this->makeLead([], $firstConvo);

        // Second conversation for the same lead.
        $secondConvo = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'session_id' => 'second-'.uniqid(),
            'status' => 'active',
            'lead_id' => $lead->id,
        ]);

        // Also link first conversation back to lead (post-capture linkage).
        $firstConvo->update(['lead_id' => $lead->id]);

        // Scoring against the second conversation: 0 messages × 2 = 0, plus
        // return_visitor (2 linked conversations) = 10.
        $this->assertSame(10, $this->service->score($lead, $secondConvo));
    }

    public function test_return_visitor_does_not_fire_for_single_conversation(): void
    {
        $conversation = $this->makeConversationWithMessages(['hi']);
        $lead = $this->makeLead([], $conversation);
        $conversation->update(['lead_id' => $lead->id]);

        // 1 × 2 = 2, no return_visitor
        $this->assertSame(2, $this->service->score($lead, $conversation));
    }

    /* ---------- Conversation-source fallback ---------- */

    public function test_score_falls_back_to_lead_conversation_when_argument_is_null(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'What is the pricing?',
        ]);
        $lead = $this->makeLead([], $conversation);

        // Same result whether $conversation is passed explicitly or omitted.
        $explicit = $this->service->score($lead, $conversation);
        $fallback = $this->service->score($lead);

        $this->assertSame($explicit, $fallback);
    }

    public function test_score_works_when_lead_has_no_conversation_relation(): void
    {
        $lead = $this->makeLead(['email' => 'visitor@example.com']);

        // No conversation passed, none on the lead — should only count contact signals.
        $this->assertSame(20, $this->service->score($lead));
    }

    /* ---------- Cross-cutting behaviour ---------- */

    public function test_only_user_messages_contribute(): void
    {
        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'session_id' => 'assistant-only',
            'status' => 'active',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Our pricing starts at $29/month with a 14-day trial.',
        ]);

        $lead = $this->makeLead([], $conversation);

        $this->assertSame(0, $this->service->score($lead, $conversation));
    }

    public function test_keyword_matching_is_case_insensitive(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'WHAT IS THE PRICING?',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 25 (pricing) + 2 = 27
        $this->assertSame(27, $this->service->score($lead, $conversation));
    }

    public function test_score_is_clamped_to_zero_minimum(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'Your service is awful and I hate it.',
        ]);
        $lead = $this->makeLead([], $conversation);

        // 2 (message_sent) - 10 (negative) = -8 → clamped to 0
        $this->assertSame(0, $this->service->score($lead, $conversation));
    }

    public function test_score_is_clamped_to_one_hundred_maximum(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'What is the pricing? Can I book a demo? I need this asap. '.
            'How does it compare versus the competitor? Can you reach out? '.
            'I want to buy this now.',
            'one', 'two', 'three', 'four', 'five', 'six',
        ]);
        $lead = $this->makeLead([
            'name' => 'Visitor',
            'email' => 'visitor@example.com',
            'phone' => '+1234567890',
            'company' => 'Acme',
        ], $conversation);

        // Total well over 100; should clamp.
        $this->assertSame(100, $this->service->score($lead, $conversation));
    }

    /* ---------- Temperature ---------- */

    public function test_temperature_thresholds(): void
    {
        $this->assertSame('cold', $this->service->temperature(0));
        $this->assertSame('cold', $this->service->temperature(30));
        $this->assertSame('warm', $this->service->temperature(31));
        $this->assertSame('warm', $this->service->temperature(60));
        $this->assertSame('hot', $this->service->temperature(61));
        $this->assertSame('hot', $this->service->temperature(100));
    }
}
