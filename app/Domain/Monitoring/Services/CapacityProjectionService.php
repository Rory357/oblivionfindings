<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Data\CapacityProjection;
use App\Domain\Monitoring\Data\TimeSeriesPoint;
use App\Domain\Monitoring\Models\MetricSeries;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class CapacityProjectionService
{
    public function __construct(private readonly TimeSeriesStore $store) {}

    public function project(
        MetricSeries $series,
        float $threshold,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): CapacityProjection {
        if (! is_finite($threshold)) {
            throw new InvalidArgumentException('Capacity threshold is invalid.');
        }

        $to ??= CarbonImmutable::now('UTC');
        $from ??= $to->subDays((int) config('monitoring.storage.capacity.lookback_days', 90));
        $points = $this->store->range(
            (string) $series->external_key,
            (string) $series->retention_tier,
            $from->utc(),
            $to->utc(),
        );
        $minimum = max(2, (int) config('monitoring.storage.capacity.minimum_samples', 12));
        if (count($points) < $minimum) {
            return new CapacityProjection(
                'insufficient_data',
                $points[0]->observedAt ?? null,
                $points === [] ? null : $points[array_key_last($points)]->observedAt,
                count($points),
                $points === [] ? null : $this->percentile($this->values($points), 0.95),
                null,
                0,
                $threshold,
                null,
            );
        }

        $first = $points[0]->observedAt;
        $last = $points[array_key_last($points)]->observedAt;
        $values = $this->values($points);
        $x = array_map(
            fn (TimeSeriesPoint $point): float => $first->diffInSeconds($point->observedAt) / 86400,
            $points,
        );
        [$slope, $confidence] = $this->regression($x, $values);
        $p95 = $this->percentile($values, 0.95);
        $thresholdAt = null;
        $state = $p95 >= $threshold ? 'threshold_reached' : 'stable';
        if ($p95 < $threshold && $slope > 0) {
            $days = ($threshold - $values[array_key_last($values)]) / $slope;
            if ($days >= 0 && is_finite($days)) {
                $thresholdAt = $last->addSeconds((int) round($days * 86400));
                $state = 'forecast';
            }
        }

        return new CapacityProjection(
            $state,
            $first,
            $last,
            count($points),
            $p95,
            $slope,
            $confidence,
            $threshold,
            $thresholdAt,
        );
    }

    /** @param list<TimeSeriesPoint> $points
     * @return list<float>
     */
    private function values(array $points): array
    {
        return array_map(
            fn (TimeSeriesPoint $point): float => isset($point->statistics['p95'])
                ? (float) $point->statistics['p95']
                : $point->value,
            $points,
        );
    }

    /** @param list<float> $x
     * @param  list<float>  $y
     * @return array{float, float}
     */
    private function regression(array $x, array $y): array
    {
        $count = count($x);
        $meanX = array_sum($x) / $count;
        $meanY = array_sum($y) / $count;
        $covariance = 0.0;
        $varianceX = 0.0;
        foreach ($x as $index => $candidate) {
            $covariance += ($candidate - $meanX) * ($y[$index] - $meanY);
            $varianceX += ($candidate - $meanX) ** 2;
        }
        if ($varianceX === 0.0) {
            return [0.0, 0.0];
        }
        $slope = $covariance / $varianceX;
        $intercept = $meanY - ($slope * $meanX);
        $residual = 0.0;
        $total = 0.0;
        foreach ($x as $index => $candidate) {
            $predicted = $intercept + ($slope * $candidate);
            $residual += ($y[$index] - $predicted) ** 2;
            $total += ($y[$index] - $meanY) ** 2;
        }
        $confidence = $total === 0.0 ? 1.0 : max(0.0, min(1.0, 1 - ($residual / $total)));

        return [$slope, $confidence];
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $percentile): float
    {
        sort($values, SORT_NUMERIC);
        $index = max(0, (int) ceil($percentile * count($values)) - 1);

        return (float) $values[$index];
    }
}
