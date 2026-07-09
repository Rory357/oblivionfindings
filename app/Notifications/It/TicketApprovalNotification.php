<?php

namespace App\Notifications\It;

use App\Models\ItTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Approval lifecycle (§P-S3): a ticket needs sign-off (requested → managers),
 * or a request was decided (approved/rejected → the agent who asked).
 * Reference + title only — never ticket body or client detail (§8).
 */
class TicketApprovalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  string  $event  requested | approved | rejected */
    public function __construct(
        private ItTicket $ticket,
        private string $event,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = match ($this->event) {
            'approved' => "Approved — {$this->ticket->reference} {$this->ticket->title}",
            'rejected' => "Rejected — {$this->ticket->reference} {$this->ticket->title}",
            default => "Approval needed — {$this->ticket->reference} {$this->ticket->title}",
        };
        $line = match ($this->event) {
            'approved' => 'Your approval request was approved:',
            'rejected' => 'Your approval request was rejected:',
            default => 'A ticket needs a manager’s approval before it can be resolved:',
        };

        return (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line($line)
            ->line("**{$this->ticket->reference}** — {$this->ticket->title}")
            ->action('Open the ticket', url("/it/tickets/{$this->ticket->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'it_ticket_approval_'.$this->event,
            'ticket_id' => $this->ticket->id,
            'reference' => $this->ticket->reference,
            'title' => $this->ticket->title,
            'event' => $this->event,
            'action_url' => "/it/tickets/{$this->ticket->id}",
        ];
    }
}
