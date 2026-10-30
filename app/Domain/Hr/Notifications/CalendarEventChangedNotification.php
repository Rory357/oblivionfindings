<?php

namespace App\Domain\Hr\Notifications;

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Services\HrCalendarChanges;
use App\Models\User;
use Illuminate\Notifications\Notification;

/** Transactional, generic receipt; private source identity is never bell content. */
class CalendarEventChangedNotification extends Notification
{
    public function __construct(private readonly int $eventId, private readonly string $date, private readonly int $actorId, private readonly int $version) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $person = $notifiable instanceof User ? $notifiable->fresh() : null;
        $event = HrCalendarEvent::find($this->eventId);

        return $channel === 'database' && $person && $event && app(HrCalendarChanges::class)->canDeliver($person, $event, $this->date, $this->actorId, $this->version);
    }

    public function toArray(object $notifiable): array
    {
        $url = $notifiable->canDo('company_hub.view') ? '/company-hub?view=events' : '/hr/calendar';

        return ['type' => 'calendar_event_changed', 'title' => 'Your event details changed',
            'message' => 'An event you joined or planned to attend has updated details. Open your calendar to see the current invitation.',
            'url' => $url, 'action_url' => $url];
    }
}
