<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrJobPosting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class JobPostingClosingSoonNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrJobPosting $posting,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $closesAt = $this->posting->closes_at?->format('l, j F Y') ?? 'soon';

        return (new MailMessage)
            ->subject("Job Posting Closing Soon: {$this->posting->title}")
            ->greeting('Hello,')
            ->line("The following job posting is closing soon:")
            ->line("**Position:** {$this->posting->title}")
            ->line("**Closing Date:** {$closesAt}")
            ->line("**Applications Received:** {$this->posting->applications_count}")
            ->action('View Posting', url("/hr/job-postings/{$this->posting->id}"))
            ->line('Please review applications and take any necessary action before the posting closes.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'job_posting_closing_soon',
            'posting_id' => $this->posting->id,
            'posting_title' => $this->posting->title,
            'closes_at' => $this->posting->closes_at?->toDateString(),
            'applications_count' => $this->posting->applications_count,
            'action_url' => "/hr/job-postings/{$this->posting->id}",
        ];
    }
}
