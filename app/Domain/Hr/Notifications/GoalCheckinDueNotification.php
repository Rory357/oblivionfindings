<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrGoal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A check-in is due on an objective (cadence elapsed since the last one). */
class GoalCheckinDueNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly HrGoal $goal) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Check-in due: '.$this->goal->title)
            ->line('A check-in is due on an objective you own.')
            ->line('Objective: '.$this->goal->title)
            ->action('Log check-in', url("/hr/goals/{$this->goal->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'hr_goal_checkin_due',
            'title' => 'Check-in due: '.$this->goal->title,
            'message' => 'A check-in is due on an objective you own.',
            'url' => "/hr/goals/{$this->goal->id}",
            'goal_id' => $this->goal->id,
        ];
    }
}
