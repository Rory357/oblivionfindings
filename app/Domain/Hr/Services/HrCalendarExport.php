<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrCalendarEvent;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** A calendar snapshot, with the same dates and exception keys as the application. */
class HrCalendarExport
{
    public function __construct(private readonly HrCalendarRecurrence $recurrence) {}

    public function series(HrCalendarEvent $root, iterable $children, callable $visible, ?int $reminder = null): string
    {
        $rule = $this->recurrence->rule($root->rrule);
        abort_unless($rule && ! $root->recurrence_parent_id && ! $root->archived_at, 404);
        $zone = $this->recurrence->timezone($root);
        $finite = $rule['count'] !== null || $root->recurrence_until !== null;
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Oblivion Findings//Company Hub//EN', 'CALSCALE:GREGORIAN'];
        if (! $finite && $zone !== 'UTC') {
            array_push($lines, ...$this->aucklandTimezone());
        }
        $phases = $finite ? [['start' => $root->starts_at, 'until' => null]] : $this->phases($root, $rule);
        $exceptions = [];
        foreach ($children as $child) {
            if (! $child->is_exception || ! $child->exception_date) {
                continue;
            }
            $date = $child->exception_date->toDateString();
            $original = collect($this->recurrence->between($root, $child->exception_date->copy()->startOfDay(), $child->exception_date->copy()->endOfDay()))->firstWhere('date', $date);
            if ($original) {
                $exceptions[] = ['child' => $child, 'start' => $original['start'], 'visible' => ! $child->archived_at && $visible($child)];
            }
        }
        if (! $finite && $zone !== 'UTC') {
            $first = $this->recurrence->resolve($root->starts_at->copy()->tz($zone)->format('Y-m-d H:i:s'), $zone);
            if ($first && ! $first->eq($root->starts_at) && ! collect($exceptions)->contains(fn ($exception) => $exception['start']->eq($root->starts_at))) {
                // A deliberately selected second repeated hour needs an explicit UTC override.
                $exceptions[] = ['child' => $root, 'start' => $root->starts_at, 'visible' => true];
            }
        }
        foreach ($phases as $index => $phase) {
            $uid = 'calendar-series-'.$root->id.'-'.$index.'@oblivion-findings';
            $start = $phase['start'];
            $end = $start->copy()->addSeconds($root->ends_at->timestamp - $root->starts_at->timestamp);
            $dateZone = $finite ? 'UTC' : $zone;
            array_push($lines, ...$this->event($root, $uid, $start, $end, $dateZone));
            if ($finite) {
                $dates = $this->recurrence->between($root, $root->starts_at->copy()->startOfDay(), Carbon::parse('9999-12-31')->endOfDay());
                abort_if($dates === [], 404);
                if ($rule['count'] !== null && ! $root->recurrence_until && count($dates) < $rule['count']) {
                    throw ValidationException::withMessages(['rrule' => 'This series extends beyond the dates supported by calendar files. Set an end date before downloading it.']);
                }
                foreach (array_slice($dates, 1) as $date) {
                    $lines[] = $this->date('RDATE', $date['start'], $root->is_all_day, 'UTC');
                }
            } else {
                $rrule = 'FREQ='.$rule['freq'].';INTERVAL='.$rule['interval'];
                if ($rule['freq'] === 'MONTHLY' && $zone !== 'UTC') {
                    $rrule .= ';BYMONTHDAY='.implode(',', range(1, $start->copy()->tz($zone)->day)).';BYSETPOS=-1';
                }
                if ($phase['until']) {
                    $rrule .= ';UNTIL='.($root->is_all_day ? $phase['until']->format('Ymd') : $phase['until']->utc()->format('Ymd\THis\Z'));
                }
                $lines[] = 'RRULE:'.$rrule;
            }
            $overrides = [];
            foreach ($exceptions as $exception) {
                if ($exception['start']->lt($start) || ($phase['until'] && $exception['start']->gt($phase['until']))) {
                    continue;
                }
                if ($exception['visible']) {
                    // Visible cancelled dates retain an override; EXDATE conceals archived/hidden dates.
                    $overrides[] = $exception;
                } else {
                    $lines[] = $this->date('EXDATE', $exception['start'], $root->is_all_day, $dateZone);
                }
            }
            array_push($lines, ...[...($root->cancelled_at ? [] : $this->alarm($reminder)), 'END:VEVENT']);
            // Preserve completed dates and the UID without expanding an unbounded
            // cancelled series: RFC 5545 recurrence range overrides the later dates.
            if ($root->cancelled_at && ! app(HrCalendarCancellation::class)->marker($root, $start)) {
                $candidates = $this->recurrence->between($root, $root->cancelled_at->copy()->subDay()->startOfDay(), $phase['until'] ?? Carbon::parse('9999-12-31')->endOfDay(), 4);
                $cancelled = collect($candidates)->first(fn ($date) => $date['start']->gte($start) && app(HrCalendarCancellation::class)->marker($root, $date['start']));
                if ($cancelled) {
                    $rangeId = str_replace('RECURRENCE-ID', 'RECURRENCE-ID;RANGE=THISANDFUTURE', $this->date('RECURRENCE-ID', $cancelled['start'], $root->is_all_day, $dateZone));
                    array_push($lines, ...[...$this->event($root, $uid, $cancelled['start'], $cancelled['end'], 'UTC'), $rangeId, 'END:VEVENT']);
                }
            }
            foreach ($overrides as $exception) {
                $child = $exception['child'];
                array_push($lines, ...[...$this->event($child, $uid, $child->starts_at, $child->ends_at, 'UTC', (int) $root->edit_version),
                    $this->date('RECURRENCE-ID', $exception['start'], $root->is_all_day, $dateZone),
                    ...(app(HrCalendarCancellation::class)->marker($child, $child->starts_at) ? [] : $this->alarm($reminder)), 'END:VEVENT']);
            }
        }
        $lines[] = 'END:VCALENDAR';

        return $this->fold($lines);
    }

    /** Legacy monthly arithmetic can settle on an earlier day. Separate phases preserve it exactly. */
    private function phases(HrCalendarEvent $event, array $rule): array
    {
        $phases = [['start' => $event->starts_at->copy(), 'until' => null]];
        if ($rule['freq'] !== 'MONTHLY' || $this->recurrence->timezone($event) !== 'UTC') {
            return $phases;
        }
        $cursor = $event->starts_at->copy();
        // The Gregorian calendar repeats after 400 years; no later day decrease can first occur beyond it.
        for ($i = 0; $i < 4800; $i += $rule['interval']) {
            $next = $cursor->copy()->addMonthsNoOverflow($rule['interval']);
            if ($next->day < $cursor->day) {
                $phases[array_key_last($phases)]['until'] = $next->copy()->subSecond();
                $phases[] = ['start' => $next->copy(), 'until' => null];
            }
            $cursor = $next;
        }

        return $phases;
    }

    private function event(HrCalendarEvent $event, string $uid, Carbon $start, Carbon $end, string $zone, ?int $seriesVersion = null): array
    {
        return ['BEGIN:VEVENT', 'UID:'.$uid, 'DTSTAMP:'.Carbon::now()->utc()->format('Ymd\THis\Z'), 'SEQUENCE:'.max(1, (int) $event->edit_version, $seriesVersion ?? 1),
            'STATUS:'.(app(HrCalendarCancellation::class)->marker($event, $start) ? 'CANCELLED' : 'CONFIRMED'),
            'SUMMARY:'.$this->escape($event->title), 'DESCRIPTION:'.$this->escape($event->description), 'LOCATION:'.$this->escape($event->location),
            $this->date('DTSTART', $start, $event->is_all_day, $zone),
            $event->is_all_day ? $this->date('DTEND', $end->copy()->startOfDay()->addDay(), true, $zone)
                : 'DURATION:PT'.max(0, $end->timestamp - $start->timestamp).'S'];
    }

    private function date(string $key, Carbon $value, bool $allDay, string $zone): string
    {
        if ($allDay) {
            return $key.';VALUE=DATE:'.$value->format('Ymd');
        }

        return $zone === 'UTC' ? $key.':'.$value->copy()->utc()->format('Ymd\THis\Z')
            : $key.';TZID='.$zone.':'.$value->copy()->tz($zone)->format('Ymd\THis');
    }

    private function escape(?string $value): string
    {
        return str_replace(['\\', "\r", "\n", ';', ','], ['\\\\', '', '\\n', '\\;', '\\,'], $value ?? '');
    }

    private function alarm(?int $minutes): array
    {
        return $minutes ? ['BEGIN:VALARM', 'TRIGGER:-PT'.$minutes.'M', 'ACTION:DISPLAY', 'DESCRIPTION:Event reminder', 'END:VALARM'] : [];
    }

    public function fold(array $lines): string
    {
        return implode("\r\n", array_map(function ($line) {
            $parts = [];
            while (strlen($line) > 73) {
                $chunk = mb_strcut($line, 0, 73, 'UTF-8');
                $parts[] = $chunk;
                $line = substr($line, strlen($chunk));
            }
            $parts[] = $line;

            return implode("\r\n ", $parts);
        }, $lines))."\r\n";
    }

    private function aucklandTimezone(): array
    {
        $lines = ['BEGIN:VTIMEZONE', 'TZID:Pacific/Auckland'];
        $transitions = (new \DateTimeZone('Pacific/Auckland'))->getTransitions(Carbon::parse('1860-01-01')->timestamp, Carbon::parse('2008-01-01')->timestamp);
        $previous = $transitions[0];
        foreach (array_slice($transitions, 1) as $transition) {
            $kind = $transition['isdst'] ? 'DAYLIGHT' : 'STANDARD';
            $wall = Carbon::createFromTimestampUTC($transition['ts'])->addSeconds($previous['offset']);
            array_push($lines, 'BEGIN:'.$kind, 'DTSTART:'.$wall->format('Ymd\THis'), 'TZOFFSETFROM:'.$this->offset($previous['offset']),
                'TZOFFSETTO:'.$this->offset($transition['offset']), 'TZNAME:'.$transition['abbr'], 'END:'.$kind);
            $previous = $transition;
        }
        // Current New Zealand rules, effective since 2007, remain recurring in an unbounded snapshot.
        array_push($lines, 'BEGIN:STANDARD', 'DTSTART:20080406T030000', 'TZOFFSETFROM:+1300', 'TZOFFSETTO:+1200', 'TZNAME:NZST',
            'RRULE:FREQ=YEARLY;BYMONTH=4;BYDAY=1SU', 'END:STANDARD',
            'BEGIN:DAYLIGHT', 'DTSTART:20080928T020000', 'TZOFFSETFROM:+1200', 'TZOFFSETTO:+1300', 'TZNAME:NZDT',
            'RRULE:FREQ=YEARLY;BYMONTH=9;BYDAY=-1SU', 'END:DAYLIGHT', 'END:VTIMEZONE');

        return $lines;
    }

    private function offset(int $seconds): string
    {
        $absolute = abs($seconds);

        return ($seconds < 0 ? '-' : '+').sprintf('%02d%02d', intdiv($absolute, 3600), intdiv($absolute % 3600, 60)).($absolute % 60 ? sprintf('%02d', $absolute % 60) : '');
    }
}
