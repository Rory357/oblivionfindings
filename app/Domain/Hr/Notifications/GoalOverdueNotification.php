<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrGoal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** An active objective is past its due date. */
class GoalOverdueNotification extends Notification
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
            ->subject('Overdue objective: '.$this->goal->title)
            ->line('An objective you own is past its due date and still active.')
            ->line('Objective: '.$this->goal->title)
            ->line('Due: '.optional($this->goal->due_date)->format('d/m/Y'))
            ->action('Review objective', url("/hr/goals/{$this->goal->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'hr_goal_overdue',
            'title' => 'Overdue objective: '.$this->goal->title,
            'message' => 'An objective you own is past its due date.',
            'url' => "/hr/goals/{$this->goal->id}",
            'goal_id' => $this->goal->id,
            'due_date' => optional($this->goal->due_date)->toDateString(),
        ];
    }
}
