<?php

namespace App\Services\Sites;

use App\Models\SiteCalendarEvent;
use App\Models\SiteCalendarEventException;
use Carbon\Carbon;

class SiteCalendarService
{
    /**
     * Get events for a date range, handling recurring events
     */
    public function getEventsForRange(
        ?array $siteIds,
        ?array $eventTypes,
        Carbon $start,
        Carbon $end,
        ?int $userId = null
    ): array {
        $query = SiteCalendarEvent::query()
            ->when($siteIds, fn($q) => $q->whereIn('site_id', $siteIds))
            ->when($eventTypes, fn($q) => $q->whereIn('event_type', $eventTypes))
            ->when($userId, fn($q) => $q->where(function($sq) use ($userId) {
                $sq->where('owner_user_id', $userId)
                   ->orWhereJsonContains('attendee_user_ids', $userId)
                   ->orWhereJsonContains('attendee_user_ids', (string) $userId);
            }))
            ->whereNull('recurrence_parent_id')
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_at', [$start, $end])
                  ->orWhere(function ($sq) use ($end) {
                      $sq->whereNotNull('recurrence_rule')
                         ->where('start_at', '<=', $end);
                  });
            })
            ->with(['site:id,name,type', 'owner:id,name']);

        $events = $query->get();
        $occurrences = [];

        foreach ($events as $event) {
            if ($event->recurrence_rule) {
                $occurrences = array_merge(
                    $occurrences,
                    $this->expandRecurringEvent($event, $start, $end)
                );
            } else {
                $occurrences[] = $this->formatOccurrence($event);
            }
        }

        return $occurrences;
    }

    /**
     * Expand a recurring event into individual occurrences
     */
    private function expandRecurringEvent(
        SiteCalendarEvent $event,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {
        // Exceptions keyed by occurrence date — carry both the cancel flag and any
        // per-occurrence field overrides (single-occurrence reschedule/edit).
        $exceptions = SiteCalendarEventException::where('parent_event_id', $event->id)
            ->whereBetween('exception_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->get()
            ->keyBy(fn ($exception) => Carbon::parse($exception->exception_date)->toDateString());

        $occurrences = [];

        // Use PHP native DatePeriod for RRULE expansion (simplified)
        // For production, use a library like `spatie/icalendar-generator` or `eluceo/ical`
        $occurrencesData = $this->calculateOccurrences($event, $rangeStart, $rangeEnd);

        foreach ($occurrencesData as $occurrence) {
            $date = $occurrence['date']->format('Y-m-d');
            $exception = $exceptions->get($date);

            // Skip cancelled occurrences ("delete this one").
            if ($exception && $exception->is_cancelled) {
                continue;
            }

            // Apply "edit this occurrence" overrides (e.g. moved time).
            if ($exception && is_array($exception->overridden_fields)) {
                $occurrence['overrides'] = $exception->overridden_fields;
            }

            $occurrences[] = $this->formatOccurrence($event, $occurrence);
        }

        return $occurrences;
    }

    /**
     * Calculate occurrences from RRULE (simplified implementation)
     */
    private function calculateOccurrences(
        SiteCalendarEvent $event,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {
        $rrule = (string) $event->recurrence_rule;
        $start = $event->start_at->copy();
        $interval = $this->extractInterval($rrule) ?: 1;

        // Map the frequency to its step. Unknown / non-recurring rules emit a
        // single occurrence.
        $step = match (true) {
            str_contains($rrule, 'FREQ=DAILY') => fn (Carbon $c) => $c->addDays($interval),
            str_contains($rrule, 'FREQ=WEEKLY') => fn (Carbon $c) => $c->addWeeks($interval),
            str_contains($rrule, 'FREQ=MONTHLY') => fn (Carbon $c) => $c->addMonths($interval),
            default => null,
        };

        if (! $step) {
            return ($start >= $rangeStart && $start <= $rangeEnd) ? [['date' => $start]] : [];
        }

        // Honour the series' end condition: UNTIL (inclusive instant) and COUNT
        // (total occurrences from the series start, in- and out-of-range alike).
        $until = $this->extractUntil($rrule);
        $maxCount = $this->extractCount($rrule);

        $occurrences = [];
        $current = $start->copy();
        $emitted = 0;
        $guard = 0;

        while ($current <= $rangeEnd && $guard++ < 5000) {
            if ($until && $current->gt($until)) {
                break;
            }
            if ($maxCount !== null && $emitted >= $maxCount) {
                break;
            }

            if ($current >= $rangeStart) {
                $occurrences[] = ['date' => $current->copy()];
            }
            $emitted++;
            $step($current);
        }

        return $occurrences;
    }

    private function extractInterval(string $rrule): ?int
    {
        if (preg_match('/INTERVAL=(\d+)/', $rrule, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }

    /** RRULE UNTIL ("YYYYMMDD" or "YYYYMMDDTHHMMSSZ") as a UTC instant. */
    private function extractUntil(string $rrule): ?Carbon
    {
        if (preg_match('/UNTIL=(\d{8})(?:T(\d{6})Z?)?/', $rrule, $m)) {
            return Carbon::createFromFormat('YmdHis', $m[1].($m[2] ?? '000000'), 'UTC');
        }
        return null;
    }

    /** RRULE COUNT — total number of occurrences in the series. */
    private function extractCount(string $rrule): ?int
    {
        if (preg_match('/COUNT=(\d+)/', $rrule, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Create exception for a recurring event occurrence
     */
    public function createException(
        int $parentEventId,
        string $exceptionDate,
        ?array $overriddenFields = null,
        bool $isCancelled = false
    ): SiteCalendarEventException {
        return SiteCalendarEventException::create([
            'parent_event_id' => $parentEventId,
            'exception_date' => $exceptionDate,
            'is_cancelled' => $isCancelled,
            'overridden_fields' => $overriddenFields,
        ]);
    }

    private function formatOccurrence(
        SiteCalendarEvent $event,
        ?array $occurrence = null
    ): array {
        $start = $occurrence['date'] ?? $event->start_at;
        $overrides = $occurrence['overrides'] ?? [];

        // Each occurrence keeps the series' duration: shift end by the same
        // span as the master event rather than reusing the original end date.
        $end = null;
        if ($event->end_at) {
            $durationSeconds = $event->end_at->getTimestamp() - $event->start_at->getTimestamp();
            $end = $start->copy()->addSeconds($durationSeconds);
        }

        // Override times are stored verbatim as business-timezone wall-clock
        // (a datetime-local string); parse them in that zone before emitting UTC.
        // Series occurrences already derive from the UTC start column, so they
        // need no conversion.
        $tz = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $startIso = isset($overrides['start_at'])
            ? Carbon::parse($overrides['start_at'], $tz)->utc()->toIso8601String()
            : $start->toIso8601String();
        $endIso = isset($overrides['end_at'])
            ? Carbon::parse($overrides['end_at'], $tz)->utc()->toIso8601String()
            : $end?->toIso8601String();

        return [
            'id' => $event->id,
            // occ_id keys on the ORIGINAL occurrence date so its exception row stays addressable.
            'occ_id' => $event->id.'@'.$start->toDateString(),
            'series_id' => $event->id,
            'is_occurrence' => $occurrence !== null,
            'is_exception' => $occurrence !== null,
            'title' => $overrides['title'] ?? $event->title,
            'description' => $event->description,
            'room' => $event->room,
            'event_type' => $event->event_type,
            'start_at' => $startIso,
            'end_at' => $endIso,
            'recurrence_rule' => $event->recurrence_rule,
            'all_day' => (bool) $event->all_day,
            'reminder_minutes' => $event->reminder_minutes ?? [],
            'attendee_user_ids' => $event->attendee_user_ids ?? [],
            'site' => $event->site,
            'owner' => $event->owner,
            'status' => $event->status,
            'approval_status' => $event->approval_status,
        ];
    }

    /**
     * Check for booking conflicts
     */
    public function hasConflict(
        int $siteId,
        Carbon $start,
        Carbon $end,
        ?int $excludeEventId = null
    ): bool {
        $query = SiteCalendarEvent::where('site_id', $siteId)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_at', [$start, $end])
                  ->orWhereBetween('end_at', [$start, $end])
                  ->orWhere(function ($sq) use ($start, $end) {
                      $sq->where('start_at', '<=', $start)
                         ->where('end_at', '>=', $end);
                  });
            })
            ->whereNotIn('status', ['cancelled', 'declined']);

        if ($excludeEventId) {
            $query->where('id', '!=', $excludeEventId);
        }

        return $query->exists();
    }
}
