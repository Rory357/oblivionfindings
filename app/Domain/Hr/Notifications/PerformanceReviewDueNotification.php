<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PerformanceReviewDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $reviewType,
        private string $employeeName,
        private string $dueDate,
        private ?int $reviewId = null
    ) {}

    /**
     * Database only - no mail for performance review reminders.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $reviewType = ucfirst(str_replace('_', ' ', $this->reviewType));

        return [
            'type'          => 'performance_review_due',
            'title'         => "Performance Review Due: {$this->employeeName}",
            'message'       => "A {$reviewType} review for {$this->employeeName} is due on {$this->dueDate}.",
            'review_type'   => $this->reviewType,
            'employee_name' => $this->employeeName,
            'due_date'      => $this->dueDate,
            'review_id'     => $this->reviewId,
            'url'           => $this->reviewId
                ? "/hr/performance-reviews/{$this->reviewId}"
                : '/hr/performance-reviews',
            'context'       => [
                'Review type' => $reviewType,
                'Employee' => $this->employeeName,
                'Due date' => $this->dueDate,
            ],
        ];
    }
}
