<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\EnterpriseInquiry;
use App\Models\Tenant;
use App\Notifications\Admin\EnterpriseInquiryNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\SeedsRoleMatrix;
use Tests\TestCase;

class EnterpriseInquiryNotificationTest extends TestCase
{
    use SeedsRoleMatrix;

    public function test_inquiry_routes_to_configured_admin_address(): void
    {
        Notification::fake();
        config(['mail.admin_inquiry_address' => 'enterprise@abit.bt']);

        $tenant = $this->makeTenant();
        $this->actingAsAgent($tenant);

        $this->post(route('client.billing.enterprise-inquiry'), [
            'name' => 'Jane Doe',
            'email' => 'jane@acme.test',
            'company' => 'Acme',
            'message' => 'Hi we are interested.',
        ])->assertRedirect();

        Notification::assertSentOnDemand(
            EnterpriseInquiryNotification::class,
            function ($n, $channels, $notifiable): bool {
                return $notifiable->routeNotificationFor('mail') === 'enterprise@abit.bt';
            }
        );
    }

    public function test_inquiry_reply_to_is_inquirer_email(): void
    {
        $tenant = $this->makeTenant();
        $inquiry = EnterpriseInquiry::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'email' => 'jane@acme.test',
            'company' => 'Acme',
            'message' => 'Hi we are interested.',
            'status' => 'pending',
        ]);

        $notif = new EnterpriseInquiryNotification($inquiry);
        $mail = $notif->toMail(new AnonymousNotifiable);

        $this->assertSame('jane@acme.test', $mail->replyTo[0][0]);
    }

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Inquiry Tenant',
            'slug' => uniqid('inq-'),
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
        ]);
    }
}
