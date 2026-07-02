<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * In-app nudge from the daily PIP sweep: an active plan's end date is within
 * 7 days (or already past) and it needs a completion outcome recorded.
 * Sent to the PIP manager.
 */
class PipEndingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private int $pipId,
        private string $pipTitle,
        private string $endDate,
        private string $employeeName,
        private bool $overdue,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'pip_ending',
            'pip_id' => $this->pipId,
            'pip_title' => $this->pipTitle,
            'end_date' => $this->endDate,
            'employee_name' => $this->employeeName,
            'overdue' => $this->overdue,
            'action_url' => "/hr/performance/pips/{$this->pipId}",
        ];
    }
}
