<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The dispatcher rechecks access and preferences; no event details leave the calendar. */
class CalendarReminderNotification extends Notification
{
    public function __construct(public readonly string $channel, public readonly string $url, public readonly int $count = 1) {}

    public function via(object $notifiable): array
    {
        return [$this->channel === 'email' ? 'mail' : 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title())->line($this->body())->action('Open my events', url($this->url));
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'calendar_reminder', 'event_key' => 'hr.calendar.reminder', 'title' => $this->title(),
            'message' => $this->body(), 'body' => $this->body(), 'url' => $this->url, 'action_url' => $this->url, 'context' => []];
    }

    private function title(): string
    {
        return $this->count > 1 ? 'Your event reminders' : 'Event reminder';
    }

    private function body(): string
    {
        return $this->count > 1 ? 'You have '.$this->count.' event reminders. Open your calendar to see current details.' : 'Open your calendar to see the event and its current details.';
    }
}
