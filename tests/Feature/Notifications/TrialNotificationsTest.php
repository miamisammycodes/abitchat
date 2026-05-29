<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\EmailType;
use App\Models\Tenant;
use App\Notifications\Billing\TrialStartedNotification;
use App\Services\Email\RecipientResolver;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\TestCase;

class TrialNotificationsTest extends TestCase
{
    public function test_resolver_routes_trial_emails_to_owners(): void
    {
        $this->actingAsSetupTenant(); // creates an Owner on $this->tenant

        $recipients = app(RecipientResolver::class)->recipientsFor(EmailType::TrialStarted, $this->tenant);

        $this->assertCount(1, $recipients);
        $this->assertSame($this->user->id, $recipients->first()->id);
    }

    public function test_trial_started_mail_renders(): void
    {
        $tenant = Tenant::create(['name' => 'Mailco', 'slug' => 'mailco', 'status' => 'active', 'plan_expires_at' => now()->addDays(14)]);

        $mail = (new TrialStartedNotification($tenant))->toMail(new AnonymousNotifiable);

        $this->assertStringContainsString('Mailco', (string) $mail->greeting);
    }
}
