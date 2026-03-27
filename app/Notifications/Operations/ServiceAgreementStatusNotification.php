<?php

namespace App\Notifications\Operations;

use App\Models\ServiceAgreement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ServiceAgreementStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ServiceAgreement $agreement,
        public string $action,
        public string $actorName
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Service Agreement {$this->action}: {$this->agreement->title}")
            ->greeting("Kia ora {$notifiable->name},")
            ->line(sprintf(
                '%s has %s service agreement "%s" for %s.',
                $this->actorName,
                $this->action,
                $this->agreement->title,
                $this->agreement->client?->full_name ?? 'a client'
            ))
            ->action('View Agreement', url("/operations/service-agreements/{$this->agreement->id}"))
            ->line('Please review and take any necessary action.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'agreement_id' => $this->agreement->id,
            'title' => $this->agreement->title,
            'action' => $this->action,
            'actor' => $this->actorName,
            'client_id' => $this->agreement->client_id,
        ];
    }
}
