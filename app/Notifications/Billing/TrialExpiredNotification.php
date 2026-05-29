<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class TrialExpiredNotification extends Notification implements NotTenantAware, ShouldQueue
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
        return (new MailMessage)
            ->subject('Your AbitChat free plan has ended')
            ->greeting("Hi {$this->tenant->name},")
            ->line('Your free plan has ended, so your chat widget is now offline.')
            ->line('Subscribe to a paid plan to bring it back online. Your leads, conversations, and knowledge base are all preserved.')
            ->action('Reactivate with a plan', route('client.billing.plans'));
    }
}
