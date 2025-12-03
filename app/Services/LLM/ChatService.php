<?php

declare(strict_types=1);

namespace App\Services\LLM;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\UsageRecord;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class ChatService
{
    private Provider $provider;

    private string $model;

    public function __construct()
    {
        $providerName = config('app.env') === 'production' ? 'groq' : 'ollama';

        $this->provider = match ($providerName) {
            'groq' => Provider::Groq,
            'ollama' => Provider::Ollama,
            default => Provider::Ollama,
        };

        $this->model = match ($providerName) {
            'groq' => (string) env('GROQ_MODEL', 'llama-3.1-8b-instant'),
            'ollama' => (string) env('OLLAMA_MODEL', 'gemma3:4b'),
            default => 'gemma3:4b',
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    public function generateResponse(
        Conversation $conversation,
        string $userMessage,
        array $context = []
    ): string {
        /** @var Tenant $tenant */
        $tenant = $conversation->tenant;

        Log::debug('[LLM] (IS $) Generating response', [
            'conversation_id' => $conversation->id,
            'tenant_id' => $tenant->id,
            'provider' => $this->provider->value,
            'model' => $this->model,
        ]);

        $systemPrompt = $this->buildSystemPrompt($tenant, $context, $conversation);
        $messages = $this->buildMessageHistory($conversation);

        // Add current user message to the messages array
        $messages[] = new UserMessage($userMessage);

        try {
            $response = Prism::text()
                ->using($this->provider, $this->model)
                ->withSystemPrompt($systemPrompt)
                ->withMessages($messages)
                ->withClientOptions(['timeout' => 60])
                ->asText();

            // Track token usage
            $this->trackUsage($tenant, $conversation, $response->usage);

            Log::debug('[LLM] (IS $) Response generated', [
                'conversation_id' => $conversation->id,
                'tokens' => $response->usage?->totalTokens ?? 0,
            ]);

            return $response->text;
        } catch (\Exception $e) {
            Log::error('[LLM] Response generation failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return $this->getFallbackResponse();
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function streamResponse(
        Conversation $conversation,
        string $userMessage,
        array $context = []
    ): \Generator {
        /** @var Tenant $tenant */
        $tenant = $conversation->tenant;
        $systemPrompt = $this->buildSystemPrompt($tenant, $context, $conversation);
        $messages = $this->buildMessageHistory($conversation);

        Log::debug('[LLM] (IS $) Starting stream', [
            'conversation_id' => $conversation->id,
            'provider' => $this->provider->value,
        ]);

        // Add current user message to the messages array
        $messages[] = new UserMessage($userMessage);

        try {
            $stream = Prism::text()
                ->using($this->provider, $this->model)
                ->withSystemPrompt($systemPrompt)
                ->withMessages($messages)
                ->withClientOptions(['timeout' => 60])
                ->asStream();

            $totalTokens = 0;
            $fullResponse = '';

            foreach ($stream as $event) {
                // Only process text chunks, skip start/end events
                if (property_exists($event, 'text') && $event->text !== null) {
                    $fullResponse .= $event->text;
                    yield $event->text;
                }

                if (property_exists($event, 'usage') && $event->usage) {
                    $totalTokens = $event->usage->totalTokens ?? 0;
                }
            }

            // Track usage after stream completes
            if ($totalTokens > 0) {
                UsageRecord::create([
                    'tenant_id' => $tenant->id,
                    'type' => 'tokens',
                    'quantity' => $totalTokens,
                    'recorded_date' => now()->toDateString(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('[LLM] Stream failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            yield $this->getFallbackResponse();
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildSystemPrompt(Tenant $tenant, array $context, ?Conversation $conversation = null): string
    {
        $companyName = $tenant->name;
        $botType = $tenant->bot_type ?? 'hybrid';
        $botTone = $tenant->bot_tone ?? 'friendly';
        $customInstructions = $tenant->bot_custom_instructions;

        // Check conversation state
        $leadCaptured = $conversation?->lead_id !== null;
        $contactRequested = false;

        if ($conversation) {
            $assistantMessages = $conversation->messages()
                ->where('role', 'assistant')
                ->pluck('content')
                ->implode(' ');
            $contactRequested = preg_match('/email|phone|contact.*info|reach.*out|get.*back/i', $assistantMessages);
        }

        // Build base prompt based on bot type
        $basePrompt = $this->getBotTypePrompt($botType, $companyName);

        // Add tone modifier
        $basePrompt .= "\n\n".$this->getToneModifier($botTone);

        // Add custom instructions if provided
        if (! empty($customInstructions)) {
            $basePrompt .= "\n\nADDITIONAL INSTRUCTIONS:\n{$customInstructions}";
        }

        // Add lead capture instructions based on bot type and state
        if ($botType === 'sales' || $botType === 'hybrid') {
            if ($leadCaptured) {
                $basePrompt .= <<<'PROMPT'


CONTACT INFO ALREADY COLLECTED:
The user has already provided their contact details. Do NOT ask for email/phone again.
Just continue helping them and confirm our team will be in touch soon.
PROMPT;
            } elseif ($contactRequested) {
                $basePrompt .= <<<'PROMPT'


ALREADY ASKED FOR CONTACT INFO:
You've already asked for their contact details. Do NOT ask again.
- If they provide it now, thank them and confirm follow-up
- If they ask something else, just answer helpfully
- Only gently remind once if conversation continues without them providing it
PROMPT;
            } else {
                $basePrompt .= <<<'PROMPT'


LEAD CAPTURE:
When user shows buying interest (meeting, demo, quote, get started, pricing):
- Ask for their name and email so the team can follow up
- Only ask ONCE, don't repeat
PROMPT;
            }
        }

        $basePrompt .= <<<'PROMPT'


STRICT RULES:
- ONLY use information from "Relevant Information" below
- If info unavailable, offer to connect with the team
- NEVER use placeholders like [Insert X] or make up data
PROMPT;

        if (! empty($context['knowledge']) && is_array($context['knowledge'])) {
            $knowledgeContext = implode("\n\n", $context['knowledge']);
            $basePrompt .= "\n\n## Relevant Information:\n{$knowledgeContext}";
        }

        return $basePrompt;
    }

    private function getBotTypePrompt(string $botType, string $companyName): string
    {
        return match ($botType) {
            'support' => <<<PROMPT
You are a helpful customer support assistant for {$companyName}.

Your Role:
- Focus on answering questions and solving problems
- Provide helpful, accurate information
- Do NOT push sales or promotions
- Empathize with customer issues
- Offer to escalate to human support when needed
PROMPT,
            'sales' => <<<PROMPT
You are a friendly sales assistant for {$companyName}.

Your Role:
- Proactively engage visitors and understand their needs
- Highlight benefits and value propositions
- Qualify leads by understanding their requirements
- Encourage conversions and next steps
- Create urgency when appropriate
- Always be helpful, never pushy
PROMPT,
            'information' => <<<PROMPT
You are an information assistant for {$companyName}.

Your Role:
- Provide neutral, factual responses
- Answer questions accurately and concisely
- Do NOT apply sales pressure or push conversions
- Be objective and informative
- Direct users to appropriate resources
PROMPT,
            default => <<<PROMPT
You are a versatile assistant for {$companyName}.

Your Role:
- Dynamically adapt based on user intent
- When users have questions or issues: focus on support and help
- When users show buying interest: engage as a sales assistant
- Be helpful, informative, and responsive to their needs
PROMPT,
        };
    }

    private function getToneModifier(string $tone): string
    {
        return match ($tone) {
            'formal' => <<<'PROMPT'
Communication Style:
- Use professional, polished language
- Maintain respectful distance
- Proper grammar and complete sentences
- Avoid slang, contractions, and casual expressions
PROMPT,
            'casual' => <<<'PROMPT'
Communication Style:
- Very relaxed and peer-like
- Use contractions and casual expressions freely
- Can use appropriate emojis sparingly
- Short, punchy responses
- Like talking to a friend
PROMPT,
            default => <<<'PROMPT'
Communication Style:
- Warm, conversational, and approachable
- Natural language with some contractions
- Keep responses concise (2-4 sentences)
- Friendly but professional
PROMPT,
        };
    }

    /**
     * @return array<int, UserMessage|AssistantMessage>
     */
    private function buildMessageHistory(Conversation $conversation): array
    {
        $messages = $conversation->messages()
            ->orderBy('created_at', 'asc')
            ->limit(20) // Keep context window manageable
            ->get();

        return $messages->map(function (Message $message) {
            if ($message->role === 'user') {
                return new UserMessage($message->content);
            }

            return new AssistantMessage($message->content);
        })->toArray();
    }

    private function trackUsage(Tenant $tenant, Conversation $conversation, mixed $usage): void
    {
        if (! $usage || ! is_object($usage)) {
            return;
        }

        $totalTokens = property_exists($usage, 'totalTokens') ? (int) $usage->totalTokens : 0;
        $promptTokens = property_exists($usage, 'promptTokens') ? (int) $usage->promptTokens : 0;
        $completionTokens = property_exists($usage, 'completionTokens') ? (int) $usage->completionTokens : 0;

        UsageRecord::create([
            'tenant_id' => $tenant->id,
            'type' => 'tokens',
            'quantity' => $totalTokens,
            'recorded_date' => now()->toDateString(),
        ]);

        Log::debug('[Usage] (NO $) Tokens tracked', [
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
        ]);
    }

    private function getFallbackResponse(): string
    {
        return "I apologize, but I'm having trouble processing your request right now. Please try again in a moment, or contact our support team for immediate assistance.";
    }
}
