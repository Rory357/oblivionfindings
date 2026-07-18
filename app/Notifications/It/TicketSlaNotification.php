<?php

namespace App\Notifications\It;

use App\Domain\It\Contracts\TracksItEmailDelivery;
use App\Models\ItTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The SLA scheduler's voice: a ticket is running out of road (at_risk),
 * has run out (breached), or nobody owns an urgent one (escalation).
 * Reference + title only — frontline privacy keeps client details out of
 * inboxes.
 */
class TicketSlaNotification extends Notification implements ShouldQueue, TracksItEmailDelivery
{
    use Queueable;

    public function __construct(
        private ItTicket $ticket,
        private string $transition = 'at_risk', // at_risk | breached | escalation
        private ?string $clock = null, // first_response | resolution (null for escalation)
    ) {}

    public function itEmailDeliveryContext(): array
    {
        $subject = match ($this->transition) {
            'breached' => "SLA breached — {$this->ticket->reference} {$this->ticket->title}",
            'escalation' => "Unassigned urgent ticket — {$this->ticket->reference} {$this->ticket->title}",
            default => "SLA at risk — {$this->ticket->reference} {$this->ticket->title}",
        };

        return [
            'tenant_id' => (int) $this->ticket->tenant_id,
            'ticket_id' => (int) $this->ticket->id,
            'type' => 'ticket_sla',
            'subject' => $subject,
            'retry_context' => [
                'transition' => $this->transition,
                'clock' => $this->clock,
            ],
        ];
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $clockLabel = $this->clock === 'first_response' ? 'first response' : 'resolution';
        $mail = (new MailMessage)->greeting("Hello {$notifiable->name},");

        return match ($this->transition) {
            'breached' => $mail
                ->subject("SLA breached — {$this->ticket->reference} {$this->ticket->title}")
                ->error()
                ->line("The {$clockLabel} target has been missed on:")
                ->line("**{$this->ticket->reference}** — {$this->ticket->title}")
                ->action('Open the ticket', url("/it/tickets/{$this->ticket->id}"))
                ->line('Jump in or reassign — the requester is still waiting.'),
            'escalation' => $mail
                ->subject("Unassigned urgent ticket — {$this->ticket->reference} {$this->ticket->title}")
                ->error()
                ->line('An urgent ticket has sat unassigned for over 30 minutes:')
                ->line("**{$this->ticket->reference}** — {$this->ticket->title}")
                ->action('Assign it now', url("/it/tickets/{$this->ticket->id}"))
                ->line('You were notified as an administrator — nobody owns this yet.'),
            default => $mail
                ->subject("SLA at risk — {$this->ticket->reference} {$this->ticket->title}")
                ->line("The {$clockLabel} target is nearly out of time on:")
                ->line("**{$this->ticket->reference}** — {$this->ticket->title}")
                ->action('Open the ticket', url("/it/tickets/{$this->ticket->id}"))
                ->line('You were notified as the assignee.'),
        };
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'it_ticket_sla_'.$this->transition,
            'transition' => $this->transition,
            'clock' => $this->clock,
            'ticket_id' => $this->ticket->id,
            'reference' => $this->ticket->reference,
            'title' => $this->ticket->title,
            'action_url' => "/it/tickets/{$this->ticket->id}",
        ];
    }
}
