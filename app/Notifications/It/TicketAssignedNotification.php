<?php

namespace App\Notifications\It;

use App\Domain\It\Contracts\TracksItEmailDelivery;
use App\Models\ItTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an agent a ticket just landed on their plate. Reference + title
 * only (frontline privacy) — the detail lives behind the deep link.
 */
class TicketAssignedNotification extends Notification implements ShouldQueue, TracksItEmailDelivery
{
    use Queueable;

    public function __construct(
        private ItTicket $ticket,
    ) {}

    public function itEmailDeliveryContext(): array
    {
        return [
            'ticket_id' => (int) $this->ticket->id,
            'type' => 'ticket_assigned',
            'subject' => "Assigned to you — {$this->ticket->reference} {$this->ticket->title}",
        ];
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Assigned to you — {$this->ticket->reference} {$this->ticket->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line('An IT ticket has been assigned to you:')
            ->line("**{$this->ticket->reference}** — {$this->ticket->title}")
            ->action('Open the ticket', url("/it/tickets/{$this->ticket->id}"))
            ->line('You were notified because the ticket was assigned to you.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'it_ticket_assigned',
            'ticket_id' => $this->ticket->id,
            'reference' => $this->ticket->reference,
            'title' => $this->ticket->title,
            'priority' => $this->ticket->priority,
            'action_url' => "/it/tickets/{$this->ticket->id}",
        ];
    }
}
