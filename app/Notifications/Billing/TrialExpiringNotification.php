<?php

declare(strict_types=1);

namespace App\Notifications\Billing;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class TrialExpiringNotification extends Notification implements NotTenantAware, ShouldQueue
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
            ->subject('Your AbitChat free plan ends soon')
            ->greeting("Hi {$this->tenant->name},")
            ->line("Your free plan ends on **{$expires}**. Subscribe now to keep your widget live without interruption.")
            ->action('Choose a plan', route('client.billing.plans'))
            ->line('Your data stays safe either way.');
    }
}
