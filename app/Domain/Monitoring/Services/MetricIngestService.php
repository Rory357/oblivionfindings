<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Data\MetricSample;
use App\Domain\Monitoring\Data\TimeSeriesPoint;
use App\Domain\Monitoring\Exceptions\TimeSeriesUnavailable;
use App\Domain\Monitoring\Models\MetricCurrentSummary;
use App\Domain\Monitoring\Models\MetricRollupCoverage;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\SecurityDevices\Models\Device;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class MetricIngestService
{
    public function __construct(
        private readonly TimeSeriesStore $store,
        private readonly CanonicalDeviceSiteResolver $sites,
    ) {}

    public function write(Monitor $monitor, MetricSample $sample): MetricCurrentSummary
    {
        $monitor->loadMissing('device');
        if (! $monitor->device instanceof Device) {
            throw new InvalidArgumentException('Metric monitor does not resolve to a canonical Device.');
        }

        return $this->writeForDevice(
            $monitor->device,
            $this->sites->resolve((int) $monitor->device_id),
            $sample,
            (int) $monitor->id,
        );
    }

    public function writeForDevice(
        Device $device,
        int $siteId,
        MetricSample $sample,
        ?int $monitorId = null,
    ): MetricCurrentSummary {
        if ($this->sites->resolve((int) $device->id) !== $siteId) {
            throw new InvalidArgumentException('Metric Site does not match the canonical Device.');
        }

        return $this->writePoint(
            siteId: $siteId,
            deviceId: (int) $device->id,
            monitorId: $monitorId,
            metric: $sample->metric,
            value: (float) $sample->value,
            unit: $sample->unit,
            dimensions: $sample->dimensions,
            source: $sample->source,
            dataClass: $sample->dataClass,
            privacyClass: $sample->privacyClass,
            tier: 'raw',
            observedAt: ($sample->observedAt ?? CarbonImmutable::now('UTC'))->utc(),
            statistics: [],
        );
    }

    /**
     * @param  array{p50: float, p95: float, min: float, max: float, count: int}  $statistics
     */
    public function writeRollup(
        MetricSeries $source,
        string $tier,
        CarbonImmutable $observedAt,
        array $statistics,
    ): MetricCurrentSummary {
        if (! in_array($tier, ['hourly', 'daily'], true)
            || ($source->retention_tier === 'raw' && $tier !== 'hourly')
            || ($source->retention_tier === 'hourly' && $tier !== 'daily')
            || ! isset($statistics['p50'], $statistics['p95'], $statistics['min'], $statistics['max'], $statistics['count'])
            || $statistics['count'] < 1) {
            throw new InvalidArgumentException('Metric rollup is invalid.');
        }

        return $this->writePoint(
            siteId: (int) $source->site_id,
            deviceId: (int) $source->device_id,
            monitorId: $source->monitor_id === null ? null : (int) $source->monitor_id,
            metric: (string) $source->metric,
            value: (float) $statistics['p95'],
            unit: (string) $source->unit,
            dimensions: (array) $source->dimensions,
            source: (string) $source->source,
            dataClass: (string) $source->data_class,
            privacyClass: (string) $source->privacy_class,
            tier: $tier,
            observedAt: $observedAt->utc(),
            statistics: $statistics,
        );
    }

    /**
     * @param  array<string, string|int|bool>  $dimensions
     * @param  array<string, float|int>  $statistics
     */
    private function writePoint(
        int $siteId,
        int $deviceId,
        ?int $monitorId,
        string $metric,
        float $value,
        string $unit,
        array $dimensions,
        string $source,
        string $dataClass,
        string $privacyClass,
        string $tier,
        CarbonImmutable $observedAt,
        array $statistics,
    ): MetricCurrentSummary {
        $dimensions = $this->normaliseDimensions($dimensions);
        $dimensionsHash = hash('sha256', $this->json($dimensions));
        $identity = [
            'site_id' => $siteId,
            'device_id' => $deviceId,
            'monitor_id' => $monitorId,
            'metric' => $metric,
            'dimensions_hash' => $dimensionsHash,
            'source' => $source,
            'data_class' => $dataClass,
            'privacy_class' => $privacyClass,
            'retention_tier' => $tier,
        ];
        $externalKey = hash('sha256', $this->json($identity));
        $idempotencyKey = hash('sha256', $this->json([
            'external_key' => $externalKey,
            'observed_at' => $observedAt->format('Y-m-d\TH:i:s.u\Z'),
            'value' => $value,
            'statistics' => $statistics,
        ]));

        $series = DB::transaction(function () use ($identity, $unit, $dimensions, $externalKey): MetricSeries {
            $unitConflict = MetricSeries::query()
                ->where($this->identityWithoutTier($identity))
                ->where('unit', '!=', $unit)
                ->lockForUpdate()
                ->exists();
            if ($unitConflict) {
                throw new InvalidArgumentException('Metric unit conflicts with its existing series identity.');
            }

            return MetricSeries::query()->firstOrCreate(
                ['external_key' => $externalKey],
                [
                    ...$identity,
                    'dimensions' => $dimensions,
                    'unit' => $unit,
                ],
            );
        }, 3);

        $summary = MetricCurrentSummary::query()->where('series_id', $series->id)->first();
        if ($summary !== null && hash_equals((string) $summary->last_idempotency_key, $idempotencyKey)) {
            return $summary;
        }

        $point = new TimeSeriesPoint(
            externalKey: $series->external_key,
            seriesId: (int) $series->id,
            siteId: $siteId,
            deviceId: $deviceId,
            monitorId: $monitorId,
            metric: $metric,
            value: $value,
            unit: $unit,
            dimensions: $dimensions,
            tier: $tier,
            observedAt: $observedAt,
            idempotencyKey: $idempotencyKey,
            statistics: $statistics,
        );

        try {
            return DB::transaction(function () use (
                $series,
                $point,
                $value,
                $statistics,
                $observedAt,
                $idempotencyKey,
            ): MetricCurrentSummary {
                // Serialise the external write with coverage advancement and
                // retention deletion for this one canonical source series.
                $lockedSeries = MetricSeries::query()->lockForUpdate()->findOrFail($series->id);
                $current = MetricCurrentSummary::query()
                    ->where('series_id', $series->id)
                    ->lockForUpdate()
                    ->first();

                if ($current !== null
                    && hash_equals((string) $current->last_idempotency_key, $idempotencyKey)) {
                    return $current;
                }

                $this->invalidateCoveredBucket($lockedSeries, $observedAt);
                try {
                    $this->store->writePoints([$point]);
                } catch (Throwable $exception) {
                    if ($exception instanceof TimeSeriesUnavailable) {
                        throw $exception;
                    }

                    throw new TimeSeriesUnavailable(
                        'Time-series storage is unavailable.',
                        previous: $exception,
                    );
                }

                $lockedSeries->first_point_at = $lockedSeries->first_point_at === null
                    || $observedAt->lessThan($lockedSeries->first_point_at)
                        ? $observedAt
                        : $lockedSeries->first_point_at;
                $lockedSeries->last_point_at = $lockedSeries->last_point_at === null
                    || $observedAt->greaterThan($lockedSeries->last_point_at)
                        ? $observedAt
                        : $lockedSeries->last_point_at;
                $lockedSeries->save();

                $attributes = [
                    'sample_count' => ($current?->sample_count ?? 0) + 1,
                    'last_idempotency_key' => $idempotencyKey,
                    'storage_state' => 'available',
                    'storage_checked_at' => now(),
                ];
                if ($current?->observed_at === null
                    || $observedAt->greaterThanOrEqualTo($current->observed_at)) {
                    $attributes['value'] = $value;
                    $attributes['statistics'] = $statistics === [] ? null : $statistics;
                    $attributes['observed_at'] = $observedAt;
                }

                return MetricCurrentSummary::query()->updateOrCreate(
                    ['series_id' => $series->id],
                    $attributes,
                );
            }, 3);
        } catch (TimeSeriesUnavailable $exception) {
            MetricCurrentSummary::query()->updateOrCreate(
                ['series_id' => $series->id],
                [
                    'storage_state' => 'unavailable',
                    'storage_checked_at' => now(),
                ],
            );

            throw $exception;
        }
    }

    private function invalidateCoveredBucket(MetricSeries $source, CarbonImmutable $observedAt): void
    {
        MetricRollupCoverage::query()
            ->where('source_series_id', $source->id)
            ->where('covered_until', '>', $observedAt)
            ->lockForUpdate()
            ->get()
            ->each(function (MetricRollupCoverage $coverage) use ($observedAt): void {
                $bucketStart = $coverage->target_tier === 'hourly'
                    ? $observedAt->startOfHour()
                    : $observedAt->startOfDay();

                if (! $bucketStart->greaterThan($coverage->covered_from)) {
                    // One interval row cannot represent a hole at its beginning.
                    // Rebuild from the source's first bucket on the next run.
                    $coverage->delete();

                    return;
                }

                $coverage->forceFill([
                    'covered_until' => $bucketStart,
                ])->save();
            });
    }

    /** @param array<string, mixed> $identity */
    private function identityWithoutTier(array $identity): array
    {
        unset($identity['retention_tier']);

        return $identity;
    }

    /**
     * @param  array<string, string|int|bool>  $dimensions
     * @return array<string, string|int|bool>
     */
    private function normaliseDimensions(array $dimensions): array
    {
        ksort($dimensions, SORT_STRING);

        return $dimensions;
    }

    private function json(array $value): string
    {
        ksort($value, SORT_STRING);

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
