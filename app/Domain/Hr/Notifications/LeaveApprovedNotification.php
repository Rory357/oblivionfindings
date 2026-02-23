<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrLeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrLeaveRequest $leaveRequest
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $leaveType = ucfirst(str_replace('_', ' ', $this->leaveRequest->leave_type));
        $startDate = $this->leaveRequest->starts_at?->format('l, F j, Y') ?? 'N/A';
        $endDate = $this->leaveRequest->ends_at?->format('l, F j, Y') ?? 'N/A';
        $reviewer = $this->leaveRequest->reviewer?->name ?? 'Your manager';

        return (new MailMessage)
            ->subject('Leave Request Approved')
            ->greeting("Hello {$notifiable->name},")
            ->line('Your leave request has been approved.')
            ->line("**Type:** {$leaveType}")
            ->line("**From:** {$startDate}")
            ->line("**To:** {$endDate}")
            ->line("**Approved by:** {$reviewer}")
            ->action('View Leave Details', url("/hr/leave/{$this->leaveRequest->id}"))
            ->line('If you have any questions, please contact your manager.');
    }

    public function toArray(object $notifiable): array
    {
        $leaveType = ucfirst(str_replace('_', ' ', $this->leaveRequest->leave_type));
        $reviewer = $this->leaveRequest->reviewer?->name ?? 'Your manager';

        return [
            'type'             => 'leave_approved',
            'title'            => 'Leave Approved',
            'message'          => "Your {$leaveType} leave request has been approved by {$reviewer}.",
            'leave_type'       => $this->leaveRequest->leave_type,
            'starts_at'        => $this->leaveRequest->starts_at?->toIso8601String(),
            'ends_at'          => $this->leaveRequest->ends_at?->toIso8601String(),
            'status'           => 'approved',
            'leave_request_id' => $this->leaveRequest->id,
            'url'              => "/hr/leave/{$this->leaveRequest->id}",
            'context'          => [
                'Type' => $leaveType,
                'From' => $this->leaveRequest->starts_at?->format('d M Y') ?? 'N/A',
                'To' => $this->leaveRequest->ends_at?->format('d M Y') ?? 'N/A',
                'Approved by' => $reviewer,
            ],
        ];
    }
}
