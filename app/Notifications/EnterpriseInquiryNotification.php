<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\EnterpriseInquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EnterpriseInquiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public EnterpriseInquiry $inquiry
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
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
        $inquiry = $this->inquiry;

        return (new MailMessage)
            ->subject("New Enterprise Inquiry from {$inquiry->name}")
            ->greeting('New Enterprise Plan Inquiry!')
            ->line('A potential customer has submitted an inquiry for the Enterprise plan.')
            ->line("**Name:** {$inquiry->name}")
            ->line("**Email:** {$inquiry->email}")
            ->when($inquiry->company, fn ($mail) => $mail->line("**Company:** {$inquiry->company}"))
            ->line('**Message:**')
            ->line($inquiry->message)
            ->action('View Inquiry', url('/admin/inquiries'))
            ->line('Please follow up with this potential customer soon!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'inquiry_id' => $this->inquiry->id,
            'name' => $this->inquiry->name,
            'email' => $this->inquiry->email,
            'company' => $this->inquiry->company,
        ];
    }
}
