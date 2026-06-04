<?php

namespace App\Services\Sites\Calendar;

use App\Models\CalendarSyncConnection;
use App\Models\CalendarSyncEventLink;
use App\Models\CalendarSyncMapping;
use App\Models\SiteCalendarEvent;
use App\Services\GoogleCalendarService;
use App\Services\MicrosoftGraphService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Drives the admin resource-calendar sync (Part D): pushes manual site calendar
 * events out to each house's mapped Google resource calendar / Outlook room mailbox
 * (idempotently, via {@see CalendarSyncEventLink}) and pulls external busy blocks for
 * two-way mappings.
 *
 * Non-recurring manual events are pushed as real provider events. Recurring events
 * and auto-derived obligations are carried by the per-house secret .ics feed instead
 * (so external clients still see everything without fragile RRULE translation).
 */
class CalendarSyncService
{
    /**
     * List the resource calendars / room mailboxes available on a connection, for
     * the per-house mapping picker.
     *
     * @return array<int, array{id:string,name:string}>
     */
    public function listResources(CalendarSyncConnection $connection): array
    {
        if (! $connection->isConnected()) {
            return [];
        }

        try {
            if ($connection->provider === CalendarSyncConnection::PROVIDER_GOOGLE) {
                return array_map(
                    fn (array $c) => ['id' => $c['id'], 'name' => $c['name']],
                    (new GoogleCalendarService($connection))->listCalendars(),
                );
            }

            if ($connection->provider === CalendarSyncConnection::PROVIDER_MICROSOFT) {
                return array_map(
                    fn (array $r) => ['id' => $r['id'], 'name' => $r['name']],
                    (new MicrosoftGraphService($connection))->listRooms(),
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Calendar resource listing failed', [
                'provider' => $connection->provider,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Create or update the external event(s) for a manual site calendar event, in
     * every active+syncable mapping on its site whose configured sources include
     * manual events. Best-effort: failures are logged + recorded, never thrown.
     */
    public function pushEvent(SiteCalendarEvent $event): void
    {
        // Recurring events and pending/cancelled events are not API-pushed (the
        // per-house .ics feed carries recurring + obligation entries instead).
        if (! $this->isPushable($event)) {
            $this->deleteEvent($event); // e.g. it became pending/cancelled — retract it
            return;
        }

        foreach ($this->mappingsForSite($event->site_id) as $mapping) {
            if (! $this->mappingPushesManualEvents($mapping)) {
                continue;
            }

            $connection = $this->connectionFor($mapping);
            if (! $connection) {
                continue;
            }

            try {
                $this->upsertExternal($event, $mapping, $connection);
            } catch (\Throwable $e) {
                $mapping->forceFill(['last_error' => $e->getMessage()])->save();
                Log::warning('Calendar push failed', [
                    'event' => $event->id,
                    'mapping' => $mapping->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Remove the external event(s) for a site calendar event from every mapped
     * resource calendar, and clear the idempotency links.
     */
    public function deleteEvent(SiteCalendarEvent $event): void
    {
        $links = CalendarSyncEventLink::query()
            ->where('site_calendar_event_id', $event->id)
            ->get();

        foreach ($links as $link) {
            $mapping = CalendarSyncMapping::query()
                ->where('site_id', $link->site_id)
                ->where('provider', $link->provider)
                ->first();
            $connection = $mapping ? $this->connectionFor($mapping) : null;

            if ($mapping && $connection && $mapping->external_calendar_id) {
                try {
                    $this->deleteExternal($link->external_event_id, $mapping, $connection);
                } catch (\Throwable $e) {
                    Log::warning('Calendar delete failed', ['link' => $link->id, 'error' => $e->getMessage()]);
                }
            }

            $link->delete();
        }
    }

    /**
     * Catch-up sync for one mapping: ensure all current pushable manual events are
     * present, and (for two-way) pull external busy blocks. Returns counts for the
     * sync log.
     *
     * @return array{pushed:int,pulled:int}
     */
    public function syncMapping(CalendarSyncMapping $mapping): array
    {
        $connection = $this->connectionFor($mapping);
        if (! $connection || ! $mapping->isSyncable()) {
            return ['pushed' => 0, 'pulled' => 0];
        }

        $pushed = 0;
        if ($this->mappingPushesManualEvents($mapping)) {
            $events = SiteCalendarEvent::query()
                ->where('site_id', $mapping->site_id)
                ->whereBetween('start_at', [now()->subMonth(), now()->addMonths(3)])
                ->get()
                ->filter(fn (SiteCalendarEvent $e) => $this->isPushable($e));

            foreach ($events as $event) {
                try {
                    $this->upsertExternal($event, $mapping, $connection);
                    $pushed++;
                } catch (\Throwable $e) {
                    Log::warning('Calendar catch-up push failed', ['event' => $event->id, 'error' => $e->getMessage()]);
                }
            }
        }

        $pulled = 0;
        if ($mapping->pullsExternalBusy()) {
            $pulled = $this->pullBusy($mapping, $connection);
        }

        $mapping->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();

        return ['pushed' => $pushed, 'pulled' => $pulled];
    }

    /* ------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------ */

    private function upsertExternal(SiteCalendarEvent $event, CalendarSyncMapping $mapping, CalendarSyncConnection $connection): void
    {
        $calendarId = (string) $mapping->external_calendar_id;

        // Atomically claim the link row. The unique index (event, provider, occurrence)
        // guarantees only one inserter, so concurrent workers can't both create an
        // external event for the same entry.
        $link = CalendarSyncEventLink::firstOrCreate(
            [
                'site_calendar_event_id' => $event->id,
                'provider' => $mapping->provider,
                'occurrence_key' => '',
            ],
            [
                'tenant_id' => $mapping->tenant_id,
                'site_id' => $mapping->site_id,
                'external_event_id' => '',
                'last_pushed_at' => now(),
            ],
        );

        // Already linked to an external event → update it in place.
        if (! $link->wasRecentlyCreated && $link->external_event_id !== '') {
            $this->updateExternalEvent($connection, $calendarId, $link->external_event_id, $event);
            $link->forceFill(['last_pushed_at' => now()])->save();

            return;
        }

        // An empty claim another worker is actively filling (recent) → let it finish.
        if (! $link->wasRecentlyCreated && $link->external_event_id === ''
            && $link->last_pushed_at && $link->last_pushed_at->gt(now()->subMinutes(5))) {
            return;
        }

        // We own the claim (fresh insert, or a reclaimed stale empty claim) → create.
        try {
            $externalId = $this->createExternalEvent($connection, $calendarId, $event);
        } catch (\Throwable $e) {
            if ($link->external_event_id === '') {
                $link->delete();
            }
            throw $e;
        }

        if (! $externalId) {
            if ($link->external_event_id === '') {
                $link->delete();
            }
            throw new \RuntimeException('Provider did not return an event id.');
        }

        $link->forceFill(['external_event_id' => $externalId, 'last_pushed_at' => now()])->save();
    }

    private function createExternalEvent(CalendarSyncConnection $connection, string $calendarId, SiteCalendarEvent $event): ?string
    {
        if ($connection->provider === CalendarSyncConnection::PROVIDER_GOOGLE) {
            $created = (new GoogleCalendarService($connection))->createCalendarEvent($this->googleBody($event), $calendarId);
        } else {
            $created = (new MicrosoftGraphService($connection))->createRoomEvent($calendarId, $this->microsoftBody($event));
        }

        return $created['id'] ?? null;
    }

    private function updateExternalEvent(CalendarSyncConnection $connection, string $calendarId, string $externalId, SiteCalendarEvent $event): void
    {
        if ($connection->provider === CalendarSyncConnection::PROVIDER_GOOGLE) {
            (new GoogleCalendarService($connection))->updateCalendarEvent($externalId, $this->googleBody($event), $calendarId);
        } else {
            (new MicrosoftGraphService($connection))->updateRoomEvent($calendarId, $externalId, $this->microsoftBody($event));
        }
    }

    private function deleteExternal(string $externalId, CalendarSyncMapping $mapping, CalendarSyncConnection $connection): void
    {
        $calendarId = (string) $mapping->external_calendar_id;

        if ($connection->provider === CalendarSyncConnection::PROVIDER_GOOGLE) {
            (new GoogleCalendarService($connection))->deleteCalendarEvent($externalId, $calendarId);
        } else {
            (new MicrosoftGraphService($connection))->deleteRoomEvent($calendarId, $externalId);
        }
    }

    /**
     * Pull external busy events for a two-way mapping. Currently fetches + counts
     * (so the connection/permissions are exercised and logged); surfacing external
     * busy as conflict blocks in the calendar UI is a tracked follow-up.
     */
    private function pullBusy(CalendarSyncMapping $mapping, CalendarSyncConnection $connection): int
    {
        $calendarId = (string) $mapping->external_calendar_id;
        $from = now()->subWeek()->toIso8601String();
        $to = now()->addMonths(3)->toIso8601String();

        if ($connection->provider === CalendarSyncConnection::PROVIDER_GOOGLE) {
            $events = (new GoogleCalendarService($connection))->getCalendarEvents($from, $to, $calendarId);
        } else {
            $events = (new MicrosoftGraphService($connection))->getRoomCalendarEvents($calendarId, $from, $to);
        }

        return count($events);
    }

    /**
     * @return array<string, mixed>
     */
    private function googleBody(SiteCalendarEvent $event): array
    {
        $start = $this->asUtc($event->start_at);
        $end = $this->asUtc($event->end_at) ?? $start->copy()->addHour();

        $body = [
            'summary' => (string) $event->title,
            'description' => (string) ($event->description ?? ''),
            'location' => (string) ($event->room ?? ''),
        ];

        if ($event->all_day) {
            // Google all-day end.date is exclusive; end_at holds the last inclusive day
            // (fall back to a single day when absent).
            $endExclusive = ($event->end_at ? $this->asUtc($event->end_at) : $start)->copy()->addDay();
            $body['start'] = ['date' => $start->toDateString()];
            $body['end'] = ['date' => $endExclusive->toDateString()];
        } else {
            $body['start'] = ['dateTime' => $start->toRfc3339String(), 'timeZone' => 'UTC'];
            $body['end'] = ['dateTime' => $end->toRfc3339String(), 'timeZone' => 'UTC'];
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function microsoftBody(SiteCalendarEvent $event): array
    {
        $start = $this->asUtc($event->start_at);
        $end = $this->asUtc($event->end_at) ?? $start->copy()->addHour();
        // Graph all-day end is exclusive midnight; end_at holds the last inclusive day.
        $allDayEnd = ($event->end_at ? $this->asUtc($event->end_at) : $start)->copy()->addDay()->startOfDay();

        return [
            'subject' => (string) $event->title,
            'body' => ['contentType' => 'text', 'content' => (string) ($event->description ?? '')],
            'location' => ['displayName' => (string) ($event->room ?? '')],
            'isAllDay' => (bool) $event->all_day,
            'start' => [
                'dateTime' => $event->all_day ? $start->copy()->startOfDay()->format('Y-m-d\TH:i:s') : $start->format('Y-m-d\TH:i:s'),
                'timeZone' => 'UTC',
            ],
            'end' => [
                'dateTime' => $event->all_day ? $allDayEnd->format('Y-m-d\TH:i:s') : $end->format('Y-m-d\TH:i:s'),
                'timeZone' => 'UTC',
            ],
        ];
    }

    private function asUtc($value): Carbon
    {
        return Carbon::parse($value)->utc();
    }

    /**
     * Only non-recurring, non-cancelled, not-awaiting-approval manual events are
     * API-pushed.
     */
    private function isPushable(SiteCalendarEvent $event): bool
    {
        if (! empty($event->recurrence_rule)) {
            return false;
        }
        if ($event->approval_status === 'pending' || $event->approval_status === 'rejected') {
            return false;
        }
        if (in_array($event->status, ['cancelled'], true)) {
            return false;
        }

        return $event->start_at !== null;
    }

    private function mappingPushesManualEvents(CalendarSyncMapping $mapping): bool
    {
        $sources = $mapping->sources;

        // null/empty = all sources; otherwise must include the manual 'event' source.
        return empty($sources) || in_array('event', $sources, true);
    }

    /**
     * @return \Illuminate\Support\Collection<int, CalendarSyncMapping>
     */
    private function mappingsForSite(int $siteId)
    {
        return CalendarSyncMapping::query()
            ->where('site_id', $siteId)
            ->active()
            ->get()
            ->filter(fn (CalendarSyncMapping $m) => $m->isSyncable());
    }

    private function connectionFor(CalendarSyncMapping $mapping): ?CalendarSyncConnection
    {
        $connection = CalendarSyncConnection::query()
            ->where('tenant_id', $mapping->tenant_id)
            ->where('provider', $mapping->provider)
            ->first();

        return $connection && $connection->isConnected() ? $connection : null;
    }
}
