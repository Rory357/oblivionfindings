<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $approverName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Account Has Been Approved')
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line('Your account has been reviewed and approved. You now have full access to the platform.')
            ->action('Get Started', url('/dashboard'))
            ->line('If you have any questions, please contact your administrator.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Account Approved',
            'message' => "Your account has been approved by {$this->approverName}. You now have full access.",
            'approver_name' => $this->approverName,
        ];
    }
}
