<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PipReviewDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $employeeName,
        private string $reviewDate,
        private int $pipId,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'pip_review_due',
            'employee_name' => $this->employeeName,
            'review_date' => $this->reviewDate,
            'pip_id' => $this->pipId,
            'action_url' => "/hr/performance/pips/{$this->pipId}",
        ];
    }
}
