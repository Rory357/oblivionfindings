<?php

namespace App\Notifications\It;

use App\Domain\It\Contracts\TracksItEmailDelivery;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class EmailConfigurationTestNotification extends Notification implements TracksItEmailDelivery
{
    public function __construct(private readonly int $version, private readonly ?string $captureMode) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function itEmailDeliveryContext(): array
    {
        return [
            'type' => 'it_email_configuration_test', 'audience' => 'settings_owner',
            'subject' => 'IT support email configuration test',
            'retry_context' => ['configuration_version' => $this->version, 'capture_mode' => $this->captureMode],
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('IT support email configuration test')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('This message tests the saved IT support email settings.')
            ->line('Check the sender and reply address before using this connection for support messages.')
            ->action('Review email settings', url('/settings/email'));
    }
}
