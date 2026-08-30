<?php

namespace App\Domain\Governance\Notifications;

use App\Domain\Governance\Models\GovernanceMeeting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PreReadReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected GovernanceMeeting $meeting,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $daysUntil = now()->diffInDays($this->meeting->scheduled_at);

        return (new MailMessage)
            ->subject("Meeting Pre-Read: {$this->meeting->title}")
            ->line("Reminder: The board meeting \"{$this->meeting->title}\" is in {$daysUntil} days.")
            ->line('Please review the board pack and agenda items before the meeting.')
            ->action('View Board Pack', url("/governance/meetings/{$this->meeting->id}"))
            ->line('If you have questions about any agenda items, please contact the Company Secretary.');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'pre_read_reminder',
            'meeting_id' => $this->meeting->id,
            'meeting_title' => $this->meeting->title,
            'scheduled_at' => $this->meeting->scheduled_at->toIso8601String(),
        ];
    }
}
