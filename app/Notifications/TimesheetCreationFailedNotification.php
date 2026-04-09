<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TimesheetCreationFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $shiftId,
        public string $staffName,
        public string $shiftDate,
        public string $siteName,
        public string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Timesheet Creation Failed: {$this->staffName} on {$this->shiftDate}")
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line("A shift at **{$this->siteName}** on **{$this->shiftDate}** for **{$this->staffName}** was completed, but the draft timesheet could not be created automatically.")
            ->line("**Reason:** {$this->reason}")
            ->action('View Shift', url("/shifts/{$this->shiftId}"))
            ->line('Please create the timesheet manually or retry from the shift detail page.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Timesheet Creation Failed',
            'message' => "Shift completed for {$this->staffName} at {$this->siteName} on {$this->shiftDate}, but timesheet creation failed: {$this->reason}",
            'shift_id' => $this->shiftId,
            'staff_name' => $this->staffName,
            'site_name' => $this->siteName,
            'shift_date' => $this->shiftDate,
            'reason' => $this->reason,
            'url' => url("/shifts/{$this->shiftId}"),
        ];
    }
}
