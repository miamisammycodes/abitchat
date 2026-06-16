<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Enums\EmailType;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Tenant;
use App\Notifications\Leads\NewLeadNotification;
use App\Services\Email\RecipientResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class LeadService
{
    public function __construct(private LeadScoring $scoring) {}

    /**
     * Create or update a lead from conversation data. Concurrent first-message
     * requests on the same conversation serialize on the conversation row so
     * exactly one lead is created.
     *
     * @param  array<string, mixed>  $contactInfo
     */
    public function captureFromConversation(Conversation $conversation, array $contactInfo = []): ?Lead
    {
        if (empty($contactInfo['email']) && empty($contactInfo['phone']) && empty($contactInfo['name'])) {
            return null;
        }

        return DB::transaction(function () use ($conversation, $contactInfo) {
            /** @var Conversation $locked */
            $locked = Conversation::with('tenant')
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Tenant $tenant */
            $tenant = $locked->tenant;

            if ($locked->lead_id) {
                $lead = Lead::find($locked->lead_id);
                if ($lead) {
                    return $this->updateLead($lead, $locked, $contactInfo);
                }
            }

            return $this->createLead($tenant, $locked, $contactInfo);
        });
    }

    /**
     * Create a new lead. Builds the model in memory, scores it via
     * LeadScoring (with the captured conversation in context), then saves
     * everything in one shot so the persisted score matches the metadata.
     *
     * @param  array<string, mixed>  $contactInfo
     */
    private function createLead(Tenant $tenant, Conversation $conversation, array $contactInfo): Lead
    {
        $lead = new Lead([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'name' => $contactInfo['name'] ?? null,
            'email' => $contactInfo['email'] ?? null,
            'phone' => $contactInfo['phone'] ?? null,
            'company' => $contactInfo['company'] ?? null,
            'status' => 'new',
            'source' => 'chatbot',
        ]);

        $score = $this->scoring->score($lead, $conversation);
        $lead->fill([
            'score' => $score,
            'metadata' => [
                'first_conversation_id' => $conversation->id,
                'captured_at' => now()->toIso8601String(),
                'initial_score' => $score,
            ],
        ]);
        $lead->save();

        // Link conversation to lead
        $conversation->update(['lead_id' => $lead->id]);

        // Send notification after transaction commits so the worker sees the committed row
        DB::afterCommit(fn () => $this->notifyNewLead($lead));

        Log::info('[Lead] (NO $) New lead captured', [
            'lead_id' => $lead->id,
            'tenant_id' => $tenant->id,
            'score' => $score,
        ]);

        return $lead;
    }

    /**
     * Update existing lead with new info. Scoring goes through the canonical
     * LeadScoring service.
     *
     * @param  array<string, mixed>  $contactInfo
     */
    private function updateLead(Lead $lead, Conversation $conversation, array $contactInfo): Lead
    {
        $contactUpdates = [];
        if (! empty($contactInfo['name']) && empty($lead->name)) {
            $contactUpdates['name'] = $contactInfo['name'];
        }
        if (! empty($contactInfo['phone']) && empty($lead->phone)) {
            $contactUpdates['phone'] = $contactInfo['phone'];
        }
        if (! empty($contactInfo['company']) && empty($lead->company)) {
            $contactUpdates['company'] = $contactInfo['company'];
        }

        // Apply contact-info updates in memory so LeadScoring reads the
        // up-to-date attributes (e.g., provided_company fires on the freshly
        // filled $lead->company).
        if ($contactUpdates !== []) {
            $lead->fill($contactUpdates);
        }

        $newScore = $this->scoring->score($lead, $conversation);
        if ($newScore !== $lead->score) {
            $lead->fill(['score' => $newScore]);
        }

        if ($lead->isDirty()) {
            $lead->save();
        }

        // Link conversation to lead if not already linked
        if ($conversation->lead_id !== $lead->id) {
            $conversation->update(['lead_id' => $lead->id]);
        }

        $lead->refresh();

        return $lead;
    }

    /**
     * Extract contact information from message content
     *
     * @return array<string, string|null>
     */
    public function extractContactInfo(string $content): array
    {
        $info = [];

        // Extract email
        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $content, $matches)) {
            $info['email'] = strtolower($matches[0]);

            // Try to extract name before email (common pattern: "name email@example.com")
            $emailPos = strpos($content, $matches[0]);
            if ($emailPos > 0) {
                $beforeEmail = trim(substr($content, 0, $emailPos));
                // Get last word(s) before email as potential name (up to 3 words for full names)
                if (preg_match('/([A-Za-z]+(?:\s+[A-Za-z]+){0,2})\s*$/', $beforeEmail, $nameMatch)) {
                    $potentialName = trim($nameMatch[1]);
                    // Validate it looks like a name (not common words)
                    $excludeWords = ['my', 'is', 'am', 'the', 'and', 'or', 'to', 'from', 'at', 'for', 'it', 'its', 'yes', 'no', 'hi', 'hello', 'hey', 'email', 'mail', 'address', 'contact', 'me', 'i', 'im', 'reach', 'send', 'write', 'here', 'this', 'that', 'with', 'can', 'you', 'please', 'thanks', 'thank'];

                    // Check each word in potential name - all must be valid
                    $words = preg_split('/\s+/', strtolower($potentialName));
                    $validWords = array_filter($words, fn ($w) => ! in_array($w, $excludeWords) && strlen($w) >= 2);

                    // Only use as name if we have valid words and they weren't all filtered out
                    if (count($validWords) > 0 && count($validWords) === count($words)) {
                        $info['name'] = ucwords(strtolower($potentialName));
                    }
                }
            }
        }

        // Extract phone (various formats)
        $phonePatterns = [
            '/(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', // US format
            '/\+?\d{10,14}/', // International
        ];
        foreach ($phonePatterns as $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $info['phone'] = preg_replace('/[^\d+]/', '', $matches[0]) ?? '';
                break;
            }
        }

        return $info;
    }

    private function notifyNewLead(Lead $lead): void
    {
        $recipients = app(RecipientResolver::class)
            ->recipientsFor(EmailType::LeadNotification, $lead->tenant);

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new NewLeadNotification($lead));
        } else {
            Log::warning('[Email] (NO $) Lead notification skipped — tenant has no owners', [
                'tenant_id' => $lead->tenant_id,
                'lead_id' => $lead->id,
                'email_type' => EmailType::LeadNotification->value,
            ]);
        }
    }

    /**
     * Manually update lead score
     */
    public function adjustScore(Lead $lead, int $adjustment, string $reason = ''): Lead
    {
        $newScore = min(100, max(0, $lead->score + $adjustment));

        /** @var array<string, mixed> $metadata */
        $metadata = $lead->metadata ?? [];
        /** @var array<int, array<string, mixed>> $scoreAdjustments */
        $scoreAdjustments = $metadata['score_adjustments'] ?? [];
        $scoreAdjustments[] = [
            'from' => $lead->score,
            'to' => $newScore,
            'adjustment' => $adjustment,
            'reason' => $reason,
            'at' => now()->toIso8601String(),
        ];
        $metadata['score_adjustments'] = $scoreAdjustments;

        $lead->update([
            'score' => $newScore,
            'metadata' => $metadata,
        ]);

        $lead->refresh();

        return $lead;
    }

    /**
     * Get lead statistics for a tenant
     *
     * @return array<string, int|float>
     */
    public function getStats(Tenant $tenant): array
    {
        $leads = Lead::forTenant($tenant);

        return [
            'total' => $leads->count(),
            'new' => (clone $leads)->where('status', 'new')->count(),
            'contacted' => (clone $leads)->where('status', 'contacted')->count(),
            'qualified' => (clone $leads)->where('status', 'qualified')->count(),
            'converted' => (clone $leads)->where('status', 'converted')->count(),
            'lost' => (clone $leads)->where('status', 'lost')->count(),
            'average_score' => (float) ((clone $leads)->avg('score') ?? 0),
            'high_quality' => (clone $leads)->where('score', '>=', LeadScoring::HOT_THRESHOLD)->count(),
        ];
    }
}
