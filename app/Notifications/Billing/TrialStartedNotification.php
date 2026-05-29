<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class TrialStartedNotification extends Notification implements NotTenantAware, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Tenant $tenant) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expires = $this->tenant->plan_expires_at?->format('M j, Y');

        return (new MailMessage)
            ->subject('Your AbitChat free plan is live')
            ->greeting("Hi {$this->tenant->name},")
            ->line('Your 14-day free plan is now active and your chat widget is live.')
            ->line("It runs until **{$expires}**. Add your widget snippet from Widget Settings to go live on your site.")
            ->action('Open Widget Settings', route('client.widget.index'))
            ->line('Thanks for using AbitChat!');
    }
}
