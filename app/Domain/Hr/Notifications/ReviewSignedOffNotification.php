<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrPerformanceReview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReviewSignedOffNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrPerformanceReview $review,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employeeName = $this->review->employee?->name ?? 'Your team member';
        $reviewType = ucfirst(str_replace('_', ' ', (string) $this->review->review_type)) ?: 'Performance review';

        return (new MailMessage)
            ->subject("Review Signed Off — {$employeeName}")
            ->greeting('Hello,')
            ->line("{$employeeName} has signed off on their {$reviewType}.")
            ->line('Their acknowledgement (and any comments they added) is now recorded on the review.')
            ->action('View Review', url("/hr/performance/reviews/{$this->review->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'review_signed_off',
            'review_id' => $this->review->id,
            'review_type' => $this->review->review_type,
            'employee_name' => $this->review->employee?->name,
            'action_url' => "/hr/performance/reviews/{$this->review->id}",
        ];
    }
}
