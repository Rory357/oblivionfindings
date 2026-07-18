<?php

namespace App\Notifications\It;

use App\Domain\It\Contracts\TracksItEmailDelivery;
use App\Models\ItTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The ticket is fixed. Requester gets the receipt (with a nudge to say how
 * IT did — the CSAT prompt lives on the ticket page); watchers get a plain
 * heads-up. Reference + title only (frontline privacy) — the resolution
 * note itself stays on the thread.
 */
class TicketResolvedNotification extends Notification implements ShouldQueue, TracksItEmailDelivery
{
    use Queueable;

    public function __construct(
        private ItTicket $ticket,
        private string $audience = 'requester', // requester | watcher
    ) {}

    public function itEmailDeliveryContext(): array
    {
        return [
            'tenant_id' => (int) $this->ticket->tenant_id,
            'ticket_id' => (int) $this->ticket->id,
            'audience' => $this->audience,
            'type' => 'ticket_resolved',
            'subject' => "Resolved — {$this->ticket->reference} {$this->ticket->title}",
            'retry_context' => ['audience' => $this->audience],
        ];
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Resolved — {$this->ticket->reference} {$this->ticket->title}")
            ->greeting("Hello {$notifiable->name},");

        if ($this->audience === 'requester') {
            return $mail
                ->line('Your IT ticket has been resolved:')
                ->line("**{$this->ticket->reference}** — {$this->ticket->title}")
                ->line('The fix is described on the ticket. If it is not sorted, you can reopen it within 7 days.')
                ->action('See the resolution', url("/it/tickets/{$this->ticket->id}"))
                ->line('You were notified because you raised this ticket.');
        }

        return $mail
            ->line('A ticket you are watching has been resolved:')
            ->line("**{$this->ticket->reference}** — {$this->ticket->title}")
            ->action('Open the ticket', url("/it/tickets/{$this->ticket->id}"))
            ->line('You were notified as a watcher.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'it_ticket_resolved',
            'audience' => $this->audience,
            'ticket_id' => $this->ticket->id,
            'reference' => $this->ticket->reference,
            'title' => $this->ticket->title,
            'action_url' => "/it/tickets/{$this->ticket->id}",
        ];
    }
}
