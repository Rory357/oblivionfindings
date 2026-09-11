<?php

namespace App\Support\It;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;

/**
 * Working-time arithmetic for the IT helpdesk SLA clocks (§P-S1, stretch).
 *
 * A "calendar" is the shape ItSlaPolicy::calendarFor() returns:
 *   [
 *     'business_hours' => ['mon' => [['08:00','17:00']], 'tue' => [...], ...,
 *                          'sat' => [], 'sun' => []],   // per-weekday windows
 *     'holiday_dates'  => ['2026-12-25', ...],           // non-working "Y-m-d"
 *   ]
 * Windows are interpreted in the worker timezone (config('app.worker_timezone')).
 *
 * When the calendar is null or defines no working windows, both operations fall
 * back to plain continuous time — this is the v1 24/7 behaviour, so an organisation
 * that never sets business hours is completely unaffected.
 *
 * Callers persisting a result to an Eloquent datetime column should ->utc() it
 * first (the app stores UTC and presents in the worker timezone).
 */
class BusinessHours
{
    /** All seven weekday keys, matching lowercased Carbon format('D'). */
    private const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** Safety cap so a malformed calendar can never spin forever. */
    private const MAX_DAYS = 1000;

    /**
     * The instant that is $minutes WORKING minutes after $start.
     * Falls back to $start->addMinutes($minutes) when the calendar has no windows.
     *
     * @param  array<string, mixed>|null  $calendar
     */
    public static function addWorkingMinutes(CarbonInterface $start, int $minutes, ?array $calendar = null): CarbonImmutable
    {
        $cursor = CarbonImmutable::instance($start)->setTimezone(self::timezone($calendar));

        if ($minutes <= 0 || ! self::hasWindows($calendar)) {
            return self::addElapsedMinutes($cursor, max(0, $minutes));
        }

        $windows = self::windows($calendar);
        $holidays = self::holidays($calendar);
        $remaining = $minutes;

        for ($day = 0; $day < self::MAX_DAYS; $day++) {
            if (! self::isHoliday($cursor, $holidays)) {
                foreach ($windows[self::dayKey($cursor)] as [$open, $close]) {
                    $windowClose = $cursor->setTimeFromTimeString($close);
                    if ($cursor->greaterThanOrEqualTo($windowClose)) {
                        continue; // window already elapsed today
                    }
                    $windowOpen = $cursor->setTimeFromTimeString($open);
                    $effectiveStart = $cursor->greaterThan($windowOpen) ? $cursor : $windowOpen;
                    $available = max(0, (int) $effectiveStart->diffInMinutes($windowClose));

                    if ($remaining <= $available) {
                        return self::addElapsedMinutes($effectiveStart, $remaining);
                    }
                    $remaining -= $available;
                    $cursor = $windowClose;
                }
            }
            $cursor = $cursor->addDay()->startOfDay();
        }

        throw new DomainException('The SLA calendar cannot establish a deadline within its supported range.');
    }

    /**
     * Count of WORKING minutes in the interval [$from, $to].
     * Falls back to $from->diffInMinutes($to) when the calendar has no windows.
     *
     * @param  array<string, mixed>|null  $calendar
     */
    public static function workingMinutesBetween(CarbonInterface $from, CarbonInterface $to, ?array $calendar = null): int
    {
        $tz = self::timezone($calendar);
        $start = CarbonImmutable::instance($from)->setTimezone($tz);
        $end = CarbonImmutable::instance($to)->setTimezone($tz);

        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }
        if (! self::hasWindows($calendar)) {
            return max(0, (int) $start->diffInMinutes($end));
        }

        $windows = self::windows($calendar);
        $holidays = self::holidays($calendar);
        $total = 0;
        $cursor = $start;

        for ($day = 0; $day < self::MAX_DAYS && $cursor->lessThan($end); $day++) {
            if (! self::isHoliday($cursor, $holidays)) {
                foreach ($windows[self::dayKey($cursor)] as [$open, $close]) {
                    $windowOpen = $cursor->setTimeFromTimeString($open);
                    $windowClose = $cursor->setTimeFromTimeString($close);
                    $overlapStart = $cursor->greaterThan($windowOpen) ? $cursor : $windowOpen;
                    $overlapEnd = $windowClose->lessThan($end) ? $windowClose : $end;
                    if ($overlapEnd->greaterThan($overlapStart)) {
                        $total += max(0, (int) $overlapStart->diffInMinutes($overlapEnd));
                    }
                }
            }
            $cursor = $cursor->addDay()->startOfDay();
        }

        if ($cursor->lessThan($end)) {
            throw new DomainException('The SLA calendar interval exceeds its supported range.');
        }

        return $total;
    }

    /**
     * The canonical NZ default calendar — Mon–Fri 08:00–17:00, weekends off,
     * no holidays. Used as the editor default and by the seeder.
     *
     * @return array{business_hours: array<string, array<int, array{0: string, 1: string}>>, holiday_dates: array<int, string>}
     */
    public static function nzDefault(): array
    {
        $weekday = [['08:00', '17:00']];

        return [
            'business_hours' => [
                'mon' => $weekday, 'tue' => $weekday, 'wed' => $weekday,
                'thu' => $weekday, 'fri' => $weekday, 'sat' => [], 'sun' => [],
            ],
            'holiday_dates' => [],
        ];
    }

    /**
     * True when the calendar defines at least one working window.
     *
     * @param  array<string, mixed>|null  $calendar
     */
    public static function hasWindows(?array $calendar): bool
    {
        if (! $calendar || empty($calendar['business_hours']) || ! is_array($calendar['business_hours'])) {
            return false;
        }
        foreach ($calendar['business_hours'] as $windows) {
            if (! empty($windows)) {
                return true;
            }
        }

        return false;
    }

    private static function timezone(?array $calendar): string
    {
        return $calendar['timezone'] ?? config('app.worker_timezone', 'Pacific/Auckland');
    }

    private static function addElapsedMinutes(CarbonImmutable $at, int $minutes): CarbonImmutable
    {
        return $at->utc()->addMinutes($minutes)->setTimezone($at->timezone);
    }

    /**
     * Windows keyed by all seven day-keys (missing days => []), each a list of
     * [open, close] "HH:MM" pairs.
     *
     * @param  array<string, mixed>|null  $calendar
     * @return array<string, array<int, array{0: string, 1: string}>>
     */
    private static function windows(?array $calendar): array
    {
        $raw = is_array($calendar['business_hours'] ?? null) ? $calendar['business_hours'] : [];
        $out = [];
        foreach (self::DAY_KEYS as $key) {
            $day = is_array($raw[$key] ?? null) ? $raw[$key] : [];
            foreach ($day as $window) {
                if (! is_array($window) || count($window) !== 2
                    || ! is_string($window[0] ?? null) || ! is_string($window[1] ?? null)
                    || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $window[0])
                    || ! preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $window[1])
                    || $window[0] >= $window[1]) {
                    throw new DomainException('The SLA calendar contains an invalid working window.');
                }
            }
            usort($day, fn (array $left, array $right) => $left[0] <=> $right[0]);
            $out[$key] = [];
            foreach ($day as [$open, $close]) {
                $last = array_key_last($out[$key]);
                if ($last !== null && $open <= $out[$key][$last][1]) {
                    $out[$key][$last][1] = max($close, $out[$key][$last][1]);
                } else {
                    $out[$key][] = [$open, $close];
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $calendar
     * @return array<int, string>
     */
    private static function holidays(?array $calendar): array
    {
        return array_values(array_filter(
            (array) ($calendar['holiday_dates'] ?? []),
            fn ($d) => is_string($d) && $d !== '',
        ));
    }

    private static function dayKey(CarbonInterface $date): string
    {
        return strtolower($date->format('D')); // Mon => mon
    }

    /** @param  array<int, string>  $holidays */
    private static function isHoliday(CarbonInterface $date, array $holidays): bool
    {
        return in_array($date->format('Y-m-d'), $holidays, true);
    }
}
