<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\User;
use App\Notifications\Auth\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_dispatches_branded_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'jane@example.test']);

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_reset_email_contains_reset_url_with_token(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.test']);
        $notif = new ResetPasswordNotification('test-token-abc123');

        $mail = $notif->toMail($user);
        $rendered = (string) $mail->render();

        $this->assertStringContainsString('test-token-abc123', $rendered);
        $this->assertStringContainsString('reset-password', $rendered);
    }

    public function test_reset_email_uses_abitchat_theme(): void
    {
        $user = User::factory()->create();
        $notif = new ResetPasswordNotification('any-token');

        $rendered = (string) $notif->toMail($user)->render();

        // Header wordmark from the abitchat theme
        $this->assertStringContainsString('AbitChat', $rendered);
        // Footer support address from the customized footer
        $this->assertStringContainsString('support@abit.bt', $rendered);
    }
}
