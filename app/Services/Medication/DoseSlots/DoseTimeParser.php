<?php

namespace App\Services\Medication\DoseSlots;

use Illuminate\Support\Str;

/**
 * The one reading of an order's dose times (New Zealand wall-clock "HH:MM").
 *
 * The structured `dose_times` column wins; otherwise the free-text frequency
 * is read for 24-hour times, 12-hour times and a few keywords. This is
 * MarScheduleService's rule, moved here unchanged so the dose-slot rules and
 * today's screens read an order the same way.
 */
final class DoseTimeParser
{
    private const KEYWORD_TIMES = [
        'morning' => '08:00',
        'noon' => '12:00',
        'midday' => '12:00',
        'afternoon' => '15:00',
        'evening' => '18:00',
        'night' => '21:00',
        'bedtime' => '21:00',
    ];

    /**
     * @return list<string> sorted, distinct HH:MM times; empty when nothing can be read
     */
    public static function parse(mixed $doseTimes, ?string $frequency): array
    {
        $times = self::fromColumn($doseTimes);

        return $times !== [] ? $times : self::fromFrequency($frequency);
    }

    /**
     * @return list<string>
     */
    public static function fromColumn(mixed $doseTimes): array
    {
        $doseTimes = is_array($doseTimes) ? $doseTimes : [];

        return collect($doseTimes)
            ->filter(fn ($time) => is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function fromFrequency(?string $frequency): array
    {
        $frequency = trim((string) ($frequency ?? ''));
        if ($frequency === '') {
            return [];
        }

        $times = [];

        // 24h times: 8:00 or 08:00
        if (preg_match_all('/\b([01]?\d|2[0-3])\s*[:.]\s*([0-5]\d)\b/', $frequency, $m)) {
            foreach ($m[1] as $i => $h) {
                $hh = str_pad((string) ((int) $h), 2, '0', STR_PAD_LEFT);
                $mm = str_pad((string) ((int) $m[2][$i]), 2, '0', STR_PAD_LEFT);
                $times[] = "$hh:$mm";
            }
        }

        // 12h times: 8am / 8 pm / 8:30am
        if (preg_match_all('/\b(1[0-2]|0?\d)(?:\s*[:.]\s*([0-5]\d))?\s*(am|pm)\b/i', $frequency, $m2)) {
            foreach ($m2[1] as $i => $h) {
                $hour = (int) $h;
                $min = isset($m2[2][$i]) && $m2[2][$i] !== '' ? (int) $m2[2][$i] : 0;
                $ampm = strtolower($m2[3][$i]);
                if ($ampm === 'pm' && $hour < 12) {
                    $hour += 12;
                }
                if ($ampm === 'am' && $hour === 12) {
                    $hour = 0;
                }
                $times[] = str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':'.str_pad((string) $min, 2, '0', STR_PAD_LEFT);
            }
        }

        $lower = Str::lower($frequency);
        foreach (self::KEYWORD_TIMES as $keyword => $time) {
            if (Str::contains($lower, $keyword)) {
                $times[] = $time;
            }
        }

        $times = collect($times)
            ->filter()
            ->unique()
            ->values()
            ->all();

        sort($times);

        return $times;
    }
}
