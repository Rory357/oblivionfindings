<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrCalendarEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CalendarEventInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrCalendarEvent $event,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $when = $this->event->is_all_day
            ? $this->event->starts_at?->format('l, F j, Y')
            : $this->event->starts_at?->format('l, F j, Y \a\t g:ia');

        $mail = (new MailMessage)
            ->subject("You're invited — {$this->event->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("You've been invited to **{$this->event->title}**.")
            ->line('**When:** '.($when ?? 'TBC'));

        if ($this->event->location) {
            $mail->line("**Where:** {$this->event->location}");
        }

        return $mail
            ->line('Open the calendar to respond (going / maybe / not going).')
            ->action('View Event', url('/hr/calendar'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'calendar_event_invite',
            'event_id' => $this->event->id,
            'title' => $this->event->title,
            'starts_at' => $this->event->starts_at?->toIso8601String(),
            'action_url' => '/hr/calendar',
        ];
    }
}
