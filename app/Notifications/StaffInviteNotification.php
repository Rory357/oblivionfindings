<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInviteNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $inviterName,
        public string $acceptUrl,
        public ?string $message = null,
    ) {}

    public function via(object $notifiable): array
    {
        // Database is always recorded. Mail will work when MAIL_* env is configured.
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('You\'re invited to join Oblivion Findings')
            ->greeting('Hello!')
            ->line("{$this->inviterName} invited you to join Oblivion Findings.");

        if ($this->message) {
            $mail->line($this->message);
        }

        return $mail
            ->action('Accept invitation', $this->acceptUrl)
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Staff invitation',
            'message' => "{$this->inviterName} invited you to join Oblivion Findings.",
            'accept_url' => $this->acceptUrl,
        ];
    }
}
