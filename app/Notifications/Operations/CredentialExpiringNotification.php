<?php

namespace App\Notifications\Operations;

use App\Models\StaffCredential;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CredentialExpiringNotification extends Notification
{
    use Queueable;

    public function __construct(
        public StaffCredential $credential
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Credential Expiring: {$this->credential->type}")
            ->greeting("Kia ora {$notifiable->name},")
            ->line(sprintf(
                'Your %s credential (issued by %s) will expire on %s.',
                $this->credential->type,
                $this->credential->issuer ?? 'N/A',
                $this->credential->expires_at?->format('d M Y') ?? 'soon'
            ))
            ->line('Please arrange for renewal before the expiry date to maintain compliance.')
            ->action('View My Credentials', url('/staff/credentials'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'credential_id' => $this->credential->id,
            'type' => $this->credential->type,
            'expires_at' => $this->credential->expires_at?->toDateString(),
        ];
    }
}
