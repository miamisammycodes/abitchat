<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EmailType;
use App\Models\Plan;
use App\Models\Tenant;
use App\Notifications\Billing\TrialExpiredNotification;
use App\Notifications\Billing\TrialExpiringNotification;
use App\Services\Email\RecipientResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendTrialLifecycleEmails extends Command
{
    protected $signature = 'trials:send-lifecycle-emails';

    protected $description = 'Email Free-plan tenants ~3 days before expiry and on expiry';

    private const REMINDER_DAYS = 3;

    public function __construct(private readonly RecipientResolver $resolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $freeId = Plan::query()->free()->value('id');
        if ($freeId === null) {
            $this->warn('No Free plan found; nothing to do.');

            return self::SUCCESS;
        }

        $reminders = 0;
        $expireds = 0;

        // Reminder: Free-plan tenants expiring within REMINDER_DAYS, not yet reminded.
        Tenant::query()
            ->where('plan_id', $freeId)
            ->whereNotNull('plan_expires_at')
            ->where('plan_expires_at', '>', now())
            ->where('plan_expires_at', '<=', now()->addDays(self::REMINDER_DAYS))
            ->whereNull('trial_expiring_notified_at')
            ->chunkById(100, function ($tenants) use (&$reminders): void {
                foreach ($tenants as $tenant) {
                    Notification::send(
                        $this->resolver->recipientsFor(EmailType::TrialExpiring, $tenant),
                        new TrialExpiringNotification($tenant),
                    );
                    $tenant->forceFill(['trial_expiring_notified_at' => now()])->save();
                    $reminders++;
                }
            });

        // Expired: Free-plan tenants past expiry, not yet notified.
        Tenant::query()
            ->where('plan_id', $freeId)
            ->whereNotNull('plan_expires_at')
            ->where('plan_expires_at', '<=', now())
            ->whereNull('trial_expired_notified_at')
            ->chunkById(100, function ($tenants) use (&$expireds): void {
                foreach ($tenants as $tenant) {
                    Notification::send(
                        $this->resolver->recipientsFor(EmailType::TrialExpired, $tenant),
                        new TrialExpiredNotification($tenant),
                    );
                    $tenant->forceFill(['trial_expired_notified_at' => now()])->save();
                    $expireds++;
                }
            });

        $this->info("Sent {$reminders} reminder(s) and {$expireds} expiry email(s).");

        return self::SUCCESS;
    }
}
