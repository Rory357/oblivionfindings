<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrCalendarEvent;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Shared time calculation for the board, registrations, reminders and exports. */
class HrCalendarRecurrence
{
    public function timezone(HrCalendarEvent $event): string
    {
        return ! $event->is_all_day && $event->recurrence_timezone === 'Pacific/Auckland' ? 'Pacific/Auckland' : 'UTC';
    }

    public function rule(?string $value): ?array
    {
        if (! $value) {
            return null;
        }
        $parts = [];
        foreach (explode(';', strtoupper($value)) as $part) {
            [$key, $val] = array_pad(explode('=', $part, 2), 2, null);
            if (! in_array($key, ['FREQ', 'INTERVAL', 'COUNT'], true) || $val === null || isset($parts[$key])) {
                return null;
            }
            $parts[$key] = $val;
        }
        if (! in_array($parts['FREQ'] ?? null, ['DAILY', 'WEEKLY', 'MONTHLY'], true)) {
            return null;
        }
        foreach (['INTERVAL', 'COUNT'] as $key) {
            if (isset($parts[$key]) && (! ctype_digit($parts[$key]) || (int) $parts[$key] < 1)) {
                return null;
            }
        }

        return ['freq' => $parts['FREQ'], 'interval' => (int) ($parts['INTERVAL'] ?? 1), 'count' => isset($parts['COUNT']) ? (int) $parts['COUNT'] : null];
    }

    public function validateRule(?string $value): void
    {
        if (! $value) {
            return;
        }
        $rule = $this->rule($value);
        if (! $rule || $rule['interval'] > ['DAILY' => 366, 'WEEKLY' => 52, 'MONTHLY' => 12][$rule['freq']] || ($rule['count'] !== null && $rule['count'] > 10000)) {
            throw ValidationException::withMessages(['rrule' => 'Choose a supported daily, weekly or monthly repeat pattern (up to 10,000 dates).']);
        }
    }

    /** Resolve ambiguous hours to the first instant; skip nonexistent recurrence hours (RFC 5545). */
    public function resolve(string $wall, string $zone): ?Carbon
    {
        $naive = Carbon::parse($wall, 'UTC');
        if ($zone === 'UTC') {
            return $naive;
        }
        $tz = new \DateTimeZone($zone);
        $offsets = array_unique(array_column($tz->getTransitions($naive->timestamp - 172800, $naive->timestamp + 172800), 'offset'));
        $matches = [];
        foreach ($offsets as $offset) {
            $candidate = $naive->copy()->subSeconds($offset);
            if ($candidate->copy()->setTimezone($zone)->format('Y-m-d H:i:s') === $wall) {
                $matches[] = $candidate;
            }
        }
        usort($matches, fn ($a, $b) => $a->timestamp <=> $b->timestamp);

        return $matches[0] ?? null;
    }

    /** @return list<array{date:string,start:Carbon,end:Carbon}> */
    public function between(HrCalendarEvent $event, Carbon $from, Carbon $to, ?int $limit = null): array
    {
        if (! $event->starts_at || ! $event->ends_at) {
            return [];
        }
        if (! $event->rrule) {
            return $event->starts_at->lte($to) && $event->ends_at->gte($from)
                ? [['date' => $event->starts_at->toDateString(), 'start' => $event->starts_at->copy(), 'end' => $event->ends_at->copy()]] : [];
        }
        $rule = $this->rule($event->rrule);
        if (! $rule) {
            return [];
        }
        $zone = $this->timezone($event);
        $anchor = $event->starts_at->copy()->setTimezone($zone);
        // Wall-clock arithmetic in UTC avoids Carbon permanently shifting 02:30 after a skipped hour.
        $wall = Carbon::parse($anchor->format('Y-m-d H:i:s'), 'UTC');
        $cursor = $wall->copy();
        $duration = max(0, $event->ends_at->timestamp - $event->starts_at->timestamp);
        $hardEnd = $event->recurrence_until ? $to->copy()->min($event->recurrence_until) : $to->copy();
        $index = 0;
        $counted = 0;
        // Unbounded regular repeats can jump near the requested window without a lifetime cap.
        if ($rule['count'] === null && in_array($rule['freq'], ['DAILY', 'WEEKLY'], true)) {
            $stride = $rule['interval'] * ($rule['freq'] === 'WEEKLY' ? 7 : 1);
            $days = $wall->diffInDays(Carbon::parse($from->copy()->subSeconds($duration)->setTimezone($zone)->format('Y-m-d H:i:s'), 'UTC'), false);
            $index = max(0, (int) floor($days / $stride) - 2);
            $cursor->addDays($index * $stride);
        }
        $out = [];
        // A supported application range is far smaller; refuse excessive output rather than truncate it silently.
        $lastWall = $hardEnd->copy()->utc()->addSeconds($hardEnd->copy()->tz($zone)->offset);
        while ($cursor->lte($lastWall)) {
            if ($cursor->year > 9999) {
                break;
            }
            if ($rule['count'] !== null && $counted >= $rule['count']) {
                break;
            }
            $start = $index === 0 ? $event->starts_at->copy()->utc() : $this->resolve($cursor->format('Y-m-d H:i:s'), $zone);
            if ($start) {
                $counted++;
                $end = $start->copy()->addSeconds($duration);
                if ($start->lte($hardEnd) && $start->lte($to) && $end->gte($from)) {
                    $out[] = ['date' => $start->toDateString(), 'start' => $start, 'end' => $end];
                    if ($limit !== null && count($out) >= $limit) {
                        break;
                    }
                    if (count($out) > 20000) {
                        throw ValidationException::withMessages(['rrule' => 'Choose a shorter date range for this event.']);
                    }
                }
            }
            $index++;
            if ($rule['freq'] === 'MONTHLY' && $zone !== 'UTC') {
                // Same day number, or the last day in shorter months; the original day returns next month.
                $cursor = $wall->copy()->startOfMonth()->addMonths($index * $rule['interval']);
                $cursor->day(min($wall->day, $cursor->daysInMonth))->setTimeFromTimeString($wall->format('H:i:s'));
            } else {
                match ($rule['freq']) {
                    'DAILY' => $cursor->addDays($rule['interval']),
                    'WEEKLY' => $cursor->addWeeks($rule['interval']),
                    'MONTHLY' => $cursor->addMonthsNoOverflow($rule['interval']),
                };
            }
        }

        return $out;
    }

    public function normalizeUntil(?string $value, string $zone, ?HrCalendarEvent $original = null): ?Carbon
    {
        if (! $value) {
            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return Carbon::parse($value)->utc();
        }
        if ($original?->recurrence_until && $this->timezone($original) === $zone && $original->recurrence_until->copy()->setTimezone($zone)->toDateString() === $value) {
            return $original->recurrence_until->copy();
        }

        return Carbon::parse($value, $zone)->endOfDay()->utc();
    }

    /** Latest surviving historic date without materialising an unlimited series' lifetime. */
    public function latestBefore(HrCalendarEvent $event, Carbon $to, array $excludedDates = []): ?array
    {
        if (! $event->starts_at || ! $event->ends_at) {
            return null;
        }
        $rule = $this->rule($event->rrule);
        $end = $event->recurrence_until ? $to->copy()->min($event->recurrence_until) : $to->copy();
        $windowDays = max(31, ($rule['interval'] ?? 1) * match ($rule['freq'] ?? '') {
            'WEEKLY' => 21,
            'MONTHLY' => 93,
            default => 3,
        });
        while ($end->gte($event->starts_at)) {
            // Finite COUNT rules are capped by validation at 10,000; unlimited
            // rules walk small windows backwards, including suppressed dates.
            $start = ($rule['count'] ?? null) !== null || ! $rule
                ? $event->starts_at->copy() : $end->copy()->subDays($windowDays)->max($event->starts_at);
            $dates = $this->between($event, $start, $end);
            foreach (array_reverse($dates) as $date) {
                if (! in_array($date['date'], $excludedDates, true)) {
                    return $date;
                }
            }
            $end = $start->subSecond();
        }

        return null;
    }
}
