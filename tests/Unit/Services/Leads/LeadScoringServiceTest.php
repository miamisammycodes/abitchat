<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Leads;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Services\Leads\LeadScoringService;
use Tests\TestCase;

class LeadScoringServiceTest extends TestCase
{
    private LeadScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LeadScoringService();
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
            'session_id' => 'test-session-' . uniqid(),
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

    public function test_score_is_zero_for_lead_with_no_signals(): void
    {
        $lead = $this->makeLead();

        $this->assertSame(0, $this->service->calculateScore($lead));
    }

    public function test_email_adds_twenty_points(): void
    {
        $lead = $this->makeLead(['email' => 'visitor@example.com']);

        $this->assertSame(20, $this->service->calculateScore($lead));
    }

    public function test_phone_adds_fifteen_points(): void
    {
        $lead = $this->makeLead(['phone' => '+1234567890']);

        $this->assertSame(15, $this->service->calculateScore($lead));
    }

    public function test_name_adds_ten_points(): void
    {
        $lead = $this->makeLead(['name' => 'Visitor Name']);

        $this->assertSame(10, $this->service->calculateScore($lead));
    }

    public function test_full_contact_info_combines_signals(): void
    {
        $lead = $this->makeLead([
            'name' => 'Visitor Name',
            'email' => 'visitor@example.com',
            'phone' => '+1234567890',
        ]);

        $this->assertSame(45, $this->service->calculateScore($lead));
    }

    public function test_pricing_keyword_adds_twenty_five_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'What is the pricing for your service?',
        ]);
        $lead = $this->makeLead([], $conversation);

        $this->assertSame(25, $this->service->calculateScore($lead));
    }

    public function test_demo_keyword_adds_thirty_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'Can I book a demo?',
        ]);
        $lead = $this->makeLead([], $conversation);

        $this->assertSame(30, $this->service->calculateScore($lead));
    }

    public function test_timeline_urgency_adds_twenty_five_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'I need this asap, please.',
        ]);
        $lead = $this->makeLead([], $conversation);

        $this->assertSame(25, $this->service->calculateScore($lead));
    }

    public function test_competitor_mention_adds_twenty_points(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'How do you compare versus your competitor?',
        ]);
        $lead = $this->makeLead([], $conversation);

        // "competitor" and "versus" both match — but each signal is counted at most once.
        $this->assertSame(20, $this->service->calculateScore($lead));
    }

    public function test_high_engagement_kicks_in_above_five_user_messages(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'hello',
            'are you there',
            'I have a question',
            'one more thing',
            'and another',
            'and one more', // 6th message — triggers high_engagement
        ]);
        $lead = $this->makeLead([], $conversation);

        $this->assertSame(15, $this->service->calculateScore($lead));
    }

    public function test_high_engagement_does_not_kick_in_at_exactly_five_messages(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'one', 'two', 'three', 'four', 'five',
        ]);
        $lead = $this->makeLead([], $conversation);

        $this->assertSame(0, $this->service->calculateScore($lead));
    }

    public function test_negative_sentiment_subtracts_ten_points(): void
    {
        // Pair negative with a positive contact signal so we can observe the
        // subtraction (clamping prevents going below 0 when alone).
        $conversation = $this->makeConversationWithMessages([
            'This is terrible service.',
        ]);
        $lead = $this->makeLead(['email' => 'visitor@example.com'], $conversation);

        // 20 (email) - 10 (negative) = 10
        $this->assertSame(10, $this->service->calculateScore($lead));
    }

    public function test_score_is_clamped_to_zero_minimum(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'Your service is awful and I hate it.',
        ]);
        $lead = $this->makeLead([], $conversation);

        // -10 alone would clamp to 0
        $this->assertSame(0, $this->service->calculateScore($lead));
    }

    public function test_score_is_clamped_to_one_hundred_maximum(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'What is the pricing? Can I book a demo? I need this asap. ' .
            'How does it compare versus the competitor?',
            'one', 'two', 'three', 'four', 'five', 'six', // high engagement
        ]);
        $lead = $this->makeLead([
            'name' => 'Visitor',
            'email' => 'visitor@example.com',
            'phone' => '+1234567890',
        ], $conversation);

        // 10 + 20 + 15 + 25 + 30 + 25 + 20 + 15 = 160 → clamped to 100
        $this->assertSame(100, $this->service->calculateScore($lead));
    }

    public function test_keyword_matching_is_case_insensitive(): void
    {
        $conversation = $this->makeConversationWithMessages([
            'WHAT IS THE PRICING?',
        ]);
        $lead = $this->makeLead([], $conversation);

        $this->assertSame(25, $this->service->calculateScore($lead));
    }

    public function test_assistant_messages_do_not_contribute_to_score(): void
    {
        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'session_id' => 'assistant-only',
            'status' => 'active',
        ]);
        // Only an assistant message mentioning pricing keywords.
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Our pricing starts at $29/month with a 14-day trial.',
        ]);

        $lead = $this->makeLead([], $conversation);

        $this->assertSame(0, $this->service->calculateScore($lead));
    }

    public function test_get_temperature_thresholds(): void
    {
        $this->assertSame('cold', $this->service->getTemperature(0));
        $this->assertSame('cold', $this->service->getTemperature(30));
        $this->assertSame('warm', $this->service->getTemperature(31));
        $this->assertSame('warm', $this->service->getTemperature(60));
        $this->assertSame('hot', $this->service->getTemperature(61));
        $this->assertSame('hot', $this->service->getTemperature(100));
    }

    public function test_update_lead_score_persists_calculated_score(): void
    {
        $lead = $this->makeLead([
            'name' => 'Visitor',
            'email' => 'visitor@example.com',
        ]);

        $updated = $this->service->updateLeadScore($lead);

        $this->assertSame(30, $updated->score);
        $this->assertSame(30, $lead->fresh()->score);
    }
}
