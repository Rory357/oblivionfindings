<?php

namespace App\Domain\Monitoring\Jobs;

use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Data\TimeSeriesPoint;
use App\Domain\Monitoring\Exceptions\TimeSeriesUnavailable;
use App\Domain\Monitoring\Models\MetricCurrentSummary;
use App\Domain\Monitoring\Models\MetricRollupCoverage;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Services\MetricIngestService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
            to: $now->startOfHour(),
            bucket: fn (CarbonImmutable $time): CarbonImmutable => $time->startOfHour(),
            window: fn (CarbonImmutable $time): CarbonImmutable => $time->addHours(
                min(168, max(1, (int) config('monitoring.retention.downsample_raw_window_hours', 24))),
            ),
        );
        $this->downsample(
            $store,
            $ingest,
            sourceTier: 'hourly',
            targetTier: 'daily',
            to: $now->startOfDay(),
            bucket: fn (CarbonImmutable $time): CarbonImmutable => $time->startOfDay(),
            window: fn (CarbonImmutable $time): CarbonImmutable => $time->addDays(
                min(31, max(1, (int) config('monitoring.retention.downsample_hourly_window_days', 31))),
            ),
        );
    }

    /**
     * @param  callable(CarbonImmutable): CarbonImmutable  $bucket
     * @param  callable(CarbonImmutable): CarbonImmutable  $window
     */
    private function downsample(
        TimeSeriesStore $store,
        MetricIngestService $ingest,
        string $sourceTier,
        string $targetTier,
        CarbonImmutable $to,
        callable $bucket,
        callable $window,
    ): void {
        $maximumWindows = min(
            64,
            max(1, (int) config('monitoring.retention.downsample_max_windows_per_series', 32)),
        );

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
                $to,
                $bucket,
                $window,
                $maximumWindows,
            ): void {
                foreach ($seriesBatch as $series) {
                    $sourceStart = $bucket(CarbonImmutable::instance($series->first_point_at)->utc());
                    if (! $sourceStart->lessThan($to)) {
                        continue;
                    }

                    $coverage = MetricRollupCoverage::query()
                        ->where('source_series_id', $series->id)
                        ->where('target_tier', $targetTier)
                        ->first();
                    if ($coverage !== null && $sourceStart->lessThan($coverage->covered_from)) {
                        // A late source point predates this watermark. Discard the
                        // mutable watermark and rebuild it from the true first bucket.
                        $coverage->delete();
                        $coverage = null;
                    }
                    if ($coverage !== null) {
                        $target = MetricSeries::query()->find($coverage->target_series_id);
                        if (! $target instanceof MetricSeries
                            || ! $this->isMatchingTarget($series, $target, $targetTier)) {
                            throw new RuntimeException('Metric roll-up coverage identity is invalid.');
                        }
                    }

                    $cursor = $coverage === null
                        ? $sourceStart
                        : CarbonImmutable::instance($coverage->covered_until)->utc();
                    $targetSeriesId = $coverage?->target_series_id === null
                        ? null
                        : (int) $coverage->target_series_id;

                    for ($completed = 0; $completed < $maximumWindows && $cursor->lessThan($to); $completed++) {
                        $windowEnd = $window($cursor);
                        if ($windowEnd->greaterThan($to)) {
                            $windowEnd = $to;
                        }
                        if (! $windowEnd->greaterThan($cursor)) {
                            throw new RuntimeException('Metric roll-up window is invalid.');
                        }

                        $series->refresh();
                        $sourceVersion = CarbonImmutable::instance($series->updated_at)->utc();
                        $sourceSampleCount = (int) MetricCurrentSummary::query()
                            ->where('series_id', $series->id)
                            ->value('sample_count');
                        $points = $store->range(
                            (string) $series->external_key,
                            $sourceTier,
                            $cursor,
                            $windowEnd,
                        );
                        $this->assertPointsBelongToWindow(
                            $points,
                            $series,
                            $sourceTier,
                            $cursor,
                            $windowEnd,
                        );
                        $groups = collect($points)->groupBy(
                            fn (TimeSeriesPoint $point): string => $bucket($point->observedAt)
                                ->format('Y-m-d H:i:s.u'),
                        );

                        foreach ($groups as $group) {
                            /** @var TimeSeriesPoint $first */
                            $first = $group->first();
                            $summary = $ingest->writeRollup(
                                $series,
                                $targetTier,
                                $bucket($first->observedAt),
                                $this->statistics($group->all()),
                            );
                            $writtenTargetId = (int) $summary->series_id;
                            if ($targetSeriesId !== null && $targetSeriesId !== $writtenTargetId) {
                                throw new RuntimeException('Metric roll-up target changed within one source series.');
                            }
                            $targetSeriesId = $writtenTargetId;
                        }

                        if ($targetSeriesId === null) {
                            throw new TimeSeriesUnavailable(
                                'Metric roll-up source coverage could not be verified.',
                            );
                        }
                        if (! $this->recordCoverage(
                            $series,
                            $targetSeriesId,
                            $targetTier,
                            $cursor,
                            $windowEnd,
                            $sourceVersion,
                            $sourceSampleCount,
                        )) {
                            // A source write raced the external read. Its writer
                            // invalidates any affected watermark; retry next run.
                            break;
                        }

                        $cursor = $windowEnd;
                    }
                }
            });
    }

    /** @param list<TimeSeriesPoint> $points */
    private function assertPointsBelongToWindow(
        array $points,
        MetricSeries $series,
        string $sourceTier,
        CarbonImmutable $from,
        CarbonImmutable $until,
    ): void {
        foreach ($points as $point) {
            if (! $point instanceof TimeSeriesPoint
                || $point->externalKey !== $series->external_key
                || $point->seriesId !== (int) $series->id
                || $point->tier !== $sourceTier
                || $point->observedAt->lessThan($from)
                || ! $point->observedAt->lessThan($until)) {
                throw new TimeSeriesUnavailable('Metric roll-up source coverage could not be verified.');
            }
        }
    }

    private function recordCoverage(
        MetricSeries $source,
        int $targetSeriesId,
        string $targetTier,
        CarbonImmutable $from,
        CarbonImmutable $until,
        CarbonImmutable $sourceVersion,
        int $sourceSampleCount,
    ): bool {
        return DB::transaction(function () use (
            $source,
            $targetSeriesId,
            $targetTier,
            $from,
            $until,
            $sourceVersion,
            $sourceSampleCount,
        ): bool {
            $lockedSource = MetricSeries::query()->lockForUpdate()->findOrFail($source->id);
            $lockedSummary = MetricCurrentSummary::query()
                ->where('series_id', $lockedSource->id)
                ->lockForUpdate()
                ->first();
            if (! CarbonImmutable::instance($lockedSource->updated_at)->utc()->equalTo($sourceVersion)
                || (int) ($lockedSummary?->sample_count ?? 0) !== $sourceSampleCount) {
                return false;
            }

            $target = MetricSeries::query()->findOrFail($targetSeriesId);
            if (! $this->isMatchingTarget($lockedSource, $target, $targetTier)) {
                throw new RuntimeException('Metric roll-up coverage identity is invalid.');
            }

            $coverage = MetricRollupCoverage::query()
                ->where('source_series_id', $lockedSource->id)
                ->where('target_tier', $targetTier)
                ->lockForUpdate()
                ->first();
            if ($coverage !== null
                && (! $coverage->covered_until->equalTo($from)
                    || (int) $coverage->target_series_id !== $targetSeriesId)) {
                throw new RuntimeException('Metric roll-up coverage is not contiguous.');
            }

            if ($coverage === null) {
                MetricRollupCoverage::query()->create([
                    'source_series_id' => $lockedSource->id,
                    'target_series_id' => $targetSeriesId,
                    'target_tier' => $targetTier,
                    'covered_from' => $from,
                    'covered_until' => $until,
                    'completed_at' => CarbonImmutable::now('UTC'),
                ]);
            } else {
                $coverage->forceFill([
                    'covered_until' => $until,
                    'completed_at' => CarbonImmutable::now('UTC'),
                ])->save();
            }

            return true;
        }, 3);
    }

    private function isMatchingTarget(
        MetricSeries $source,
        MetricSeries $target,
        string $targetTier,
    ): bool {
        return $target->retention_tier === $targetTier
            && (int) $target->site_id === (int) $source->site_id
            && (int) $target->device_id === (int) $source->device_id
            && $target->monitor_id === $source->monitor_id
            && $target->metric === $source->metric
            && $target->dimensions_hash === $source->dimensions_hash
            && $target->unit === $source->unit
            && $target->source === $source->source
            && $target->data_class === $source->data_class
            && $target->privacy_class === $source->privacy_class;
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
