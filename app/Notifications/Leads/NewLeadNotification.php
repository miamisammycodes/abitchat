<?php

declare(strict_types=1);

namespace App\Notifications\Leads;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class NewLeadNotification extends Notification implements NotTenantAware, ShouldQueue
{
    use Queueable;

    public function __construct(public Lead $lead) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lead = $this->lead;
        $scoreLabel = $this->scoreLabel($lead->score);
        $replyTo = $lead->email ?: 'support@abit.bt';

        return (new MailMessage)
            ->subject("New lead: {$lead->name} ({$scoreLabel})")
            ->replyTo($replyTo)
            ->greeting('New lead captured!')
            ->line('A new lead has been captured from your chatbot.')
            ->line("**Name:** {$lead->name}")
            ->when($lead->email, fn ($m) => $m->line("**Email:** {$lead->email}"))
            ->when($lead->phone, fn ($m) => $m->line("**Phone:** {$lead->phone}"))
            ->when($lead->company, fn ($m) => $m->line("**Company:** {$lead->company}"))
            ->line("**Lead score:** {$lead->score}/100 ({$scoreLabel})")
            ->action('View lead', route('client.leads.show', $lead))
            ->line('Follow up soon to maximize conversion.');
    }

    /**
     * @return array<string, mixed>
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

    private function scoreLabel(int $score): string
    {
        return match (true) {
            $score >= 80 => 'Hot',
            $score >= 60 => 'Warm',
            $score >= 40 => 'Moderate',
            default => 'Cold',
        };
    }
}
