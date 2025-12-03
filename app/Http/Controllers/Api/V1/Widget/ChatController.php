<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Widget;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Knowledge\RetrievalService;
use App\Services\Leads\LeadService;
use App\Services\LLM\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(
        private ChatService $chatService,
        private RetrievalService $retrievalService,
        private LeadService $leadService
    ) {}

    /**
     * Initialize widget and validate API key.
     */
    public function init(Request $request): JsonResponse
    {
        $request->validate([
            'api_key' => 'required|string',
        ]);

        $tenant = Tenant::where('api_key', $request->api_key)->first();

        if (! $tenant) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }

        Log::debug('[Widget] (NO $) Initialized', [
            'tenant_id' => $tenant->id,
        ]);

        return response()->json([
            'success' => true,
            'config' => [
                'name' => $tenant->name,
                'welcome_message' => $tenant->settings['welcome_message'] ?? 'Hello! How can I help you today?',
                'primary_color' => $tenant->settings['primary_color'] ?? '#4F46E5',
            ],
        ]);
    }

    /**
     * Start a new conversation.
     */
    public function startConversation(Request $request): JsonResponse
    {
        $request->validate([
            'api_key' => 'required|string',
            'session_id' => 'nullable|string|max:64',
        ]);

        $tenant = Tenant::where('api_key', $request->api_key)->first();

        if (! $tenant) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }

        $sessionId = $request->session_id ?? Str::uuid()->toString();

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'session_id' => $sessionId,
            'status' => 'active',
            'metadata' => [
                'user_agent' => $request->userAgent(),
                'ip' => $request->ip(),
                'started_at' => now()->toIso8601String(),
            ],
        ]);

        Log::debug('[Widget] (NO $) Conversation started', [
            'conversation_id' => $conversation->id,
            'tenant_id' => $tenant->id,
        ]);

        return response()->json([
            'conversation_id' => $conversation->id,
            'session_id' => $conversation->session_id,
        ]);
    }

    /**
     * Send a message and get AI response.
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'api_key' => 'required|string',
            'conversation_id' => 'required|integer',
            'message' => 'required|string|max:2000',
        ]);

        /** @var string $apiKey */
        $apiKey = $validated['api_key'];
        /** @var int $conversationId */
        $conversationId = $validated['conversation_id'];
        /** @var string $message */
        $message = $validated['message'];

        $tenant = Tenant::where('api_key', $apiKey)->first();

        if (! $tenant) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }

        $conversation = Conversation::where('id', $conversationId)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        // Store user message
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $message,
        ]);

        // Extract contact info and capture lead
        $this->captureLeadFromMessage($conversation, $message);

        // Retrieve relevant context
        $context = $this->retrievalService->retrieve($tenant, $message);

        // Generate AI response
        $response = $this->chatService->generateResponse(
            $conversation,
            $message,
            ['knowledge' => $context]
        );

        // Store assistant message
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $response,
        ]);

        Log::debug('[Widget] (NO $) Message exchanged', [
            'conversation_id' => $conversation->id,
        ]);

        return response()->json([
            'response' => $response,
        ]);
    }

    /**
     * Stream a message response using SSE.
     */
    public function streamMessage(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'api_key' => 'required|string',
            'conversation_id' => 'required|integer',
            'message' => 'required|string|max:2000',
        ]);

        /** @var string $apiKey */
        $apiKey = $validated['api_key'];
        /** @var int $conversationId */
        $conversationId = $validated['conversation_id'];
        /** @var string $message */
        $message = $validated['message'];

        $tenant = Tenant::where('api_key', $apiKey)->first();

        if (! $tenant) {
            return response()->stream(function () {
                echo 'data: '.json_encode(['error' => 'Invalid API key'])."\n\n";
            }, 401, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        $conversation = Conversation::where('id', $conversationId)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $conversation) {
            return response()->stream(function () {
                echo 'data: '.json_encode(['error' => 'Conversation not found'])."\n\n";
            }, 404, [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ]);
        }

        // Store user message
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $message,
        ]);

        // Extract contact info and capture lead
        $this->captureLeadFromMessage($conversation, $message);

        // Retrieve relevant context
        $context = $this->retrievalService->retrieve($tenant, $message);

        return response()->stream(function () use ($conversation, $message, $context) {
            $fullResponse = '';

            foreach ($this->chatService->streamResponse($conversation, $message, ['knowledge' => $context]) as $chunk) {
                $fullResponse .= (string) $chunk;
                echo 'data: '.json_encode(['chunk' => $chunk])."\n\n";
                ob_flush();
                flush();
            }

            // Store complete assistant message
            Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $fullResponse,
            ]);

            echo 'data: '.json_encode(['done' => true])."\n\n";
            ob_flush();
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * End a conversation.
     */
    public function endConversation(Request $request): JsonResponse
    {
        $request->validate([
            'api_key' => 'required|string',
            'conversation_id' => 'required|integer',
        ]);

        $tenant = Tenant::where('api_key', $request->api_key)->first();

        if (! $tenant) {
            return response()->json(['error' => 'Invalid API key'], 401);
        }

        $conversation = Conversation::where('id', $request->conversation_id)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $conversation) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        /** @var array<string, mixed> $existingMetadata */
        $existingMetadata = $conversation->metadata ?? [];
        $conversation->update([
            'status' => 'closed',
            'metadata' => array_merge($existingMetadata, [
                'closed_at' => now()->toIso8601String(),
            ]),
        ]);

        Log::debug('[Widget] (NO $) Conversation ended', [
            'conversation_id' => $conversation->id,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Extract contact info from message and capture lead.
     */
    private function captureLeadFromMessage(Conversation $conversation, string $message): void
    {
        $contactInfo = $this->leadService->extractContactInfo($message);

        if (! empty($contactInfo)) {
            // Get all messages to build name context
            $messages = $conversation->messages()->where('role', 'user')->get();
            $allContent = $messages->pluck('content')->implode(' ');

            // Try to extract name if we have email or phone
            if (empty($contactInfo['name'])) {
                $contactInfo['name'] = $this->extractName($allContent);
            }

            $lead = $this->leadService->captureFromConversation($conversation, $contactInfo);

            if ($lead) {
                Log::debug('[Widget] (NO $) Lead captured from message', [
                    'conversation_id' => $conversation->id,
                    'lead_id' => $lead->id,
                    'has_email' => ! empty($contactInfo['email']),
                    'has_phone' => ! empty($contactInfo['phone']),
                ]);
            }
        }
    }

    /**
     * Try to extract a name from conversation content.
     */
    private function extractName(string $content): ?string
    {
        // Common patterns for name introduction
        $patterns = [
            '/(?:my name is|i\'m|i am|this is|call me)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)/i',
            '/^([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\s+here/im',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }
}
