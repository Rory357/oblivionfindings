<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrJobRequisition;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the hiring manager a requisition needs their sign-off before it can be
 * published (D7 / handover item 5). Sent to a real User.
 */
class RequisitionApprovalRequestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrJobRequisition $requisition,
        private User $requestedBy,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Requisition pending approval: {$this->requisition->title}")
            ->greeting('Kia ora,')
            ->line('A job requisition needs your approval before it can be published.')
            ->line("**Role:** {$this->requisition->title}")
            ->line("**Openings:** {$this->requisition->openings}")
            ->line("**Requested by:** {$this->requestedBy->name}")
            ->action('Review in Recruitment', url('/hr/recruitment?tab=requisitions'))
            ->salutation('Ngā mihi, The Recruitment Team');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'requisition_approval_request',
            'requisition_id' => $this->requisition->id,
            'requisition_title' => $this->requisition->title,
            'requested_by' => $this->requestedBy->name,
        ];
    }
}
