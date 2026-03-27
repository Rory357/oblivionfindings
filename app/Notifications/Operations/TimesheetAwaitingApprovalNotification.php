<?php

namespace App\Notifications\Operations;

use App\Models\Timesheet;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TimesheetAwaitingApprovalNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Timesheet $timesheet,
        public string $submitterName
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Timesheet Awaiting Approval: {$this->submitterName}")
            ->greeting("Kia ora {$notifiable->name},")
            ->line(sprintf(
                '%s has submitted a timesheet for %s that requires your approval.',
                $this->submitterName,
                $this->timesheet->work_date?->format('d M Y') ?? 'a shift'
            ))
            ->action('Review Timesheet', url('/operations/timesheets?mode=approvals'))
            ->line('Please review and approve or return the timesheet.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'timesheet_id' => $this->timesheet->id,
            'submitter' => $this->submitterName,
            'work_date' => $this->timesheet->work_date?->toDateString(),
            'client_id' => $this->timesheet->client_id,
        ];
    }
}
