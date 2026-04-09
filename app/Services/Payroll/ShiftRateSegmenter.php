<?php

namespace App\Services\Payroll;

use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;

/**
 * Splits a shift time window into rate-band segments.
 *
 * Rate bands (24-hour clock, every calendar day):
 *   standard  06:00–18:00
 *   evening   18:00–20:00
 *   night     20:00–06:00 (crosses midnight)
 *
 * Returns an array of segments with type and minutes.
 * Handles overnight shifts, multi-day spans, and exact boundaries.
 */
class ShiftRateSegmenter
{
    /**
     * Band boundaries as [hour, type] pairs in chronological order within a day.
     * Each entry means "from this hour, the given type applies until the next entry".
     */
    private const BANDS = [
        [0, 'night'],      // 00:00–06:00
        [6, 'standard'],   // 06:00–18:00
        [18, 'evening'],   // 18:00–20:00
        [20, 'night'],     // 20:00–00:00 (next day)
    ];

    /**
     * Segment a shift time window into rate-band chunks.
     *
     * @return array<int, array{type: string, minutes: int}>
     */
    public function segment(CarbonInterface $start, CarbonInterface $end): array
    {
        $start = CarbonImmutable::instance($start);
        $end = CarbonImmutable::instance($end);

        if ($end->lte($start)) {
            return [];
        }

        $buckets = [];
        $cursor = $start;

        while ($cursor->lt($end)) {
            $band = $this->bandAt($cursor);
            $bandEnd = $this->nextBandBoundary($cursor);

            // Clamp to shift end.
            $segmentEnd = $bandEnd->lt($end) ? $bandEnd : $end;
            $minutes = (int) $cursor->diffInMinutes($segmentEnd);

            if ($minutes > 0) {
                $buckets[$band] = ($buckets[$band] ?? 0) + $minutes;
            }

            $cursor = $segmentEnd;
        }

        // Build flat array sorted by minutes descending (dominant first).
        $segments = [];
        foreach ($buckets as $type => $minutes) {
            $segments[] = ['type' => $type, 'minutes' => $minutes];
        }

        usort($segments, fn (array $a, array $b) => $b['minutes'] <=> $a['minutes']);

        return $segments;
    }

    /**
     * Determine the dominant (most minutes) rate type for a shift.
     * Falls back to 'standard' if the window is empty.
     */
    public function dominantType(CarbonInterface $start, CarbonInterface $end): string
    {
        $segments = $this->segment($start, $end);

        return $segments[0]['type'] ?? 'standard';
    }

    /**
     * Check whether a shift spans more than one rate band.
     */
    public function isMixed(CarbonInterface $start, CarbonInterface $end): bool
    {
        return count($this->segment($start, $end)) > 1;
    }

    /**
     * Which rate band applies at the given instant.
     */
    private function bandAt(CarbonImmutable $time): string
    {
        $hour = $time->hour;
        $minute = $time->minute;

        // Walk bands in reverse to find the one whose start hour is <= current time.
        // Bands: 0→night, 6→standard, 18→evening, 20→night
        for ($i = count(self::BANDS) - 1; $i >= 0; $i--) {
            if ($hour > self::BANDS[$i][0] || ($hour === self::BANDS[$i][0] && $minute >= 0)) {
                return self::BANDS[$i][1];
            }
        }

        // Shouldn't reach here, but midnight is night.
        return 'night';
    }

    /**
     * The next band boundary after the given time (the next transition point).
     */
    private function nextBandBoundary(CarbonImmutable $time): CarbonImmutable
    {
        $hour = $time->hour;

        foreach (self::BANDS as [$bandHour]) {
            if ($bandHour > $hour) {
                return $time->copy()->setTime($bandHour, 0, 0);
            }
        }

        // Past the last band in the day — next boundary is 00:00 next day
        // (which is the start of the night band again).
        // But 00:00 is itself a continuation of night, so the real next
        // transition is 06:00 next day.
        //
        // Wait — we need the boundary where the type CHANGES.
        // If we're in the 20:00–06:00 night band, next change is 06:00 tomorrow.
        // But we should still stop at midnight so the day-cursor advances properly.
        // Actually no — we just need the next point where the rate type changes.

        // Current is in 20–24 range (night). Next change: 06:00 tomorrow.
        return $time->copy()->addDay()->setTime(6, 0, 0);
    }
}
