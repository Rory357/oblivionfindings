<?php

namespace App\Domain\SecurityDevices\Modes;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * When a house mode starts (S&D Phase F): weekly start times in NZ wall-clock time, on chosen days
 * (1–7, Monday first). A mode runs until the next mode starts, so there is no end time to keep.
 * Daylight saving is the wall clock's: a start in the skipped spring hour happens when the clock
 * reaches it (Carbon moves it on by the hour).
 */
final class HouseModeSchedule
{
    public const string ZONE = 'Pacific/Auckland';

    private const string TIME = '/\A([01]\d|2[0-3]):[0-5]\d\z/';

    /**
     * A clean schedule, or null for "by hand only".
     *
     * @return array{days: list<int>, starts: string}|null
     */
    public static function normalise(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (! is_array($raw)) {
            throw new InvalidArgumentException('The schedule isn’t readable.');
        }
        $days = array_values(array_unique(array_map(fn (mixed $day): int => is_numeric($day) ? (int) $day : 0, (array) ($raw['days'] ?? []))));
        sort($days);
        $starts = (string) ($raw['starts'] ?? '');
        if ($days === [] || min($days) < 1 || max($days) > 7) {
            throw new InvalidArgumentException('Choose at least one day.');
        }
        if (preg_match(self::TIME, $starts) !== 1) {
            throw new InvalidArgumentException('Choose a start time.');
        }

        return ['days' => $days, 'starts' => $starts];
    }

    /**
     * The most recent start at or before `$now` (within the last 8 days), or null.
     *
     * @param  array{days: list<int>, starts: string}  $schedule
     */
    public static function lastStart(array $schedule, CarbonImmutable $now): ?CarbonImmutable
    {
        $best = null;
        foreach (self::starts($schedule, $now, -8, 1) as $start) {
            if ($start->lte($now) && ($best === null || $start->gt($best))) {
                $best = $start;
            }
        }

        return $best?->utc();
    }

    /**
     * The next start after `$now` (within the coming 8 days), or null.
     *
     * @param  array{days: list<int>, starts: string}  $schedule
     */
    public static function nextStart(array $schedule, CarbonImmutable $now): ?CarbonImmutable
    {
        $best = null;
        foreach (self::starts($schedule, $now, 0, 9) as $start) {
            if ($start->gt($now) && ($best === null || $start->lt($best))) {
                $best = $start;
            }
        }

        return $best?->utc();
    }

    /**
     * @param  array{days: list<int>, starts: string}  $schedule
     * @return list<CarbonImmutable>
     */
    private static function starts(array $schedule, CarbonImmutable $at, int $fromDay, int $toDay): array
    {
        $today = $at->setTimezone(self::ZONE)->startOfDay();
        $out = [];
        for ($offset = $fromDay; $offset < $toDay; $offset++) {
            $day = $today->addDays($offset);
            if (in_array($day->dayOfWeekIso, $schedule['days'], true)) {
                $out[] = CarbonImmutable::parse($day->format('Y-m-d').' '.$schedule['starts'], self::ZONE);
            }
        }

        return $out;
    }
}
