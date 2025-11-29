<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Notifications\NewLeadNotification;
use Illuminate\Support\Facades\Log;

class LeadService
{
    /**
     * Scoring weights for different actions
     */
    private const SCORE_WEIGHTS = [
        'message_sent' => 2,
        'provided_email' => 20,
        'provided_phone' => 15,
        'provided_name' => 10,
        'provided_company' => 10,
        'long_conversation' => 5,  // 5+ messages
        'return_visitor' => 10,
        'asked_pricing' => 15,
        'asked_demo' => 20,
    ];

    /**
     * Keywords that indicate high intent
     */
    private const HIGH_INTENT_KEYWORDS = [
        'pricing' => ['price', 'pricing', 'cost', 'how much', 'quote', 'estimate'],
        'demo' => ['demo', 'demonstration', 'trial', 'try', 'test'],
        'contact' => ['contact', 'call me', 'reach out', 'talk to someone', 'speak with'],
        'purchase' => ['buy', 'purchase', 'subscribe', 'sign up', 'get started'],
    ];

    /**
     * Create or update a lead from conversation data
     */
    public function captureFromConversation(Conversation $conversation, array $contactInfo = []): ?Lead
    {
        $tenant = $conversation->tenant;

        // Check if we have enough info to create a lead
        if (empty($contactInfo['email']) && empty($contactInfo['phone']) && empty($contactInfo['name'])) {
            return null;
        }

        // Find existing lead by email or phone
        $lead = $this->findExistingLead($tenant, $contactInfo);

        if ($lead) {
            // Update existing lead
            $lead = $this->updateLead($lead, $conversation, $contactInfo);
        } else {
            // Create new lead
            $lead = $this->createLead($tenant, $conversation, $contactInfo);
        }

        return $lead;
    }

    /**
     * Find existing lead by email or phone
     */
    private function findExistingLead(Tenant $tenant, array $contactInfo): ?Lead
    {
        $query = Lead::where('tenant_id', $tenant->id);

        if (!empty($contactInfo['email'])) {
            $query->where('email', $contactInfo['email']);
        } elseif (!empty($contactInfo['phone'])) {
            $query->where('phone', $contactInfo['phone']);
        } else {
            return null;
        }

        return $query->first();
    }

    /**
     * Create a new lead
     */
    private function createLead(Tenant $tenant, Conversation $conversation, array $contactInfo): Lead
    {
        $score = $this->calculateInitialScore($conversation, $contactInfo);

        $lead = Lead::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'name' => $contactInfo['name'] ?? null,
            'email' => $contactInfo['email'] ?? null,
            'phone' => $contactInfo['phone'] ?? null,
            'company' => $contactInfo['company'] ?? null,
            'score' => $score,
            'status' => 'new',
            'source' => 'chatbot',
            'metadata' => [
                'first_conversation_id' => $conversation->id,
                'captured_at' => now()->toIso8601String(),
                'initial_score' => $score,
            ],
        ]);

        // Link conversation to lead
        $conversation->update(['lead_id' => $lead->id]);

        // Send notification
        $this->notifyNewLead($lead);

        Log::info('[Lead] (NO $) New lead captured', [
            'lead_id' => $lead->id,
            'tenant_id' => $tenant->id,
            'score' => $score,
        ]);

        return $lead;
    }

    /**
     * Update existing lead with new info
     */
    private function updateLead(Lead $lead, Conversation $conversation, array $contactInfo): Lead
    {
        $updates = [];

        // Update contact info if provided and not already set
        if (!empty($contactInfo['name']) && empty($lead->name)) {
            $updates['name'] = $contactInfo['name'];
        }
        if (!empty($contactInfo['phone']) && empty($lead->phone)) {
            $updates['phone'] = $contactInfo['phone'];
        }
        if (!empty($contactInfo['company']) && empty($lead->company)) {
            $updates['company'] = $contactInfo['company'];
        }

        // Recalculate score
        $newScore = $this->calculateScore($lead, $conversation, $contactInfo);
        if ($newScore !== $lead->score) {
            $updates['score'] = $newScore;
        }

        if (!empty($updates)) {
            $lead->update($updates);
        }

        // Link conversation to lead if not already linked
        if ($conversation->lead_id !== $lead->id) {
            $conversation->update(['lead_id' => $lead->id]);
        }

        return $lead->fresh();
    }

    /**
     * Calculate initial score for new lead
     */
    private function calculateInitialScore(Conversation $conversation, array $contactInfo): int
    {
        $score = 0;

        // Score for contact info provided
        if (!empty($contactInfo['email'])) {
            $score += self::SCORE_WEIGHTS['provided_email'];
        }
        if (!empty($contactInfo['phone'])) {
            $score += self::SCORE_WEIGHTS['provided_phone'];
        }
        if (!empty($contactInfo['name'])) {
            $score += self::SCORE_WEIGHTS['provided_name'];
        }
        if (!empty($contactInfo['company'])) {
            $score += self::SCORE_WEIGHTS['provided_company'];
        }

        // Score for message count
        $messageCount = $conversation->messages()->where('role', 'user')->count();
        $score += $messageCount * self::SCORE_WEIGHTS['message_sent'];

        // Check for high intent keywords
        $score += $this->scoreHighIntentKeywords($conversation);

        return min(100, $score);
    }

    /**
     * Calculate updated score for existing lead
     */
    private function calculateScore(Lead $lead, Conversation $conversation, array $contactInfo): int
    {
        $score = $lead->score;

        // Add points for new contact info
        if (!empty($contactInfo['phone']) && empty($lead->phone)) {
            $score += self::SCORE_WEIGHTS['provided_phone'];
        }
        if (!empty($contactInfo['company']) && empty($lead->company)) {
            $score += self::SCORE_WEIGHTS['provided_company'];
        }

        // Return visitor bonus
        $conversationCount = Conversation::where('lead_id', $lead->id)->count();
        if ($conversationCount > 1) {
            $score += self::SCORE_WEIGHTS['return_visitor'];
        }

        // Check for high intent keywords in current conversation
        $score += $this->scoreHighIntentKeywords($conversation);

        return min(100, $score);
    }

    /**
     * Score based on high intent keywords in messages
     */
    private function scoreHighIntentKeywords(Conversation $conversation): int
    {
        $score = 0;
        $messages = $conversation->messages()->where('role', 'user')->get();
        $allContent = $messages->pluck('content')->implode(' ');
        $allContentLower = strtolower($allContent);

        $detectedIntents = [];

        foreach (self::HIGH_INTENT_KEYWORDS as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($allContentLower, $keyword) && !in_array($intent, $detectedIntents)) {
                    $detectedIntents[] = $intent;

                    if ($intent === 'pricing') {
                        $score += self::SCORE_WEIGHTS['asked_pricing'];
                    } elseif ($intent === 'demo') {
                        $score += self::SCORE_WEIGHTS['asked_demo'];
                    }
                    break;
                }
            }
        }

        return $score;
    }

    /**
     * Extract contact information from message content
     */
    public function extractContactInfo(string $content): array
    {
        $info = [];

        // Extract email
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $content, $matches)) {
            $info['email'] = strtolower($matches[0]);
        }

        // Extract phone (various formats)
        $phonePatterns = [
            '/(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', // US format
            '/\+?\d{10,14}/', // International
        ];
        foreach ($phonePatterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $info['phone'] = preg_replace('/[^\d+]/', '', $matches[0]);
                break;
            }
        }

        return $info;
    }

    /**
     * Send notification for new lead
     */
    private function notifyNewLead(Lead $lead): void
    {
        try {
            $tenant = $lead->tenant;

            // Get tenant users to notify
            $users = $tenant->users()->get();

            foreach ($users as $user) {
                $user->notify(new NewLeadNotification($lead));
            }

            Log::info('[Lead] (NO $) Notification queued', [
                'lead_id' => $lead->id,
                'notified_users' => $users->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('[Lead] Failed to send notification', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Manually update lead score
     */
    public function adjustScore(Lead $lead, int $adjustment, string $reason = ''): Lead
    {
        $newScore = min(100, max(0, $lead->score + $adjustment));

        $metadata = $lead->metadata ?? [];
        $metadata['score_adjustments'] = $metadata['score_adjustments'] ?? [];
        $metadata['score_adjustments'][] = [
            'from' => $lead->score,
            'to' => $newScore,
            'adjustment' => $adjustment,
            'reason' => $reason,
            'at' => now()->toIso8601String(),
        ];

        $lead->update([
            'score' => $newScore,
            'metadata' => $metadata,
        ]);

        return $lead->fresh();
    }

    /**
     * Get lead statistics for a tenant
     */
    public function getStats(Tenant $tenant): array
    {
        $leads = Lead::where('tenant_id', $tenant->id);

        return [
            'total' => $leads->count(),
            'new' => (clone $leads)->where('status', 'new')->count(),
            'contacted' => (clone $leads)->where('status', 'contacted')->count(),
            'qualified' => (clone $leads)->where('status', 'qualified')->count(),
            'converted' => (clone $leads)->where('status', 'converted')->count(),
            'lost' => (clone $leads)->where('status', 'lost')->count(),
            'average_score' => (clone $leads)->avg('score') ?? 0,
            'high_quality' => (clone $leads)->where('score', '>=', 70)->count(),
        ];
    }
}
