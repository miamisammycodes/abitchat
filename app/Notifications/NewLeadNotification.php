<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewLeadNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Lead $lead
    ) {}

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $lead = $this->lead;
        $scoreLabel = $this->getScoreLabel($lead->score);

        return (new MailMessage)
            ->subject("New Lead: {$lead->name} ({$scoreLabel})")
            ->greeting('New Lead Captured!')
            ->line("A new lead has been captured from your chatbot.")
            ->line("**Name:** {$lead->name}")
            ->when($lead->email, fn ($mail) => $mail->line("**Email:** {$lead->email}"))
            ->when($lead->phone, fn ($mail) => $mail->line("**Phone:** {$lead->phone}"))
            ->when($lead->company, fn ($mail) => $mail->line("**Company:** {$lead->company}"))
            ->line("**Lead Score:** {$lead->score}/100 ({$scoreLabel})")
            ->line("**Source:** Chatbot")
            ->action('View Lead', url('/leads/' . $lead->id))
            ->line('Follow up with this lead soon to maximize conversion!');
    }

    /**
     * Get score label based on score value
     */
    private function getScoreLabel(int $score): string
    {
        return match (true) {
            $score >= 80 => 'Hot',
            $score >= 60 => 'Warm',
            $score >= 40 => 'Moderate',
            default => 'Cold',
        };
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'lead_id' => $this->lead->id,
            'name' => $this->lead->name,
            'email' => $this->lead->email,
            'score' => $this->lead->score,
        ];
    }
}
