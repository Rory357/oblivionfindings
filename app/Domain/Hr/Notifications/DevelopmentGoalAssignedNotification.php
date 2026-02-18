<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrDevelopmentGoal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DevelopmentGoalAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly HrDevelopmentGoal $goal,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Development Goal Assigned')
            ->line('A new development goal has been assigned to you.')
            ->line('Goal: ' . $this->goal->title)
            ->action('View Goal', url('/hr/development/goals'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'hr_development_goal_assigned',
            'goal_id' => $this->goal->id,
            'title' => $this->goal->title,
            'due_date' => optional($this->goal->due_date)->toDateString(),
            'url' => '/hr/development/goals',
        ];
    }
}
