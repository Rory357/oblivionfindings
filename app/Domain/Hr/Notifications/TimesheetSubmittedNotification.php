<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrTimesheet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TimesheetSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrTimesheet $timesheet
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employeeName = $this->timesheet->user?->name ?? 'An employee';
        $period = $this->timesheet->period_start?->format('M j') . ' - ' . $this->timesheet->period_end?->format('M j, Y');

        return (new MailMessage)
            ->subject("Timesheet Submitted for Approval")
            ->greeting('Hello,')
            ->line("A timesheet requires your approval:")
            ->line("**Employee:** {$employeeName}")
            ->line("**Period:** {$period}")
            ->line("**Total Hours:** {$this->timesheet->total_hours}")
            ->action('Review Timesheet', url('/hr/time/timesheets'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'         => 'timesheet_submitted',
            'timesheet_id' => $this->timesheet->id,
            'user_name'    => $this->timesheet->user?->name,
            'period_start' => $this->timesheet->period_start?->toDateString(),
            'period_end'   => $this->timesheet->period_end?->toDateString(),
            'total_hours'  => (float) $this->timesheet->total_hours,
            'action_url'   => '/hr/time/timesheets',
        ];
    }
}
