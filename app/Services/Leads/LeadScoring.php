<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Conversation;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

/**
 * Canonical lead-scoring service.
 *
 * Single source of truth for the signal set, weight table, keyword
 * dictionaries, and temperature thresholds used everywhere a Lead's
 * score is computed (widget chat capture, widget lead-form submission,
 * future score-recalc paths).
 *
 * Public surface is intentionally small:
 *   - score(Lead, ?Conversation): int   — 0–100 clamped
 *   - temperature(int): string          — hot | warm | cold
 *
 * Persistence is the caller's job. The service does not save the Lead.
 */
class LeadScoring
{
    /**
     * Scoring signals and their point values.
     *
     * @var array<string, int>
     */
    private array $weights = [
        'provided_email' => 20,
        'provided_phone' => 15,
        'provided_name' => 10,
        'provided_company' => 10,
        'message_sent' => 2,
        'long_conversation' => 5,
        'return_visitor' => 10,
        'asked_about_pricing' => 25,
        'asked_about_demo' => 30,
        'mentioned_timeline' => 25,
        'mentioned_competitor' => 20,
        'asked_about_contact' => 10,
        'asked_about_purchase' => 15,
        'negative_sentiment' => -10,
    ];

    /**
     * Keyword dictionaries. Each fires its signal at most once per call.
     *
     * @var array<string, array<int, string>>
     */
    private array $dictionaries = [
        'pricing' => [
            'price', 'pricing', 'cost', 'quote', 'budget', 'affordable',
            'expensive', 'cheap', 'how much', 'rate', 'fee', 'charge',
            'estimate',
        ],
        'demo' => [
            'demo', 'demonstration', 'trial', 'try', 'test', 'sample',
            'preview', 'see it', 'show me', 'walkthrough', 'presentation',
        ],
        'timeline' => [
            'urgent', 'asap', 'immediately', 'today', 'this week',
            'deadline', 'soon', 'quickly', 'right away', 'now',
        ],
        'competitor' => [
            'competitor', 'alternative', 'compared to', 'versus',
            'vs', 'switch from', 'migrate', 'currently using',
        ],
        'negative' => [
            'frustrated', 'angry', 'disappointed', 'terrible', 'awful',
            'hate', 'worst', 'useless', 'waste', 'scam',
        ],
        'contact' => [
            'contact', 'call me', 'reach out', 'talk to someone',
            'speak with',
        ],
        'purchase' => [
            'buy', 'purchase', 'subscribe', 'sign up', 'get started',
        ],
    ];

    /**
     * Map from keyword-dictionary name to the signal name it fires.
     *
     * @var array<string, string>
     */
    private array $dictionaryToSignal = [
        'pricing' => 'asked_about_pricing',
        'demo' => 'asked_about_demo',
        'timeline' => 'mentioned_timeline',
        'competitor' => 'mentioned_competitor',
        'negative' => 'negative_sentiment',
        'contact' => 'asked_about_contact',
        'purchase' => 'asked_about_purchase',
    ];

    public function score(Lead $lead, ?Conversation $conversation = null): int
    {
        $conversation ??= $lead->conversation;

        $score = 0;
        $fired = [];

        // Contact-info signals
        if (! empty($lead->email)) {
            $score += $this->weights['provided_email'];
            $fired[] = 'provided_email';
        }
        if (! empty($lead->phone)) {
            $score += $this->weights['provided_phone'];
            $fired[] = 'provided_phone';
        }
        if (! empty($lead->name)) {
            $score += $this->weights['provided_name'];
            $fired[] = 'provided_name';
        }
        if (! empty($lead->company)) {
            $score += $this->weights['provided_company'];
            $fired[] = 'provided_company';
        }

        // Return-visitor signal (cross-conversation).
        if ($lead->exists && Conversation::where('lead_id', $lead->id)->count() >= 2) {
            $score += $this->weights['return_visitor'];
            $fired[] = 'return_visitor';
        }

        // Conversation-driven signals.
        if ($conversation !== null) {
            $messages = $conversation->messages()->where('role', 'user')->get();
            $messageCount = $messages->count();

            if ($messageCount > 0) {
                $score += $messageCount * $this->weights['message_sent'];
                $fired[] = 'message_sent';
            }

            if ($messageCount >= 5) {
                $score += $this->weights['long_conversation'];
                $fired[] = 'long_conversation';
            }

            $allContentLower = strtolower($messages->pluck('content')->implode(' '));

            foreach ($this->dictionaries as $name => $keywords) {
                if ($this->containsAny($allContentLower, $keywords)) {
                    $signal = $this->dictionaryToSignal[$name];
                    $score += $this->weights[$signal];
                    $fired[] = $signal;
                }
            }
        }

        $score = max(0, min(100, $score));

        Log::debug('[LeadScoring] (NO $) Score calculated', [
            'lead_id' => $lead->id,
            'score' => $score,
            'signals' => $fired,
        ]);

        return $score;
    }

    public function temperature(int $score): string
    {
        if ($score >= 61) {
            return 'hot';
        }

        if ($score >= 31) {
            return 'warm';
        }

        return 'cold';
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
