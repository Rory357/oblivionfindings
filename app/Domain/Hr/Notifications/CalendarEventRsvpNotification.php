<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\CompanyHub\Services\HubNotificationPreferences;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCurrentStaffService;
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

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $organiser = $notifiable instanceof User ? $notifiable->fresh() : null;
        $responder = $this->responder->fresh();
        $event = HrCalendarEvent::find($this->event->id);
        $root = $event?->recurrence_parent_id ? $event->recurrenceParent : $event;
        if ($channel !== 'database' || ! $organiser || ! $responder || ! $event || ! $root || $event->archived_at || $root->archived_at || $event->cancelled_at || $root->cancelled_at || $root->registration_enabled
            || (int) $root->created_by !== (int) $organiser->id || ! app(HrCurrentStaffService::class)->isCurrent($organiser) || ! app(HrCurrentStaffService::class)->isCurrent($responder)
            || (! $organiser->canDo('company_hub.view') && ! $organiser->canDo('hr.calendar.view')) || (! $responder->canDo('company_hub.view') && ! $responder->canDo('hr.calendar.view'))
            || ! app(HrCalendarAccessService::class)->canViewEvent($organiser, $event) || ! app(HrCalendarAccessService::class)->canViewEvent($responder, $event)
            || ! app(HubNotificationPreferences::class)->allowsEvent($organiser, $event)
            || ! $root->attendees()->where('audience_type', 'person')->where('user_id', $responder->id)->where('rsvp_status', $this->status)->exists()) {
            return false;
        }
        $this->event = $event;
        $this->responder = $responder;

        return true;
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
