<?php

namespace App\Domain\SecurityDevices\Reports;

use Carbon\CarbonImmutable;

/**
 * Time spans as [from, to) pairs of Unix seconds, for the monthly report: overlapping outages
 * count once, and a camera's privacy hours are taken out of its picture time.
 */
final class Intervals
{
    /**
     * Merge overlapping spans; empty or backwards spans are dropped.
     *
     * @param  list<array{0: int, 1: int}>  $spans
     * @return list<array{0: int, 1: int}>
     */
    public static function union(array $spans): array
    {
        usort($spans, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $out = [];
        foreach ($spans as [$from, $to]) {
            if ($to <= $from) {
                continue;
            }
            $last = count($out) - 1;
            if ($last >= 0 && $from <= $out[$last][1]) {
                $out[$last][1] = max($out[$last][1], $to);
            } else {
                $out[] = [$from, $to];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $spans  already merged
     * @return list<array{0: int, 1: int}>
     */
    public static function clip(array $spans, int $from, int $to): array
    {
        $out = [];
        foreach ($spans as [$a, $b]) {
            $a = max($a, $from);
            $b = min($b, $to);
            if ($b > $a) {
                $out[] = [$a, $b];
            }
        }

        return $out;
    }

    /** @param  list<array{0: int, 1: int}>  $spans */
    public static function length(array $spans): int
    {
        return array_sum(array_map(fn (array $span): int => $span[1] - $span[0], $spans));
    }

    /**
     * Seconds two merged lists share.
     *
     * @param  list<array{0: int, 1: int}>  $a
     * @param  list<array{0: int, 1: int}>  $b
     */
    public static function overlap(array $a, array $b): int
    {
        $total = 0;
        $i = 0;
        $j = 0;
        while ($i < count($a) && $j < count($b)) {
            $from = max($a[$i][0], $b[$j][0]);
            $to = min($a[$i][1], $b[$j][1]);
            if ($to > $from) {
                $total += $to - $from;
            }
            if ($a[$i][1] < $b[$j][1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $total;
    }

    /**
     * A weekly schedule (as camera privacy hours keep it: {days: 1–7 Monday first, from, to} in
     * local wall-clock time; "to" at or before "from" runs past midnight) as merged spans in
     * [from, to).
     *
     * @param  list<array<string, mixed>>  $ranges
     * @return list<array{0: int, 1: int}>
     */
    public static function weekly(array $ranges, int $from, int $to, string $timezone): array
    {
        $spans = [];
        $day = CarbonImmutable::createFromTimestamp($from, 'UTC')->setTimezone($timezone)->startOfDay()->subDay();
        $last = CarbonImmutable::createFromTimestamp($to, 'UTC')->setTimezone($timezone)->startOfDay();
        for (; $day->lte($last); $day = $day->addDay()) {
            foreach ($ranges as $range) {
                $days = array_map('intval', is_array($range['days'] ?? null) ? $range['days'] : []);
                $start = self::time($range['from'] ?? null);
                $end = self::time($range['to'] ?? null);
                if ($start === null || $end === null || ! in_array($day->isoWeekday(), $days, true)) {
                    continue;
                }
                $a = $day->setTime($start[0], $start[1]);
                $b = $day->setTime($end[0], $end[1]);
                if ($b->lte($a)) {
                    $b = $b->addDay();
                }
                $spans[] = [$a->getTimestamp(), $b->getTimestamp()];
            }
        }

        return self::clip(self::union($spans), $from, $to);
    }

    /** @return array{0: int, 1: int}|null */
    private static function time(mixed $value): ?array
    {
        if (! is_string($value) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $match) !== 1) {
            return null;
        }

        return [(int) $match[1], (int) $match[2]];
    }
}
