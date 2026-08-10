<?php

namespace App\Notifications\It;

use App\Domain\It\Contracts\TracksItEmailDelivery;
use App\Models\ItTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A settled ticket is back: tells the assignee their fix didn't stick (or
 * an agent reopened it). Reference + title only.
 */
class TicketReopenedNotification extends Notification implements ShouldQueue, TracksItEmailDelivery
{
    use Queueable;

    public function __construct(
        private ItTicket $ticket,
    ) {}

    public function itEmailDeliveryContext(): array
    {
        return [
            'ticket_id' => (int) $this->ticket->id,
            'type' => 'ticket_reopened',
            'subject' => "Reopened — {$this->ticket->reference} {$this->ticket->title}",
        ];
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Reopened — {$this->ticket->reference} {$this->ticket->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line('A resolved ticket assigned to you has been reopened:')
            ->line("**{$this->ticket->reference}** — {$this->ticket->title}")
            ->action('Open the ticket', url("/it/tickets/{$this->ticket->id}"))
            ->line('You were notified because the ticket is assigned to you.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'it_ticket_reopened',
            'ticket_id' => $this->ticket->id,
            'reference' => $this->ticket->reference,
            'title' => $this->ticket->title,
            'action_url' => "/it/tickets/{$this->ticket->id}",
        ];
    }
}
