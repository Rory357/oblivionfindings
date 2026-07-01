<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrGoal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fires when an OKR objective is assigned to an owner, or when a manager
 * requests a check-in via the bulk bar.
 */
class GoalAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly HrGoal $goal,
        private readonly bool $checkinReminder = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url("/hr/goals/{$this->goal->id}");

        if ($this->checkinReminder) {
            return (new MailMessage)
                ->subject('Check-in requested: '.$this->goal->title)
                ->line('A check-in has been requested on an objective you own.')
                ->line('Objective: '.$this->goal->title)
                ->action('Log check-in', $url);
        }

        return (new MailMessage)
            ->subject('New objective assigned: '.$this->goal->title)
            ->line('A new objective has been assigned to you.')
            ->line('Objective: '.$this->goal->title)
            ->action('View objective', $url);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->checkinReminder ? 'hr_goal_checkin_requested' : 'hr_goal_assigned',
            'title' => ($this->checkinReminder ? 'Check-in requested: ' : 'Objective assigned: ').$this->goal->title,
            'message' => $this->checkinReminder
                ? 'A check-in has been requested on an objective you own.'
                : 'A new objective has been assigned to you.',
            'url' => "/hr/goals/{$this->goal->id}",
            'context' => [
                'Objective' => $this->goal->title,
                'Due date' => optional($this->goal->due_date)->toDateString() ?? 'Not set',
            ],
            'goal_id' => $this->goal->id,
            'due_date' => optional($this->goal->due_date)->toDateString(),
        ];
    }
}
