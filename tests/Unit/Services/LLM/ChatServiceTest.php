<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LLM;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\LLM\ChatService;
use App\Services\Usage\UsageTracker;
use ReflectionClass;
use Tests\TestCase;

/**
 * Covers the system-prompt builder. We invoke the private buildSystemPrompt
 * via reflection so we can assert on the prompt text directly without
 * standing up Prism. Anything that lands in the prompt is what the LLM
 * actually sees, so prompt regressions are exactly what we want to catch.
 */
class ChatServiceTest extends TestCase
{
    private ChatService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ChatService(app(UsageTracker::class));
        $this->createTenantWithUser();
    }

    private function buildPrompt(Tenant $tenant, array $context = [], ?Conversation $conversation = null): string
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('buildSystemPrompt');
        $method->setAccessible(true);

        return (string) $method->invoke($this->service, $tenant, $context, $conversation);
    }

    private function configureTenant(array $attrs): Tenant
    {
        $this->tenant->update($attrs);

        return $this->tenant->fresh();
    }

    private function makeConversation(array $assistantMessages = [], ?int $leadId = null): Conversation
    {
        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $leadId,
            'session_id' => 'sess-' . uniqid(),
            'status' => 'active',
        ]);

        foreach ($assistantMessages as $content) {
            Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $content,
            ]);
        }

        return $conversation;
    }

    public function test_includes_company_name_in_prompt(): void
    {
        $tenant = $this->configureTenant(['name' => 'Acme Widgets', 'bot_type' => 'support']);

        $prompt = $this->buildPrompt($tenant);

        $this->assertStringContainsString('Acme Widgets', $prompt);
    }

    public function test_support_bot_uses_support_role_text(): void
    {
        $tenant = $this->configureTenant(['bot_type' => 'support']);

        $prompt = $this->buildPrompt($tenant);

        $this->assertStringContainsString('customer support assistant', $prompt);
        $this->assertStringNotContainsString('sales assistant', $prompt);
    }

    public function test_sales_bot_uses_sales_role_text(): void
    {
        $tenant = $this->configureTenant(['bot_type' => 'sales']);

        $prompt = $this->buildPrompt($tenant);

        $this->assertStringContainsString('sales assistant', $prompt);
    }

    public function test_information_bot_uses_information_role_text(): void
    {
        $tenant = $this->configureTenant(['bot_type' => 'information']);

        $prompt = $this->buildPrompt($tenant);

        $this->assertStringContainsString('information assistant', $prompt);
        $this->assertStringNotContainsString('sales assistant', $prompt);
    }

    public function test_unknown_bot_type_falls_back_to_versatile_assistant(): void
    {
        $tenant = $this->configureTenant(['bot_type' => 'hybrid']);

        $prompt = $this->buildPrompt($tenant);

        $this->assertStringContainsString('versatile assistant', $prompt);
    }

    public function test_formal_tone_modifier_is_included(): void
    {
        $tenant = $this->configureTenant(['bot_type' => 'support', 'bot_tone' => 'formal']);

        $prompt = $this->buildPrompt($tenant);

        $this->assertStringContainsString('professional, polished language', $prompt);
    }

    public function test_casual_tone_modifier_is_included(): void
    {
        $tenant = $this->configureTenant(['bot_type' => 'support', 'bot_tone' => 'casual']);

        $prompt = $this->buildPrompt($tenant);

        $this->assertStringContainsString('relaxed and peer-like', $prompt);
    }

    public function test_friendly_tone_is_default(): void
    {
        // bot_tone is NOT NULL in the DB, so simulate a "missing" value with
        // an unsaved Tenant to exercise the ?? 'friendly' fallback in the
        // service. buildSystemPrompt just reads attributes off the model.
        $tenant = new Tenant();
        $tenant->name = 'Test Co';
        $tenant->bot_type = 'support';
        $tenant->bot_tone = null;

        $prompt = $this->buildPrompt($tenant);

        $this->assertStringContainsString('Warm, conversational', $prompt);
    }

    public function test_custom_instructions_are_appended_when_present(): void
    {
        $tenant = $this->configureTenant([
            'bot_type' => 'support',
            'bot_custom_instructions' => 'Always greet visitors in Dzongkha first.',
        ]);

        $prompt = $this->buildPrompt($tenant);

        $this->assertStringContainsString('ADDITIONAL INSTRUCTIONS:', $prompt);
        $this->assertStringContainsString('Always greet visitors in Dzongkha first.', $prompt);
    }

    public function test_custom_instructions_section_omitted_when_empty(): void
    {
        $tenant = $this->configureTenant([
            'bot_type' => 'support',
            'bot_custom_instructions' => null,
        ]);

        $prompt = $this->buildPrompt($tenant);

        $this->assertStringNotContainsString('ADDITIONAL INSTRUCTIONS:', $prompt);
    }

    public function test_sales_bot_includes_lead_capture_block_when_no_lead_yet(): void
    {
        $tenant = $this->configureTenant(['bot_type' => 'sales']);
        $conversation = $this->makeConversation();

        $prompt = $this->buildPrompt($tenant, [], $conversation);

        $this->assertStringContainsString('LEAD CAPTURE:', $prompt);
        $this->assertStringContainsString('name and phone number', $prompt);
    }

    public function test_hybrid_bot_includes_lead_capture_block_when_no_lead_yet(): void
    {
        $tenant = $this->configureTenant(['bot_type' => 'hybrid']);
        $conversation = $this->makeConversation();

        $prompt = $this->buildPrompt($tenant, [], $conversation);

        $this->assertStringContainsString('LEAD CAPTURE:', $prompt);
    }

    public function test_support_bot_omits_lead_capture_block(): void
    {
        $tenant = $this->configureTenant(['bot_type' => 'support']);
        $conversation = $this->makeConversation();

        $prompt = $this->buildPrompt($tenant, [], $conversation);

        $this->assertStringNotContainsString('LEAD CAPTURE:', $prompt);
        $this->assertStringNotContainsString('CONTACT INFO ALREADY COLLECTED', $prompt);
    }

    public function test_information_bot_omits_lead_capture_block(): void
    {
        $tenant = $this->configureTenant(['bot_type' => 'information']);
        $conversation = $this->makeConversation();

        $prompt = $this->buildPrompt($tenant, [], $conversation);

        $this->assertStringNotContainsString('LEAD CAPTURE:', $prompt);
    }

    public function test_lead_already_captured_switches_block_to_already_collected(): void
    {
        $tenant = $this->configureTenant(['bot_type' => 'sales']);
        $lead = Lead::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Already Captured Visitor',
            'email' => 'already@example.com',
            'status' => 'new',
            'source' => 'widget',
        ]);
        $conversation = $this->makeConversation([], $lead->id);

        $prompt = $this->buildPrompt($tenant, [], $conversation);

        $this->assertStringContainsString('CONTACT INFO ALREADY COLLECTED:', $prompt);
        $this->assertStringNotContainsString('LEAD CAPTURE:', $prompt);
    }

    public function test_assistant_having_already_asked_switches_block_to_already_asked(): void
    {
        $tenant = $this->configureTenant(['bot_type' => 'sales']);
        $conversation = $this->makeConversation([
            'Could you provide your phone number so we can follow up?',
        ]);

        $prompt = $this->buildPrompt($tenant, [], $conversation);

        $this->assertStringContainsString('ALREADY ASKED FOR CONTACT INFO:', $prompt);
        $this->assertStringNotContainsString('LEAD CAPTURE:', $prompt);
    }

    public function test_strict_rules_block_is_always_present(): void
    {
        $tenant = $this->configureTenant(['bot_type' => 'support']);

        $prompt = $this->buildPrompt($tenant);

        $this->assertStringContainsString('STRICT RULES', $prompt);
        $this->assertStringContainsString('ONLY use the Relevant Information', $prompt);
    }

    public function test_knowledge_context_is_injected_when_provided(): void
    {
        $tenant = $this->configureTenant(['bot_type' => 'support']);

        $prompt = $this->buildPrompt($tenant, [
            'knowledge' => [
                'Refund window is 14 days.',
                'Contact support@example.com for help.',
            ],
        ]);

        $this->assertStringContainsString('## Relevant Information:', $prompt);
        $this->assertStringContainsString('Refund window is 14 days.', $prompt);
        $this->assertStringContainsString('Contact support@example.com for help.', $prompt);
    }

    public function test_no_knowledge_loaded_message_when_context_empty(): void
    {
        $tenant = $this->configureTenant(['bot_type' => 'support']);

        $prompt = $this->buildPrompt($tenant, []);

        $this->assertStringContainsString('No information has been loaded yet', $prompt);
    }

    public function test_no_knowledge_loaded_message_when_knowledge_array_empty(): void
    {
        $tenant = $this->configureTenant(['bot_type' => 'support']);

        $prompt = $this->buildPrompt($tenant, ['knowledge' => []]);

        $this->assertStringContainsString('No information has been loaded yet', $prompt);
    }

    public function test_defaults_to_hybrid_bot_when_bot_type_is_null(): void
    {
        // bot_type is NOT NULL in the DB; this exercises the ?? 'hybrid'
        // defensive fallback in the service against an unsaved Tenant.
        $tenant = new Tenant();
        $tenant->name = 'Test Co';
        $tenant->bot_type = null;
        $tenant->bot_tone = 'friendly';

        $prompt = $this->buildPrompt($tenant);

        $this->assertStringContainsString('versatile assistant', $prompt);
    }
}
