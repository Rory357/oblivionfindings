<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CalendarEventRsvpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private HrCalendarEvent $event,
        private User $responder,
        private string $status,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Database-only by default (RSVPs are frequent); mail kept for future opt-in.
        return (new MailMessage)
            ->subject("RSVP — {$this->event->title}")
            ->line("{$this->responder->name} responded \u{201C}{$this->statusLabel()}\u{201D} to {$this->event->title}.");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'calendar_event_rsvp',
            'event_id' => $this->event->id,
            'title' => $this->event->title,
            'responder_name' => $this->responder->name,
            'status' => $this->status,
            'message' => "{$this->responder->name} responded \u{201C}{$this->statusLabel()}\u{201D} to {$this->event->title}",
            'action_url' => '/hr/calendar',
        ];
    }

    private function statusLabel(): string
    {
        return match ($this->status) {
            'yes' => 'Going',
            'no' => 'Not going',
            'maybe' => 'Maybe',
            default => ucfirst($this->status),
        };
    }
}
