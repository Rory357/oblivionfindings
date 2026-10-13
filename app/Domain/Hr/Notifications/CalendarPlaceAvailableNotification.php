<?php

namespace App\Domain\Hr\Notifications;

use Illuminate\Notifications\Notification;

/** Transactional in-app receipt; no email and no private event details in the bell. */
class CalendarPlaceAvailableNotification extends Notification
{
    public function __construct(private readonly int $eventId, private readonly string $date) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'calendar_place_available', 'title' => 'Your event place is confirmed',
            'message' => 'A place opened on an event waiting list. Open My events to see your registration.',
            'event_id' => $this->eventId, 'occurrence_date' => $this->date,
            'url' => '/company-hub?view=events', 'action_url' => '/company-hub?view=events',
        ];
    }
}
