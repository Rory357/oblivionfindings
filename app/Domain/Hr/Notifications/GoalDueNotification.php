<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class GoalDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $goalTitle,
        private string $dueDate,
        private int $goalId,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'goal_due',
            'goal_title' => $this->goalTitle,
            'due_date' => $this->dueDate,
            'goal_id' => $this->goalId,
            'action_url' => "/hr/goals/{$this->goalId}",
        ];
    }
}
