<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * In-app nudge from the daily PIP sweep: a milestone's due date has passed and
 * it is still pending review. Sent to the PIP manager and the subject employee.
 */
class PipMilestoneOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private int $pipId,
        private string $pipTitle,
        private string $milestoneTitle,
        private string $dueDate,
        private string $employeeName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'pip_milestone_overdue',
            'pip_id' => $this->pipId,
            'pip_title' => $this->pipTitle,
            'milestone_title' => $this->milestoneTitle,
            'due_date' => $this->dueDate,
            'employee_name' => $this->employeeName,
            'action_url' => "/hr/performance/pips/{$this->pipId}",
        ];
    }
}
