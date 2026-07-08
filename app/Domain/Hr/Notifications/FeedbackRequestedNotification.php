<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrFeedbackRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent to a reviewer the moment a 360-feedback request is raised for them —
 * the counterpart to FeedbackReminderNotification (which only nudges later).
 * Without this, a reviewer only learned they'd been asked if a manager
 * happened to remind them.
 */
class FeedbackRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrFeedbackRequest $request,
        private string $subjectName,
    ) {}

    /**
     * Database only — surfaces in the reviewer's notification feed, matching
     * FeedbackReminderNotification.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'feedback_requested',
            'subject_name' => $this->subjectName,
            'review_type' => $this->request->review_type,
            'due_date' => $this->request->due_date?->toDateString(),
            'request_id' => $this->request->id,
            'action_url' => "/hr/feedback/{$this->request->id}/respond",
        ];
    }
}
