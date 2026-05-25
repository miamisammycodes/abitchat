<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Enums\Role;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\Leads\NewLeadNotification;
use App\Services\Leads\LeadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NewLeadNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_capture_dispatches_notification_to_owners_via_resolver(): void
    {
        Notification::fake();

        $tenant = Tenant::factory()->create();
        $owner = User::factory()->create();
        UserRole::create(['user_id' => $owner->id, 'tenant_id' => $tenant->id, 'role' => Role::Owner]);

        $conversation = Conversation::factory()->create(['tenant_id' => $tenant->id]);

        app(LeadService::class)->captureFromConversation($conversation, [
            'name' => 'Jane Doe',
            'email' => 'lead@example.com',
        ]);

        Notification::assertSentTo($owner, NewLeadNotification::class);
    }

    public function test_lead_notification_reply_to_is_lead_email_when_present(): void
    {
        $tenant = Tenant::factory()->create();
        $lead = Lead::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'email' => 'lead@example.com',
            'status' => 'new',
            'score' => 0,
        ]);

        $mail = (new NewLeadNotification($lead))->toMail(new AnonymousNotifiable);

        $this->assertSame('lead@example.com', $mail->replyTo[0][0]);
    }

    public function test_lead_notification_reply_to_falls_back_to_support_when_lead_email_null(): void
    {
        $tenant = Tenant::factory()->create();
        $lead = Lead::create([
            'tenant_id' => $tenant->id,
            'name' => 'Anonymous',
            'email' => null,
            'status' => 'new',
            'score' => 0,
        ]);

        $mail = (new NewLeadNotification($lead))->toMail(new AnonymousNotifiable);

        $this->assertSame('support@abit.bt', $mail->replyTo[0][0]);
    }
}
