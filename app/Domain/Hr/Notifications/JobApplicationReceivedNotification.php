<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrJobPosting;
use App\Domain\Hr\Models\HrJobRequisition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class JobApplicationReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrJobPosting|HrJobRequisition $posting,
        private HrCandidate $candidate,
        private HrApplication $application,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = trim($this->candidate->first_name . ' ' . $this->candidate->last_name);

        return (new MailMessage)
            ->subject("New Application: {$this->posting->title}")
            ->greeting('Hello,')
            ->line("A new application has been received for **{$this->posting->title}**.")
            ->line("**Candidate:** {$name}")
            ->line("**Email:** {$this->candidate->personal_email}")
            ->line("**Phone:** " . ($this->candidate->personal_phone ?: 'Not provided'))
            ->action('View in Recruitment', url("/hr/recruitment"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'job_application_received',
            'posting_id' => $this->posting->id,
            'posting_title' => $this->posting->title,
            'candidate_id' => $this->candidate->id,
            'candidate_name' => trim($this->candidate->first_name . ' ' . $this->candidate->last_name),
            'application_id' => $this->application->id,
            'action_url' => '/hr/recruitment',
        ];
    }
}
