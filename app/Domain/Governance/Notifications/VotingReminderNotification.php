<?php

namespace App\Domain\Governance\Notifications;

use App\Domain\Governance\Models\Resolution;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VotingReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Resolution $resolution
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Reminder: Vote Required — {$this->resolution->resolution_reference}")
            ->line('This is a reminder that your vote is required on the following resolution:')
            ->line('')
            ->line("**{$this->resolution->title}**")
            ->line("Reference: {$this->resolution->resolution_reference}")
            ->line('Deadline: ' . ($this->resolution->deadline?->format('j F Y g:i A') ?? 'No deadline'))
            ->action('Cast Your Vote', url("/governance/resolutions/{$this->resolution->id}"))
            ->line('If you have any conflicts of interest, please declare them before voting.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'voting_reminder',
            'title' => "Voting Reminder: {$this->resolution->resolution_reference}",
            'message' => 'A resolution requires your vote.',
            'url' => "/governance/resolutions/{$this->resolution->id}",
            'context' => [
                'Reference' => $this->resolution->resolution_reference,
            ],
            'resolution_id' => $this->resolution->id,
            'resolution_reference' => $this->resolution->resolution_reference,
        ];
    }
}
