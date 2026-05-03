<?php

declare(strict_types=1);

namespace App\Services\LLM;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Usage\UsageTracker;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class ChatService
{
    private Provider $provider;

    private string $model;

    public function __construct(private readonly UsageTracker $usageTracker)
    {
        $providerName = config('app.env') === 'production' ? 'groq' : 'ollama';

        $this->provider = match ($providerName) {
            'groq' => Provider::Groq,
            'ollama' => Provider::Ollama,
            default => Provider::Ollama,
        };

        $this->model = match ($providerName) {
            'groq' => (string) config('services.groq.model', 'llama-3.1-8b-instant'),
            'ollama' => (string) config('services.ollama.model', 'gemma3:4b'),
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
            $response = retry(
                times: 3,
                callback: function (int $attempt) use ($systemPrompt, $messages, $conversation) {
                    if ($attempt > 1) {
                        Log::warning('[LLM] (IS $) Retry attempt', [
                            'conversation_id' => $conversation->id,
                            'attempt' => $attempt,
                        ]);
                    }

                    return Prism::text()
                        ->using($this->provider, $this->model)
                        ->withSystemPrompt($systemPrompt)
                        ->withMessages($messages)
                        ->withClientOptions(['timeout' => 60])
                        ->asText();
                },
                sleepMilliseconds: fn (int $attempt) => match ($attempt) {
                    1 => 1000,
                    2 => 2000,
                    default => 4000,
                },
                when: function (\Throwable $e) {
                    $message = $e->getMessage();
                    return str_contains($message, '429')
                        || str_contains($message, '500')
                        || str_contains($message, '503')
                        || str_contains($message, 'Connection')
                        || str_contains($message, 'timeout')
                        || str_contains($message, 'CURL');
                },
            );

            $this->trackUsage($tenant, $conversation, $response->usage);

            Log::debug('[LLM] (IS $) Response generated', [
                'conversation_id' => $conversation->id,
                'tokens' => $response->usage->promptTokens + $response->usage->completionTokens,
            ]);

            return $response->text;
        } catch (\Exception $e) {
            Log::error('[LLM] Response generation failed after retries', [
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

            $promptTokens = 0;
            $completionTokens = 0;
            $totalTokens = 0;
            $fullResponse = '';

            foreach ($stream as $event) {
                // Only process text chunks, skip start/end events
                if (property_exists($event, 'text') && $event->text !== null) {
                    $fullResponse .= $event->text;
                    yield $event->text;
                }

                if (property_exists($event, 'usage') && $event->usage) {
                    $usage = $event->usage;
                    if (property_exists($usage, 'promptTokens') && $usage->promptTokens) {
                        $promptTokens = (int) $usage->promptTokens;
                    }
                    if (property_exists($usage, 'completionTokens') && $usage->completionTokens) {
                        $completionTokens = (int) $usage->completionTokens;
                    }
                    if (property_exists($usage, 'totalTokens') && $usage->totalTokens) {
                        $totalTokens = (int) $usage->totalTokens;
                    }
                }
            }

            $this->usageTracker->recordTokens($tenant, $conversation, $promptTokens, $completionTokens, $totalTokens);
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
            $contactRequested = preg_match('/(?:provide|share|give).*(?:name|email|phone|contact|number)|(?:how can (?:I|we) (?:reach|contact|get (?:back to|in touch)))/i', $assistantMessages);
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
- Ask for their name and phone number so the team can follow up
- Do NOT ask for email
- Only ask ONCE, don't repeat
PROMPT;
            }
        }

        $basePrompt .= <<<'PROMPT'


STRICT RULES — YOU MUST FOLLOW THESE WITHOUT EXCEPTION:
- You are ONLY allowed to discuss topics covered in the "Relevant Information" section below
- If the user asks about ANYTHING not covered in the Relevant Information, you MUST refuse and say: "I can only help with questions about our company and services. Is there something specific about us I can help you with?"
- NEVER answer general knowledge questions, math problems, coding requests, trivia, or anything unrelated to the company
- NEVER act as a general-purpose assistant, tutor, calculator, or code generator
- NEVER use your training knowledge to answer questions — ONLY use the Relevant Information provided
- If no Relevant Information is available, say: "I don't have information about that yet. Would you like to speak with our team?"
- NEVER use placeholders like [Insert X] or make up data
- If you are unsure whether a topic is covered, err on the side of refusing
PROMPT;

        if (! empty($context['knowledge']) && is_array($context['knowledge'])) {
            $knowledgeContext = implode("\n\n", $context['knowledge']);
            $basePrompt .= "\n\n## Relevant Information:\n{$knowledgeContext}";
        } else {
            $basePrompt .= "\n\n## Relevant Information:\nNo information has been loaded yet. You cannot answer any specific questions. Only greet the user and offer to connect them with the team.";
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
            ->orderBy('created_at', 'desc')
            ->limit(20) // Keep context window manageable
            ->get()
            ->reverse()
            ->values();

        return $messages->map(function (Message $message) {
            if ($message->role === 'user') {
                return new UserMessage($message->content);
            }

            return new AssistantMessage($message->content);
        })->all();
    }

    private function trackUsage(Tenant $tenant, Conversation $conversation, mixed $usage): void
    {
        if (! $usage || ! is_object($usage)) {
            return;
        }

        $promptTokens = property_exists($usage, 'promptTokens') ? (int) $usage->promptTokens : 0;
        $completionTokens = property_exists($usage, 'completionTokens') ? (int) $usage->completionTokens : 0;
        $totalTokens = property_exists($usage, 'totalTokens') ? (int) $usage->totalTokens : 0;

        $this->usageTracker->recordTokens($tenant, $conversation, $promptTokens, $completionTokens, $totalTokens);
    }

    private function getFallbackResponse(): string
    {
        return "I apologize, but I'm having trouble processing your request right now. Please try again in a moment, or contact our support team for immediate assistance.";
    }
}
