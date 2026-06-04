<?php

namespace App\Jobs;

use App\Models\SiteCalendarEvent;
use App\Services\Sites\Calendar\CalendarSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Pushes a single manual site calendar event out to its house's mapped resource
 * calendars (create/update, or retract if it became pending/cancelled). Dispatched
 * from the event lifecycle so the request stays fast.
 */
class SyncCalendarEventToResourcesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $eventId) {}

    public function handle(CalendarSyncService $service): void
    {
        $event = SiteCalendarEvent::find($this->eventId);
        if (! $event) {
            return;
        }

        $service->pushEvent($event);
    }
}
