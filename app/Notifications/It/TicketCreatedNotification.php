<?php

namespace App\Notifications\It;

use App\Domain\It\Contracts\TracksItEmailDelivery;
use App\Models\ItTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired when a helpdesk ticket is raised. Two audiences, one event:
 *  - `receipt`      → the requester ("we've got it, here's your reference")
 *  - `urgent_alert` → every it.manage agent when the priority is urgent
 *                     (minus the actor — nobody is alerted to their own log).
 *
 * Frontline privacy (loop rule §2.8): subject and body carry the reference
 * and title ONLY — ticket descriptions may name the people we support and
 * never leave the app.
 */
class TicketCreatedNotification extends Notification implements ShouldQueue, TracksItEmailDelivery
{
    use Queueable;

    public function __construct(
        private ItTicket $ticket,
        private string $audience = 'receipt', // receipt | urgent_alert
    ) {}

    public function itEmailDeliveryContext(): array
    {
        return [
            'ticket_id' => (int) $this->ticket->id,
            'audience' => $this->audience,
            'type' => 'ticket_created',
            'subject' => $this->audience === 'urgent_alert'
                ? "Urgent IT ticket {$this->ticket->reference} — {$this->ticket->title}"
                : "Ticket {$this->ticket->reference} raised — {$this->ticket->title}",
            'retry_context' => ['audience' => $this->audience],
        ];
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->audience === 'urgent_alert') {
            return (new MailMessage)
                ->subject("Urgent IT ticket {$this->ticket->reference} — {$this->ticket->title}")
                ->greeting("Hello {$notifiable->name},")
                ->line('An urgent helpdesk ticket has just been raised and needs triage:')
                ->line("**{$this->ticket->reference}** — {$this->ticket->title}")
                ->action('Open the queue', url('/it?tab=tickets&view=unassigned'))
                ->line('You were notified because you work the IT queue.');
        }

        return (new MailMessage)
            ->subject("Ticket {$this->ticket->reference} raised — {$this->ticket->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line('Your IT ticket is in the queue — IT can see it now.')
            ->line("**{$this->ticket->reference}** — {$this->ticket->title}")
            ->line('We will email you when it is picked up or resolved.')
            ->action('Track it in My tickets', url('/it?tab=my-tickets'))
            ->line('You were sent this receipt because you raised the ticket.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'it_ticket_created',
            'audience' => $this->audience,
            'ticket_id' => $this->ticket->id,
            'reference' => $this->ticket->reference,
            'title' => $this->ticket->title,
            'priority' => $this->ticket->priority,
            'action_url' => $this->audience === 'urgent_alert'
                ? '/it?tab=tickets&view=unassigned'
                : '/it?tab=my-tickets',
        ];
    }
}
