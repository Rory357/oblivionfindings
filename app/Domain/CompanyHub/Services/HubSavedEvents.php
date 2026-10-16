<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCalendarAggregator;
use App\Domain\Hr\Services\HrCalendarRecurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Saved series use a current occurrence, independent of the upcoming calendar window. */
class HubSavedEvents
{
    public function __construct(
        private readonly HubAccess $hub,
        private readonly HrCalendarAccessService $calendar,
        private readonly HrCalendarRecurrence $recurrence,
        private readonly HrCalendarAggregator $aggregator,
    ) {}

    public function forUser(User $viewer): array
    {
        $viewer = $viewer->fresh();
        abort_unless($viewer, 403);
        $this->hub->assertEnter($viewer);
        $ids = DB::table('hub_bookmarks')->where('user_id', $viewer->id)->where('subject_type', 'event')->pluck('subject_id');
        if ($ids->isEmpty()) {
            return [];
        }
        $events = $this->calendar->applySiteScope(HrCalendarEvent::active()->whereKey($ids), $viewer)
            ->with(['attendees', 'recurrenceParent.attendees'])->get();
        $children = HrCalendarEvent::whereIn('recurrence_parent_id', $events->whereNotNull('rrule')->pluck('id'))
            ->where('is_exception', true)->with('recurrenceParent.attendees')->get()->groupBy('recurrence_parent_id');

        return $events->map(function (HrCalendarEvent $event) use ($viewer, $children): ?array {
            if (! $this->calendar->canViewEvent($viewer, $event) || ! $event->starts_at || ! $event->ends_at
                || ($event->recurrence_parent_id && (! $event->recurrenceParent || $event->recurrenceParent->archived_at))) {
                return null;
            }
            $occurrence = $this->representative($viewer, $event, $children->get($event->id, collect()));
            if (! $occurrence) {
                return null;
            }
            $row = $this->aggregator->projectOccurrence($viewer, $occurrence['event'], $occurrence['start'], $occurrence['end'], $occurrence['date']);
            $row['extendedProps']['savedSubjectId'] = (int) $event->id;
            $row['extendedProps']['attachments'] = array_map(fn ($file) => HubEventMedia::attachmentUrls($file, '/company-hub/event-files/'.$file['id']), $row['extendedProps']['attachments'] ?? []);

            return $row;
        })->filter()->sortBy('start')->values()->all();
    }

    private function representative(User $viewer, HrCalendarEvent $event, Collection $children): ?array
    {
        if (! $event->rrule) {
            return ['event' => $event, 'start' => $event->starts_at, 'end' => $event->ends_at, 'date' => null];
        }
        $skip = $children->pluck('exception_date')->filter()->map(fn ($date) => $date->toDateString())->all();
        $candidate = fn (array $occurrence): array => ['event' => $event, ...$occurrence];
        $now = now();
        // Only one unsuppressed future base date is needed. Exceptions cannot
        // force a scan past more dates than their own count.
        $future = collect($this->recurrence->between($event, $now, Carbon::create(9999, 12, 31, 23, 59, 59, 'UTC'), $children->count() + 1))
            ->reject(fn ($occurrence) => in_array($occurrence['date'], $skip, true))->take(1)->map($candidate);
        $overrides = $children->filter(fn ($child) => ! $child->archived_at && $child->starts_at && $child->ends_at && $this->calendar->canViewEvent($viewer, $child))
            ->map(fn ($child) => ['event' => $child, 'start' => $child->starts_at, 'end' => $child->ends_at, 'date' => null]);
        $upcoming = $future->concat($overrides->filter(fn ($occurrence) => $occurrence['end']->gte($now)))->sortBy(fn ($occurrence) => $occurrence['start']->timestamp)->first();
        if ($upcoming) {
            return $upcoming;
        }
        // Finite ended series stay saved at their last surviving occurrence.
        $lastDate = $this->recurrence->latestBefore($event, $now, $skip);
        $past = $lastDate ? collect([$candidate($lastDate)]) : collect();

        return $past->concat($overrides)->sortByDesc(fn ($occurrence) => $occurrence['start']->timestamp)->first();
    }
}
