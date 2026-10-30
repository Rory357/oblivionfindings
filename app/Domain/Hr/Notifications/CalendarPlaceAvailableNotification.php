<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\CompanyHub\Services\HubNotificationPreferences;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Services\HrCalendarCancellation;
use App\Domain\Hr\Services\HrCalendarReminderRecipients;
use App\Domain\Hr\Services\HrEventRegistrationService;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Transactional in-app receipt; no email and no private event details in the bell. */
class CalendarPlaceAvailableNotification extends Notification
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
            || app(HrCalendarCancellation::class)->cancelled($event, $this->date)
            || ! app(HubNotificationPreferences::class)->allowsEvent($person, $event)
            || ! DB::table('hr_calendar_registrations')->where('event_id', $root->id)->where('occurrence_date', $this->date)->where('user_id', $person->id)->where('status', 'going')->exists()) {
            return false;
        }
        try {
            app(HrEventRegistrationService::class)->occurrence($person, $event, $this->date);
        } catch (HttpExceptionInterface) {
            return false;
        }

        return true;
    }

    public function toArray(object $notifiable): array
    {
        $url = $notifiable->canDo('company_hub.view') ? '/company-hub?view=events' : '/hr/calendar';

        return [
            'type' => 'calendar_place_available', 'title' => 'Your event place is confirmed',
            'message' => 'A place opened on an event waiting list. Open My events to see your registration.',
            'event_id' => $this->eventId, 'occurrence_date' => $this->date,
            'url' => $url, 'action_url' => $url,
        ];
    }
}
