<?php

namespace App\Domain\Governance\Notifications;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Support\GovernanceLabels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Asks the board chair or secretary to look at an action a person raised with
 * the board ("Raise with the board" on the action page). Email plus an in-app
 * notification — no push.
 */
class ActionItemRaisedWithBoardNotification extends Notification
{
    use Queueable;

    public function __construct(
        public ActionItem $actionItem,
        public ?string $raisedByName = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $action = $this->actionItem;
        $who = $this->raisedByName ?: 'A board member';

        return (new MailMessage)
            ->subject("Action raised with the board: {$action->title}")
            ->line("{$who} has asked the board to look at this action.")
            ->line("**{$action->title}**")
            ->line('Why: '.($action->escalation_reason ?: 'No reason given.'))
            ->line('Owner: '.($action->assignedTo?->name ?? 'Nobody yet'))
            ->line('Due: '.GovernanceLabels::date($action->due_date?->toDateString()))
            ->action('Open the action', url("/governance/actions/{$action->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'action_item_raised_with_board',
            'action_id' => $this->actionItem->id,
            'title' => 'Action raised with the board',
            'message' => ($this->raisedByName ?: 'A board member')." asked the board to look at \"{$this->actionItem->title}\".",
            'action_url' => "/governance/actions/{$this->actionItem->id}",
        ];
    }
}
