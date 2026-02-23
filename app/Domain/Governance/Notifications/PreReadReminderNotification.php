<?php

namespace App\Domain\Governance\Notifications;

use App\Domain\Governance\Models\GovernanceMeeting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PreReadReminderNotification extends Notification implements ShouldQueue
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
            'title' => "Pre-Read Reminder: {$this->meeting->title}",
            'message' => 'Please review the pre-read materials before the upcoming meeting.',
            'url' => "/governance/meetings/{$this->meeting->id}",
            'context' => [
                'Meeting' => $this->meeting->title,
                'Scheduled at' => $this->meeting->scheduled_at->format('j M Y, g:i A'),
            ],
            'meeting_id' => $this->meeting->id,
            'meeting_title' => $this->meeting->title,
            'scheduled_at' => $this->meeting->scheduled_at->toIso8601String(),
        ];
    }
}
