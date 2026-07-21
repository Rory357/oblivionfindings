<?php

namespace App\Notifications\It;

use App\Domain\It\Contracts\TracksItEmailDelivery;
use App\Models\ItTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A PUBLIC reply landed on the thread — requester hears about agent
 * replies; the assignee + watchers hear about requester replies. Internal
 * notes never notify. Reference + title only (frontline privacy): the reply
 * body may name the people we support and never leaves the app.
 */
class TicketRepliedNotification extends Notification implements ShouldQueue, TracksItEmailDelivery
{
    use Queueable;

    public function __construct(
        private ItTicket $ticket,
        private string $audience = 'requester', // requester | agent_side
        private ?int $commentId = null,
    ) {}

    public function itEmailDeliveryContext(): array
    {
        return [
            'ticket_id' => (int) $this->ticket->id,
            'comment_id' => $this->commentId,
            'audience' => $this->audience,
            'type' => 'ticket_replied',
            'subject' => "New reply — {$this->ticket->reference} {$this->ticket->title}",
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
            ->subject("New reply — {$this->ticket->reference} {$this->ticket->title}")
            ->greeting("Hello {$notifiable->name},");

        if ($this->audience === 'requester') {
            return $mail
                ->line('IT has replied on your ticket:')
                ->line("**{$this->ticket->reference}** — {$this->ticket->title}")
                ->action('Read the reply', url("/it/tickets/{$this->ticket->id}"))
                ->line('You were notified because you raised this ticket.');
        }

        return $mail
            ->line('The requester has replied on a ticket you are involved with:')
            ->line("**{$this->ticket->reference}** — {$this->ticket->title}")
            ->action('Open the ticket', url("/it/tickets/{$this->ticket->id}"))
            ->line('You were notified as the assignee or a watcher.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'it_ticket_replied',
            'audience' => $this->audience,
            'ticket_id' => $this->ticket->id,
            'reference' => $this->ticket->reference,
            'title' => $this->ticket->title,
            'action_url' => "/it/tickets/{$this->ticket->id}",
        ];
    }
}
