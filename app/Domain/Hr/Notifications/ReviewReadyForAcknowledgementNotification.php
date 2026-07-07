<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrPerformanceReview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the employee when their manager signs off their performance review —
 * the employee is now the waiting party: they need to read it and acknowledge.
 */
class ReviewReadyForAcknowledgementNotification extends Notification implements ShouldQueue
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
        $reviewType = ucfirst(str_replace('_', ' ', (string) $this->review->review_type)) ?: 'performance review';

        return (new MailMessage)
            ->subject('Your performance review is ready to acknowledge')
            ->greeting("Hello {$notifiable->name},")
            ->line("Your {$reviewType} has been signed off by your manager.")
            ->line('Please read it and record your acknowledgement (you can add your own comments too).')
            ->action('View my review', url('/hr/my/reviews'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'review_ready_for_acknowledgement',
            'review_id' => $this->review->id,
            'review_type' => $this->review->review_type,
            'action_url' => '/hr/my/reviews',
        ];
    }
}
