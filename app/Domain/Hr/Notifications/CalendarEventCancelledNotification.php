<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\CompanyHub\Services\HubNotificationPreferences;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Services\HrCalendarCancellation;
use App\Domain\Hr\Services\HrCalendarReminderRecipients;
use App\Domain\Hr\Services\HrEventRegistrationService;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** A transactional receipt with no event identity or private details in the bell. */
class CalendarEventCancelledNotification extends Notification
{
    public function __construct(private readonly int $eventId, private readonly string $date) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $person = $notifiable instanceof User ? $notifiable->fresh() : null;
        $event = HrCalendarEvent::find($this->eventId);
        $root = $event?->recurrence_parent_id ? $event->recurrenceParent : $event;
        if ($channel !== 'database' || ! $person || ! $event || ! $root || $root->archived_at || $event->archived_at
            || (! $person->canDo('company_hub.view') && ! $person->canDo('hr.calendar.view'))
            || ! app(HrCalendarReminderRecipients::class)->inAudience($person, $root, $event)
            || ! app(HrCalendarCancellation::class)->cancelled($event, $this->date)
            || ! app(HubNotificationPreferences::class)->allowsEvent($person, $event)) {
            return false;
        }
        try {
            $row = app(HrEventRegistrationService::class)->occurrence($person, $event, $this->date);
        } catch (HttpExceptionInterface) {
            return false;
        }

        return in_array($row['extendedProps']['registration']['mine'] ?? null, ['going', 'waiting', 'maybe'], true)
            || (! $root->registration_enabled && $root->attendees()->where('audience_type', 'person')->where('user_id', $person->id)->whereIn('rsvp_status', ['yes', 'maybe'])->exists());
    }

    public function toArray(object $notifiable): array
    {
        $url = $notifiable->canDo('company_hub.view') ? '/company-hub?view=events' : '/hr/calendar';

        return ['type' => 'calendar_event_cancelled', 'title' => 'An event was cancelled',
            'message' => 'An event you joined or planned to attend was cancelled. Open your calendar to see current details.',
            'url' => $url, 'action_url' => $url];
    }
}
