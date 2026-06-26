<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Services\LeaveService;
use App\Models\User;
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

        // Need-to-know: don't email the sick / family-violence reason to an approver
        // unless they're HR (manage) or the employee themselves — they approve on the
        // dates + balance, and can read the reason in the HR system if cleared.
        $canSeeReason = ! LeaveService::isSensitiveLeaveType($this->leaveRequest->leave_type)
            || ($notifiable instanceof User && $notifiable->canDo('hr.leave.manage'))
            || ($notifiable instanceof User && $notifiable->getKey() === $this->leaveRequest->user_id);
        $reasonLine = $canSeeReason
            ? '**Reason:** '.($this->leaveRequest->reason ?: 'Not specified')
            : '**Reason:** Restricted — view in the HR system (need-to-know).';

        return (new MailMessage)
            ->subject("Leave Request from {$userName}")
            ->greeting('Hello,')
            ->line('A new leave request requires your approval:')
            ->line("**Employee:** {$userName}")
            ->line("**Type:** {$leaveType}")
            ->line("**From:** {$startDate}")
            ->line("**To:** {$endDate}")
            ->line($reasonLine)
            ->action('Review Request', url("/hr/leave/{$this->leaveRequest->id}"));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'leave_request',
            'leave_type' => $this->leaveRequest->leave_type,
            'starts_at' => $this->leaveRequest->starts_at?->toIso8601String(),
            'ends_at' => $this->leaveRequest->ends_at?->toIso8601String(),
            'user_name' => $this->leaveRequest->user?->name,
            'leave_request_id' => $this->leaveRequest->id,
            'action_url' => "/hr/leave/{$this->leaveRequest->id}",
        ];
    }
}
