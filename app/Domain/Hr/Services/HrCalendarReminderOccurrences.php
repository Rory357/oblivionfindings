<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrCalendarEvent;
use Illuminate\Support\Carbon;

class HrCalendarReminderOccurrences
{
    public function __construct(private readonly HrCalendarRecurrence $recurrence) {}

    public function due(HrCalendarEvent $event, Carbon $from, Carbon $to): array
    {
        if ($event->archived_at) {
            return [];
        }
        if ($event->recurrence_parent_id) {
            $root = $event->recurrenceParent;
            if (! $root || $root->archived_at || ! $event->reminders_override || ! $this->validException($root, $event)) {
                return [];
            }

            return $event->starts_at->gt($from) && $event->starts_at->lte($to) ? [['event' => $event, 'start' => $event->starts_at->copy(), 'end' => $event->ends_at->copy(), 'date' => $event->exception_date->toDateString()]] : [];
        }
        $children = $event->rrule ? HrCalendarEvent::where('recurrence_parent_id', $event->id)->get() : collect();
        $skip = $children->map(fn ($child) => $child->exception_date?->toDateString())->all();
        $due = collect($this->recurrence->between($event, $from, $to))
            ->filter(fn ($date) => $date['start']->gt($from) && $date['start']->lte($to) && ! in_array($date['date'], $skip, true))
            ->map(fn ($date) => ['event' => $event, 'start' => $date['start'], 'end' => $date['end'], 'date' => $date['date']]);
        foreach ($children as $child) {
            if (! $child->archived_at && ! $child->reminders_override && $child->starts_at->gt($from) && $child->starts_at->lte($to) && $this->validException($event, $child)) {
                $due->push(['event' => $child, 'start' => $child->starts_at->copy(), 'end' => $child->ends_at->copy(), 'date' => $child->exception_date->toDateString()]);
            }
        }

        return $due->sortBy(fn ($date) => $date['start']->timestamp)->values()->all();
    }

    /** Scan all still-useful due reminders, so delayed scheduler runs cannot lose a minute. */
    public function actionable(HrCalendarEvent $event, int $offset, Carbon $now): array
    {
        if (! $event->starts_at || ! $event->ends_at) {
            return [];
        }
        $candidates = collect([$event])->concat($event->rrule ? HrCalendarEvent::where('recurrence_parent_id', $event->id)->active()->get() : []);
        $duration = $candidates->max(fn ($item) => max(0, $item->ends_at->timestamp - $item->starts_at->timestamp)) ?? 0;

        return collect($this->due($event, $now->copy()->subSeconds($duration)->subDay(), $now->copy()->addMinutes($offset)->addDay()))
            ->map(function ($row) use ($offset) {
                if ($row['event']->is_all_day) {
                    $row['start'] = Carbon::parse($row['start']->toDateString(), 'Pacific/Auckland')->utc();
                    $row['end'] = Carbon::parse($row['end']->toDateString(), 'Pacific/Auckland')->endOfDay()->utc();
                }
                $row['due'] = $row['start']->copy()->subMinutes($offset);

                return $row;
            })->filter(fn ($row) => $row['due']->lte($now) && $row['end']->gt($now))->values()->all();
    }

    private function validException(HrCalendarEvent $root, HrCalendarEvent $child): bool
    {
        return $child->is_exception && $child->exception_date && collect($this->recurrence->between($root, $child->exception_date->copy()->startOfDay(), $child->exception_date->copy()->endOfDay()))->contains('date', $child->exception_date->toDateString());
    }
}
