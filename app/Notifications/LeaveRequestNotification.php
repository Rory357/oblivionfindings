<?php

namespace App\Notifications;

use App\Models\NotificationTemplate;
use App\Services\TemplateRenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class LeaveRequestNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $staffName,
        public string $leaveType,
        public string $startDate,
        public string $endDate,
        public string $status = 'pending',
        public ?int $leaveRequestId = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->status === 'pending') {
            $template = NotificationTemplate::findByKey('leave_request_pending');

            if ($template && $template->is_active) {
                $service = app(TemplateRenderService::class);
                $context = [
                    'leave_type' => $this->leaveType,
                    'dates' => "{$this->startDate} to {$this->endDate}",
                    'recipient' => $this->staffName,
                ];

                $body = $service->render($template, $notifiable, $context);
                $subject = $service->renderSubject($template, $notifiable, $context);

                return (new MailMessage)
                    ->subject($subject)
                    ->line(new HtmlString(nl2br(e($body))));
            }

            // Fallback for pending
            return (new MailMessage)
                ->subject("Leave Request: {$this->staffName} ({$this->leaveType})")
                ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
                ->line("**{$this->staffName}** has requested **{$this->leaveType}** leave.")
                ->line("From: {$this->startDate}")
                ->line("To: {$this->endDate}")
                ->action('Review Leave Request', url('/operations/leave-requests'))
                ->line('Please review and respond to this request.');
        }

        // Approved / declined
        $templateKey = $this->status === 'approved' ? 'leave_approved' : 'leave_declined';
        $template = NotificationTemplate::findByKey($templateKey);

        if ($template && $template->is_active) {
            $service = app(TemplateRenderService::class);
            $context = [
                'leave_type' => $this->leaveType,
                'dates' => "{$this->startDate} to {$this->endDate}",
                'approver' => '',
                'reason' => '',
            ];

            $body = $service->render($template, $notifiable, $context);
            $subject = $service->renderSubject($template, $notifiable, $context);

            return (new MailMessage)
                ->subject($subject)
                ->line(new HtmlString(nl2br(e($body))));
        }

        // Fallback for approved/declined
        $decision = ucfirst($this->status);

        return (new MailMessage)
            ->subject("Leave Request {$decision}")
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line("Your **{$this->leaveType}** leave request from {$this->startDate} to {$this->endDate} has been **{$this->status}**.")
            ->action('View Leave Requests', url('/operations/leave-requests'))
            ->line('Contact your manager if you have any questions.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->status === 'pending' ? 'Leave Request Received' : 'Leave Request ' . ucfirst($this->status),
            'message' => $this->status === 'pending'
                ? "{$this->staffName} requested {$this->leaveType} leave from {$this->startDate} to {$this->endDate}."
                : "Your {$this->leaveType} leave request ({$this->startDate} - {$this->endDate}) has been {$this->status}.",
            'staff_name' => $this->staffName,
            'leave_type' => $this->leaveType,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'status' => $this->status,
            'leave_request_id' => $this->leaveRequestId,
        ];
    }
}
