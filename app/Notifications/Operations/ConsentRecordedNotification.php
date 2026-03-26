<?php

namespace App\Notifications\Operations;

use App\Models\ClientConsent;
use App\Models\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ConsentRecordedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ClientConsent $consent,
        public Client $client,
        public string $action,
        public string $actorName
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $statusLabel = match ($this->action) {
            'recorded' => 'recorded',
            'refused' => 'refused',
            'withdrawn' => 'withdrawn',
            default => $this->action,
        };

        return (new MailMessage)
            ->subject("Consent {$statusLabel}: {$this->client->full_name}")
            ->greeting("Kia ora {$notifiable->name},")
            ->line(sprintf(
                '%s has %s consent for %s.',
                $this->actorName,
                $statusLabel,
                $this->client->full_name
            ))
            ->when($this->action === 'withdrawn', fn ($mail) => $mail->line('Reason: ' . ($this->consent->withdrawal_reason ?? 'Not specified')))
            ->action('View Consents', url("/operations/clients/{$this->client->id}/consents"))
            ->line('Please review and ensure any required follow-up actions are taken.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'consent_id' => $this->consent->id,
            'client_id' => $this->client->id,
            'client_name' => $this->client->full_name,
            'action' => $this->action,
            'actor' => $this->actorName,
        ];
    }
}
