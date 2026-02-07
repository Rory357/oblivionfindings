<?php

namespace App\Domain\Governance\Notifications;

use App\Domain\Governance\Models\ActionItem;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
        return (new MailMessage)
            ->subject("Action Item Escalated: {$this->actionItem->action_reference}")
            ->line('An action item assigned to you has been escalated due to being overdue.')
            ->line('')
            ->line("**{$this->actionItem->description}**")
            ->line("Due Date: {$this->actionItem->due_date->format('j F Y')}")
            ->line("Days Overdue: " . now()->diffInDays($this->actionItem->due_date) . " days")
            ->action('View Action Item', url("/governance/actions/{$this->actionItem->id}"))
            ->line('Please complete this action item or contact the board chair if you need assistance.');
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
