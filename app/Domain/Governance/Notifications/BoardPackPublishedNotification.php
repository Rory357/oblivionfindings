<?php

namespace App\Domain\Governance\Notifications;

use App\Domain\Governance\Models\BoardPack;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BoardPackPublishedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public BoardPack $pack
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $meeting = $this->pack->meeting;

        return (new MailMessage)
            ->subject("Board Pack Available — {$meeting->title}")
            ->line('The board pack for the upcoming meeting is now available.')
            ->line('')
            ->line("**{$meeting->title}**")
            ->line("Date: {$meeting->scheduled_at->format('l, j F Y')}")
            ->line("Time: {$meeting->scheduled_at->format('g:i A')}")
            ->action('View Board Pack', url("/governance/packs/{$this->pack->id}"))
            ->line('')
            ->line('Please review all materials prior to the meeting.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'board_pack_published',
            'pack_id' => $this->pack->id,
            'meeting_id' => $this->pack->meeting_id,
        ];
    }
}
