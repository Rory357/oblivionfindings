<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrLeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveRequestNotification extends Notification implements ShouldQueue
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
        $userName = $this->leaveRequest->user?->name ?? 'A staff member';
        $leaveType = ucfirst(str_replace('_', ' ', $this->leaveRequest->leave_type));
        $startDate = $this->leaveRequest->starts_at?->format('l, F j, Y') ?? 'N/A';
        $endDate = $this->leaveRequest->ends_at?->format('l, F j, Y') ?? 'N/A';

        return (new MailMessage)
            ->subject("Leave Request from {$userName}")
            ->greeting('Hello,')
            ->line("A new leave request requires your approval:")
            ->line("**Employee:** {$userName}")
            ->line("**Type:** {$leaveType}")
            ->line("**From:** {$startDate}")
            ->line("**To:** {$endDate}")
            ->line("**Reason:** " . ($this->leaveRequest->reason ?: 'Not specified'))
            ->action('Review Request', url("/hr/leave/{$this->leaveRequest->id}"));
    }

    public function toArray(object $notifiable): array
    {
        $userName = $this->leaveRequest->user?->name ?? 'A staff member';
        $leaveType = ucfirst(str_replace('_', ' ', $this->leaveRequest->leave_type));

        return [
            'type'             => 'leave_request',
            'title'            => "Leave Request: {$userName}",
            'message'          => "{$userName} has requested {$leaveType} leave and needs your approval.",
            'leave_type'       => $this->leaveRequest->leave_type,
            'starts_at'        => $this->leaveRequest->starts_at?->toIso8601String(),
            'ends_at'          => $this->leaveRequest->ends_at?->toIso8601String(),
            'user_name'        => $userName,
            'leave_request_id' => $this->leaveRequest->id,
            'url'              => "/hr/leave/{$this->leaveRequest->id}",
            'context'          => [
                'Type' => $leaveType,
                'From' => $this->leaveRequest->starts_at?->format('d M Y') ?? 'N/A',
                'To' => $this->leaveRequest->ends_at?->format('d M Y') ?? 'N/A',
            ],
        ];
    }
}
