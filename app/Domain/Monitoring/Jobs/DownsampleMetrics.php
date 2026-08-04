<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Data\TimeSeriesPoint;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Services\MetricIngestService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DownsampleMetrics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct()
    {
        $this->onConnection('redis');
        $this->onQueue((string) config('monitoring.queues.maintenance', 'monitoring-maintenance'));
    }

    public function handle(TimeSeriesStore $store, MetricIngestService $ingest): void
    {
        $now = CarbonImmutable::now('UTC');
        $this->downsample(
            $store,
            $ingest,
            sourceTier: 'raw',
            targetTier: 'hourly',
            from: $now->subDays((int) config('monitoring.retention.raw_days', 14)),
            to: $now->startOfHour(),
            bucket: fn (CarbonImmutable $time): CarbonImmutable => $time->startOfHour(),
        );
        $this->downsample(
            $store,
            $ingest,
            sourceTier: 'hourly',
            targetTier: 'daily',
            from: $now->subDays((int) config('monitoring.retention.hourly_days', 180)),
            to: $now->startOfDay(),
            bucket: fn (CarbonImmutable $time): CarbonImmutable => $time->startOfDay(),
        );
    }

    /** @param callable(CarbonImmutable): CarbonImmutable $bucket */
    private function downsample(
        TimeSeriesStore $store,
        MetricIngestService $ingest,
        string $sourceTier,
        string $targetTier,
        CarbonImmutable $from,
        CarbonImmutable $to,
        callable $bucket,
    ): void {
        MetricSeries::query()
            ->where('retention_tier', $sourceTier)
            ->whereNotNull('first_point_at')
            ->where('first_point_at', '<', $to)
            ->orderBy('id')
            ->chunkById(100, function ($seriesBatch) use (
                $store,
                $ingest,
                $sourceTier,
                $targetTier,
                $from,
                $to,
                $bucket,
            ): void {
                foreach ($seriesBatch as $series) {
                    $groups = collect($store->range(
                        $series->external_key,
                        $sourceTier,
                        $from,
                        $to->subMicrosecond(),
                    ))->groupBy(
                        fn (TimeSeriesPoint $point): string => $bucket($point->observedAt)->format('Y-m-d H:i:s.u'),
                    );

                    foreach ($groups as $points) {
                        /** @var TimeSeriesPoint $first */
                        $first = $points->first();
                        $ingest->writeRollup(
                            $series,
                            $targetTier,
                            $bucket($first->observedAt),
                            $this->statistics($points->all()),
                        );
                    }
                }
            });
    }

    /**
     * @param  list<TimeSeriesPoint>  $points
     * @return array{p50: float, p95: float, min: float, max: float, count: int}
     */
    private function statistics(array $points): array
    {
        $p50Values = [];
        $p95Values = [];
        $minimums = [];
        $maximums = [];
        $count = 0;

        foreach ($points as $point) {
            $p50Values[] = (float) ($point->statistics['p50'] ?? $point->value);
            $p95Values[] = (float) ($point->statistics['p95'] ?? $point->value);
            $minimums[] = (float) ($point->statistics['min'] ?? $point->value);
            $maximums[] = (float) ($point->statistics['max'] ?? $point->value);
            $count += (int) ($point->statistics['count'] ?? 1);
        }

        return [
            'p50' => $this->percentile($p50Values, 0.50),
            'p95' => $this->percentile($p95Values, 0.95),
            'min' => min($minimums),
            'max' => max($maximums),
            'count' => $count,
        ];
    }

    /** @param list<float> $values */
    private function percentile(array $values, float $percentile): float
    {
        sort($values, SORT_NUMERIC);
        $rank = ($percentile * (count($values) - 1));
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);
        if ($lower === $upper) {
            return (float) $values[$lower];
        }

        return (float) ($values[$lower] + (($values[$upper] - $values[$lower]) * ($rank - $lower)));
    }
}
