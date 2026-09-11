<?php

namespace App\Notifications\It;

use App\Domain\It\Contracts\TracksItEmailDelivery;
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
class TicketApprovalNotification extends Notification implements ShouldQueue, TracksItEmailDelivery
{
    use Queueable;

    /** @param  string  $event  requested | reminder | approved | rejected | expired | cancelled */
    public function __construct(
        private ItTicket $ticket,
        private string $event,
        private ?int $approvalId = null,
    ) {}

    public function itEmailDeliveryContext(): array
    {
        $subject = match ($this->event) {
            'approved' => "Approved — {$this->ticket->reference} {$this->ticket->title}",
            'rejected' => "Rejected — {$this->ticket->reference} {$this->ticket->title}",
            'expired' => "Approval expired — {$this->ticket->reference} {$this->ticket->title}",
            'cancelled' => "Approval cancelled — {$this->ticket->reference} {$this->ticket->title}",
            'reminder' => "Approval reminder — {$this->ticket->reference} {$this->ticket->title}",
            default => "Approval needed — {$this->ticket->reference} {$this->ticket->title}",
        };

        return [
            'ticket_id' => (int) $this->ticket->id,
            'type' => 'ticket_approval',
            'subject' => $subject,
            'retry_context' => ['event' => $this->event, 'approval_id' => $this->approvalId],
        ];
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = match ($this->event) {
            'approved' => "Approved — {$this->ticket->reference} {$this->ticket->title}",
            'rejected' => "Rejected — {$this->ticket->reference} {$this->ticket->title}",
            'expired' => "Approval expired — {$this->ticket->reference} {$this->ticket->title}",
            'cancelled' => "Approval cancelled — {$this->ticket->reference} {$this->ticket->title}",
            'reminder' => "Approval reminder — {$this->ticket->reference} {$this->ticket->title}",
            default => "Approval needed — {$this->ticket->reference} {$this->ticket->title}",
        };
        $line = match ($this->event) {
            'approved' => 'Your approval request was approved:',
            'rejected' => 'Your approval request was rejected:',
            'expired' => 'The approval deadline passed without a decision. Review the request before arranging a new approval:',
            'cancelled' => 'This pending approval was cancelled. Its request history is retained:',
            'reminder' => 'You are currently responsible for this pending approval:',
            default => 'A ticket needs a manager’s approval before it can be resolved:',
        };

        return (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line($line)
            ->line("**{$this->ticket->reference}** — {$this->ticket->title}")
            ->action('Review approval', url("/it/tickets/{$this->ticket->id}?tab=approvals"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'it_ticket_approval_'.$this->event,
            'ticket_id' => $this->ticket->id,
            'reference' => $this->ticket->reference,
            'title' => $this->ticket->title,
            'event' => $this->event,
            'approval_id' => $this->approvalId,
            'action_url' => "/it/tickets/{$this->ticket->id}?tab=approvals",
        ];
    }
}
