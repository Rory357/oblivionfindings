<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ShiftAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $shiftDate,
        public string $shiftTime,
        public string $clientName,
        public string $siteName,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Shift Assigned: {$this->clientName} on {$this->shiftDate}")
            ->greeting('Hello ' . ($notifiable->name ?? 'there') . ',')
            ->line("You have been assigned a shift for **{$this->clientName}** at **{$this->siteName}**.")
            ->line("Date: {$this->shiftDate}")
            ->line("Time: {$this->shiftTime}")
            ->action('View Roster', url('/operations/rosters'))
            ->line('Please confirm your availability at your earliest convenience.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Shift Assigned',
            'message' => "You have been assigned a shift for {$this->clientName} at {$this->siteName} on {$this->shiftDate} ({$this->shiftTime}).",
            'client_name' => $this->clientName,
            'site_name' => $this->siteName,
            'shift_date' => $this->shiftDate,
            'shift_time' => $this->shiftTime,
        ];
    }
}
