<?php

namespace App\Domain\Governance\Notifications;

use App\Domain\Governance\Models\PerformanceReview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CEOReviewMilestoneNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected PerformanceReview $review,
        protected string $milestone,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $message = match($this->milestone) {
            'self_review_due' => 'CEO self-assessment is due for the current review period.',
            'board_review_ready' => 'CEO performance review is ready for board review.',
            'feedback_requested' => 'Your feedback is requested for the CEO performance review.',
            'review_complete' => 'CEO performance review has been completed.',
            default => 'CEO performance review milestone update.',
        };

        return (new MailMessage)
            ->subject("CEO Performance Review: " . ucfirst(str_replace('_', ' ', $this->milestone)))
            ->line($message)
            ->line("Review period: {$this->review->period_start->format('M Y')} - {$this->review->period_end->format('M Y')}")
            ->action('View Review', url("/governance/performance/{$this->review->id}"));
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'ceo_review_milestone',
            'title' => 'CEO Review: ' . ucfirst(str_replace('_', ' ', $this->milestone)),
            'message' => 'A CEO review milestone has been reached.',
            'url' => "/governance/performance-reviews/{$this->review->id}",
            'context' => [
                'Milestone' => ucfirst(str_replace('_', ' ', $this->milestone)),
            ],
            'review_id' => $this->review->id,
            'milestone' => $this->milestone,
        ];
    }
}
