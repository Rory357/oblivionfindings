<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrJobRequisition;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the hiring manager their candidate has been converted to an employee.
 * Sent to a real User (the hiring manager) on successful convert.
 */
class CandidateHiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrCandidate $candidate,
        private ?HrJobRequisition $requisition = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $role = $this->requisition?->title ?: 'a role you are hiring for';

        return (new MailMessage)
            ->subject("New hire confirmed — {$this->candidate->full_name}")
            ->greeting('Kia ora,')
            ->line("**{$this->candidate->full_name}** has accepted and been converted to an employee for **{$role}**.")
            ->line('Their staff profile and onboarding have been created.')
            ->action('View in People', url('/hr/people'))
            ->salutation('Ngā mihi, The Recruitment Team');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'candidate_hired',
            'candidate_id' => $this->candidate->id,
            'candidate_name' => $this->candidate->full_name,
            'requisition_id' => $this->requisition?->id,
            'requisition_title' => $this->requisition?->title,
        ];
    }
}
