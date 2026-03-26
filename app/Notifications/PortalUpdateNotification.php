<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortalUpdateNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $clientName,
        public string $noteType,
        public string $noteExcerpt,
        public string $authorName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Update for {$this->clientName}: New {$this->noteType}")
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line("A new **{$this->noteType}** has been added for **{$this->clientName}** by {$this->authorName}.")
            ->line('> ' . $this->noteExcerpt)
            ->action('View in Portal', url('/portal'))
            ->line('You are receiving this because you are subscribed to updates for this client.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => "New {$this->noteType} for {$this->clientName}",
            'message' => "{$this->authorName} added a {$this->noteType} for {$this->clientName}.",
            'client_name' => $this->clientName,
            'note_type' => $this->noteType,
            'author_name' => $this->authorName,
        ];
    }
}
