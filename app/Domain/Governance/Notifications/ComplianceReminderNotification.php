<?php

namespace App\Domain\Governance\Notifications;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\ComplianceReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ComplianceReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ComplianceReminder $reminder,
        public ComplianceObligation $obligation,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $due = $this->obligation->due_date?->format('j F Y') ?? 'soon';
        $lead = $this->reminder->is_escalation
            ? 'A compliance obligation you are responsible for is overdue and has been escalated to you:'
            : 'A compliance obligation you own is coming due:';

        return (new MailMessage)
            ->subject(($this->reminder->is_escalation ? 'Escalated: ' : 'Reminder: ')."{$this->obligation->obligation_title} due {$due}")
            ->line($lead)
            ->line("**{$this->obligation->obligation_title}**")
            ->line('Framework: '.$this->obligation->getFrameworkLabel())
            ->line("Due: {$due}")
            ->action('View obligation', url("/governance/compliance/{$this->obligation->id}"))
            ->line('Please complete or sign off the obligation before its due date.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'compliance_reminder',
            'obligation_id' => $this->obligation->id,
            'obligation_title' => $this->obligation->obligation_title,
            'framework' => $this->obligation->framework,
            'due_date' => $this->obligation->due_date?->toDateString(),
            'is_escalation' => (bool) $this->reminder->is_escalation,
            'escalation_level' => (int) $this->reminder->escalation_level,
        ];
    }
}
