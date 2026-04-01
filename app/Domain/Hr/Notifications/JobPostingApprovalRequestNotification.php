<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrJobPosting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class JobPostingApprovalRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrJobPosting $posting,
        private User $requestedBy,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Job Posting Pending Approval: {$this->posting->title}")
            ->greeting('Hello,')
            ->line("A job posting requires your approval before it can be published.")
            ->line("**Position:** {$this->posting->title}")
            ->line("**Department:** " . ($this->posting->department ?: 'Not specified'))
            ->line("**Location:** " . ($this->posting->location ?: 'Not specified'))
            ->line("**Requested by:** {$this->requestedBy->name}")
            ->action('Review Posting', url("/hr/job-postings/{$this->posting->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'job_posting_approval_request',
            'posting_id' => $this->posting->id,
            'posting_title' => $this->posting->title,
            'requested_by' => $this->requestedBy->name,
            'action_url' => "/hr/job-postings/{$this->posting->id}",
        ];
    }
}
