<?php

namespace App\Domain\Governance\Notifications;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Domain\Governance\Support\GovernanceWording;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells an action's owner the overdue sweep raised it with the board. */
class ActionItemEscalatedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ActionItem $actionItem
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->actionItem->title;
        $due = GovernanceLabels::date($this->actionItem->due_date?->toDateString());
        $overdueDays = GovernanceWording::daysFromToday($this->actionItem->due_date?->toDateString());

        return (new MailMessage)
            ->subject("Overdue action raised with the board: {$title}")
            ->line('An action you own is overdue, so it has been raised with the board automatically.')
            ->line("**{$title}**")
            ->line("Due: {$due}".($overdueDays !== null && $overdueDays < 0
                ? ' ('.GovernanceWording::count(abs($overdueDays), 'day').' overdue)'
                : ''))
            ->action('Open the action', url("/governance/actions/{$this->actionItem->id}"))
            ->line('Please update its progress or mark it as done. If you need help, contact the board chair.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'action_item_escalated',
            'action_id' => $this->actionItem->id,
            'reference' => $this->actionItem->action_reference,
        ];
    }
}
