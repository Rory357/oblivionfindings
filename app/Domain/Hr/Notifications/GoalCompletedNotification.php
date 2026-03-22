<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrGoal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GoalCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrGoal $goal
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employeeName = $this->goal->user?->name ?? 'An employee';

        return (new MailMessage)
            ->subject("Goal Completed: {$this->goal->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$employeeName} has completed a goal:")
            ->line("**Goal:** {$this->goal->title}")
            ->line("**Category:** " . ucfirst($this->goal->category ?? 'General'))
            ->action('View Goal', url("/hr/goals/{$this->goal->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'          => 'goal_completed',
            'goal_id'       => $this->goal->id,
            'goal_title'    => $this->goal->title,
            'employee_name' => $this->goal->user?->name,
            'action_url'    => "/hr/goals/{$this->goal->id}",
        ];
    }
}
