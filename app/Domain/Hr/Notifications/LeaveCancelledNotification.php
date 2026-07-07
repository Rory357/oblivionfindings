<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrLeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  bool  $wasApproved  Whether the request was already approved when it
     *                             was cancelled — approved cancellations put the
     *                             person back on the roster for those dates.
     */
    public function __construct(
        private HrLeaveRequest $leaveRequest,
        private bool $wasApproved,
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

        $impactLine = $this->wasApproved
            ? 'This leave was already approved — the booking has been removed from the roster and the hours returned to their balance.'
            : 'This request was still awaiting approval — no action is needed.';

        return (new MailMessage)
            ->subject("Leave Request Cancelled — {$userName}")
            ->greeting('Hello,')
            ->line("{$userName} has cancelled their leave request:")
            ->line("**Type:** {$leaveType}")
            ->line("**From:** {$startDate}")
            ->line("**To:** {$endDate}")
            ->line($impactLine)
            ->action('View Leave', url('/hr/leave'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'leave_cancelled',
            'leave_type' => $this->leaveRequest->leave_type,
            'starts_at' => $this->leaveRequest->starts_at?->toIso8601String(),
            'ends_at' => $this->leaveRequest->ends_at?->toIso8601String(),
            'user_name' => $this->leaveRequest->user?->name,
            'was_approved' => $this->wasApproved,
            'leave_request_id' => $this->leaveRequest->id,
            'action_url' => '/hr/leave',
        ];
    }
}
