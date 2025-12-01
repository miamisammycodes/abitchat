<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Models\Conversation;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;

class LeadScoringService
{
    /**
     * Scoring signals and their point values.
     */
    private array $signals = [
        'provided_email' => 20,
        'provided_phone' => 15,
        'provided_name' => 10,
        'asked_about_pricing' => 25,
        'asked_about_demo' => 30,
        'multiple_sessions' => 10,
        'high_engagement' => 15,
        'mentioned_competitor' => 20,
        'mentioned_timeline' => 25,
        'negative_sentiment' => -10,
    ];

    /**
     * Keywords that indicate buying intent.
     */
    private array $pricingKeywords = [
        'price', 'pricing', 'cost', 'quote', 'budget', 'affordable',
        'expensive', 'cheap', 'how much', 'rate', 'fee', 'charge',
    ];

    private array $demoKeywords = [
        'demo', 'trial', 'try', 'test', 'sample', 'preview',
        'see it', 'show me', 'walkthrough', 'presentation',
    ];

    private array $timelineKeywords = [
        'urgent', 'asap', 'immediately', 'today', 'this week',
        'deadline', 'soon', 'quickly', 'right away', 'now',
    ];

    private array $competitorKeywords = [
        'competitor', 'alternative', 'compared to', 'versus',
        'vs', 'switch from', 'migrate', 'currently using',
    ];

    private array $negativeKeywords = [
        'frustrated', 'angry', 'disappointed', 'terrible', 'awful',
        'hate', 'worst', 'useless', 'waste', 'scam',
    ];

    /**
     * Calculate lead score based on conversation and lead data.
     */
    public function calculateScore(Lead $lead): int
    {
        $score = 0;
        $signals = [];

        // Contact info signals
        if (! empty($lead->email)) {
            $score += $this->signals['provided_email'];
            $signals[] = 'provided_email';
        }

        if (! empty($lead->phone)) {
            $score += $this->signals['provided_phone'];
            $signals[] = 'provided_phone';
        }

        if (! empty($lead->name)) {
            $score += $this->signals['provided_name'];
            $signals[] = 'provided_name';
        }

        // Analyze conversation content
        $conversation = $lead->conversation;
        if ($conversation) {
            $messages = $conversation->messages()->where('role', 'user')->get();
            $allContent = $messages->pluck('content')->implode(' ');
            $allContentLower = strtolower($allContent);

            // Check for pricing interest
            if ($this->containsAny($allContentLower, $this->pricingKeywords)) {
                $score += $this->signals['asked_about_pricing'];
                $signals[] = 'asked_about_pricing';
            }

            // Check for demo/trial interest
            if ($this->containsAny($allContentLower, $this->demoKeywords)) {
                $score += $this->signals['asked_about_demo'];
                $signals[] = 'asked_about_demo';
            }

            // Check for timeline/urgency
            if ($this->containsAny($allContentLower, $this->timelineKeywords)) {
                $score += $this->signals['mentioned_timeline'];
                $signals[] = 'mentioned_timeline';
            }

            // Check for competitor mentions
            if ($this->containsAny($allContentLower, $this->competitorKeywords)) {
                $score += $this->signals['mentioned_competitor'];
                $signals[] = 'mentioned_competitor';
            }

            // Check for negative sentiment
            if ($this->containsAny($allContentLower, $this->negativeKeywords)) {
                $score += $this->signals['negative_sentiment'];
                $signals[] = 'negative_sentiment';
            }

            // High engagement (more than 5 messages)
            if ($messages->count() > 5) {
                $score += $this->signals['high_engagement'];
                $signals[] = 'high_engagement';
            }
        }

        // Ensure score stays within bounds
        $score = max(0, min(100, $score));

        Log::debug('[LeadScoring] (NO $) Score calculated', [
            'lead_id' => $lead->id,
            'score' => $score,
            'signals' => $signals,
        ]);

        return $score;
    }

    /**
     * Get the lead temperature based on score.
     */
    public function getTemperature(int $score): string
    {
        if ($score >= 61) {
            return 'hot';
        } elseif ($score >= 31) {
            return 'warm';
        }

        return 'cold';
    }

    /**
     * Update lead score and save.
     */
    public function updateLeadScore(Lead $lead): Lead
    {
        $score = $this->calculateScore($lead);
        $lead->score = $score;
        $lead->save();

        return $lead;
    }

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
