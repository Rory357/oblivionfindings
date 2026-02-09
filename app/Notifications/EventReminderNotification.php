<?php

namespace App\Notifications;

use App\Models\SiteCalendarEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class EventReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        private SiteCalendarEvent $event,
        private int $minutesBefore
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $siteName = $this->event->site?->name ?? 'Unknown Site';
        
        return (new MailMessage)
            ->subject("Reminder: {$this->event->title} in {$this->minutesBefore} minutes")
            ->line("This is a reminder for your upcoming event:")
            ->line("**{$this->event->title}**")
            ->line("Site: {$siteName}")
            ->line("Time: {$this->event->start_at->format('l, F j, Y g:i A')}")
            ->when($this->event->description, fn($msg) => $msg->line("Details: {$this->event->description}"))
            ->action('View Event', url("/sites/{$this->event->site_id}/calendar"));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => "Reminder: {$this->event->title}",
            'message' => "Your event at {$this->event->site?->name} starts in {$this->minutesBefore} minutes",
            'event_id' => $this->event->id,
            'site_id' => $this->event->site_id,
            'type' => 'event_reminder',
            'action_url' => "/sites/{$this->event->site_id}/calendar",
        ];
    }
}
