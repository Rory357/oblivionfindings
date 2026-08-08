<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Data\TimeSeriesPoint;
use InvalidArgumentException;

final class MetricRollupStatistics
{
    /**
     * @param  list<TimeSeriesPoint>  $points
     * @return array{p50: float, p95: float, min: float, max: float, count: int}
     */
    public static function calculate(array $points): array
    {
        if ($points === []) {
            throw new InvalidArgumentException('Metric roll-up points are required.');
        }

        $p50Values = [];
        $p95Values = [];
        $minimums = [];
        $maximums = [];
        $count = 0;

        foreach ($points as $point) {
            if (! $point instanceof TimeSeriesPoint) {
                throw new InvalidArgumentException('Metric roll-up point is invalid.');
            }
            $p50Values[] = (float) ($point->statistics['p50'] ?? $point->value);
            $p95Values[] = (float) ($point->statistics['p95'] ?? $point->value);
            $minimums[] = (float) ($point->statistics['min'] ?? $point->value);
            $maximums[] = (float) ($point->statistics['max'] ?? $point->value);
            $count += (int) ($point->statistics['count'] ?? 1);
        }

        return [
            'p50' => self::percentile($p50Values, 0.50),
            'p95' => self::percentile($p95Values, 0.95),
            'min' => min($minimums),
            'max' => max($maximums),
            'count' => $count,
        ];
    }

    /** @param list<float> $values */
    private static function percentile(array $values, float $percentile): float
    {
        sort($values, SORT_NUMERIC);
        $rank = $percentile * (count($values) - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);
        if ($lower === $upper) {
            return (float) $values[$lower];
        }

        return (float) ($values[$lower] + (($values[$upper] - $values[$lower]) * ($rank - $lower)));
    }
}
