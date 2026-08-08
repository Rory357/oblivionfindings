<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Data\TimeSeriesPoint;
use App\Domain\Monitoring\Exceptions\TimeSeriesUnavailable;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\MetricCurrentSummary;
use App\Domain\Monitoring\Models\MetricRollupCoverage;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Models\MonitoringRetentionPolicy;
use App\Domain\Monitoring\Models\MonitoringRetentionTombstone;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\LegalHold;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class RetentionEnforcer
{
    public function __construct(
        private readonly TimeSeriesStore $timeSeries,
        private readonly SnapshotStore $snapshots,
        private readonly MonitoringRetentionPolicyMatcher $policyMatcher,
    ) {}

    /** @return array{metric_payloads_deleted: int, snapshot_payloads_deleted: int, held_series: int, held_snapshots: int, rollup_coverage_blocked_series: int} */
    public function enforce(
        ?CarbonImmutable $now = null,
        ?int $actorId = null,
        ?string $jobReference = null,
    ): array {
        $now ??= CarbonImmutable::now('UTC');
        $jobReference ??= (string) Str::uuid();
        if (! Str::isUuid($jobReference)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $jobReference) !== 1) {
            throw new \InvalidArgumentException('Retention job reference is invalid.');
        }

        $result = [
            'metric_payloads_deleted' => 0,
            'snapshot_payloads_deleted' => 0,
            'held_series' => 0,
            'held_snapshots' => 0,
            'rollup_coverage_blocked_series' => 0,
        ];
        $policies = MonitoringRetentionPolicy::query()->where('is_active', true)->get();

        MetricSeries::query()
            ->whereNotNull('first_point_at')
            ->orderBy('id')
            ->chunkById(100, function ($seriesBatch) use (
                $policies,
                $now,
                $actorId,
                $jobReference,
                &$result,
            ): void {
                foreach ($seriesBatch as $series) {
                    $matches = $this->policyMatcher->matchingSeries($series, $policies);
                    if ($matches->isEmpty()) {
                        continue;
                    }
                    if ($matches->contains('legal_hold', true) || $this->hasExternalHold($series)) {
                        $result['held_series']++;

                        continue;
                    }

                    $daysField = $series->retention_tier.'_days';
                    /** @var MonitoringRetentionPolicy $policy */
                    $policy = $matches->sortBy(fn (MonitoringRetentionPolicy $candidate): int => (int) $candidate->{$daysField})->first();
                    $cutoff = $now->subDays((int) $policy->{$daysField});
                    if ($series->first_point_at->greaterThanOrEqualTo($cutoff)) {
                        continue;
                    }
                    $periodStart = CarbonImmutable::instance($series->first_point_at)->utc();
                    if (MonitoringRetentionTombstone::query()
                        ->where('series_id', $series->id)
                        ->where('retention_tier', $series->retention_tier)
                        ->where('period_end', $cutoff)
                        ->exists()) {
                        continue;
                    }
                    $outcome = DB::transaction(function () use (
                        $series,
                        $policy,
                        $periodStart,
                        $cutoff,
                        $actorId,
                        $jobReference,
                        $now,
                    ): string {
                        $locked = MetricSeries::query()->lockForUpdate()->findOrFail($series->id);
                        if ($locked->first_point_at === null
                            || ! CarbonImmutable::instance($locked->first_point_at)->utc()->equalTo($periodStart)) {
                            return 'stale';
                        }
                        if (! $this->hasRequiredRollupCoverage(
                            $locked,
                            $periodStart,
                            $cutoff,
                            lockForUpdate: true,
                        )) {
                            return 'coverage_blocked';
                        }

                        $this->timeSeries->deleteRange(
                            (string) $locked->external_key,
                            (string) $locked->retention_tier,
                            $periodStart,
                            $cutoff,
                        );

                        $summary = MetricCurrentSummary::query()
                            ->where('series_id', $series->id)
                            ->lockForUpdate()
                            ->first();
                        if ($summary?->observed_at !== null && $summary->observed_at->lessThan($cutoff)) {
                            $summary->delete();
                        }
                        $locked->first_point_at = $locked->last_point_at !== null
                            && $locked->last_point_at->greaterThanOrEqualTo($cutoff)
                                ? $cutoff
                                : null;
                        if ($locked->last_point_at !== null && $locked->last_point_at->lessThan($cutoff)) {
                            $locked->last_point_at = null;
                        }
                        $locked->save();

                        MonitoringRetentionTombstone::query()->create([
                            'tombstone_uuid' => (string) Str::uuid(),
                            'series_id' => $locked->id,
                            'site_id' => $locked->site_id,
                            'device_id' => $locked->device_id,
                            'monitor_id' => $locked->monitor_id,
                            'data_class' => $locked->data_class,
                            'retention_tier' => $locked->retention_tier,
                            'period_start' => $periodStart,
                            'period_end' => $cutoff,
                            'policy_id' => $policy->id,
                            'deleted_by_user_id' => $actorId,
                            'job_reference' => $jobReference,
                            'deleted_at' => $now,
                        ]);

                        return 'deleted';
                    }, 3);
                    if ($outcome === 'coverage_blocked') {
                        $result['rollup_coverage_blocked_series']++;
                    } elseif ($outcome === 'deleted') {
                        $result['metric_payloads_deleted']++;
                    }
                }
            });

        ConfigurationSnapshot::query()
            ->where('storage_state', 'available')
            ->orderBy('id')
            ->chunkById(100, function ($batch) use ($policies, $now, $actorId, $jobReference, &$result): void {
                foreach ($batch as $snapshot) {
                    $matches = $policies->filter(
                        fn (MonitoringRetentionPolicy $policy): bool => $this->policyMatcher->matchesSnapshot($policy, $snapshot),
                    );
                    $policy = $snapshot->retention_policy_id === null
                        ? $matches->sortBy('daily_days')->first()
                        : $policies->firstWhere('id', $snapshot->retention_policy_id);
                    if (! $policy instanceof MonitoringRetentionPolicy) {
                        continue;
                    }
                    if ($matches->contains('legal_hold', true)
                        || $policy->legal_hold
                        || $this->hasSnapshotHold($snapshot)) {
                        $result['held_snapshots']++;

                        continue;
                    }
                    $cutoff = $now->subDays((int) $policy->daily_days);
                    if ($snapshot->captured_at->greaterThan($cutoff)) {
                        continue;
                    }

                    $this->snapshots->delete($snapshot->storage_path);
                    DB::transaction(function () use ($snapshot, $policy, $actorId, $jobReference, $now): void {
                        $locked = ConfigurationSnapshot::query()->lockForUpdate()->findOrFail($snapshot->id);
                        $locked->forceFill([
                            'storage_state' => 'deleted',
                            'payload_deleted_at' => $now,
                        ])->save();
                        MonitoringRetentionTombstone::query()->create([
                            'tombstone_uuid' => (string) Str::uuid(),
                            'snapshot_id' => $snapshot->id,
                            'site_id' => $snapshot->site_id,
                            'device_id' => $snapshot->device_id,
                            'monitor_id' => null,
                            'data_class' => 'configuration',
                            'retention_tier' => 'configuration',
                            'period_start' => $snapshot->captured_at,
                            'period_end' => $snapshot->captured_at,
                            'policy_id' => $policy->id,
                            'deleted_by_user_id' => $actorId,
                            'job_reference' => $jobReference,
                            'deleted_at' => $now,
                        ]);
                    }, 3);
                    $result['snapshot_payloads_deleted']++;
                }
            });

        return $result;
    }

    /** @return list<int> */
    public function validatePointers(): array
    {
        $missing = [];
        MetricSeries::query()->whereNotNull('last_point_at')->orderBy('id')->chunkById(100, function ($batch) use (&$missing): void {
            foreach ($batch as $series) {
                try {
                    $exists = $this->timeSeries->exists(
                        $series->external_key,
                        $series->retention_tier,
                        $series->first_point_at === null
                            ? null
                            : CarbonImmutable::instance($series->first_point_at)->utc(),
                        CarbonImmutable::instance($series->last_point_at)->utc()->addMicrosecond(),
                    );
                    $state = $exists ? 'available' : 'missing';
                } catch (TimeSeriesUnavailable) {
                    $state = 'unavailable';
                    $exists = false;
                }
                MetricCurrentSummary::query()->updateOrCreate(
                    ['series_id' => $series->id],
                    ['storage_state' => $state, 'storage_checked_at' => now()],
                );
                if (! $exists) {
                    $missing[] = (int) $series->id;
                }
            }
        });

        return $missing;
    }

    private function hasRequiredRollupCoverage(
        MetricSeries $source,
        CarbonImmutable $from,
        CarbonImmutable $until,
        bool $lockForUpdate = false,
    ): bool {
        $targetTier = match ($source->retention_tier) {
            'raw' => 'hourly',
            'hourly' => 'daily',
            default => null,
        };
        if ($targetTier === null) {
            return true;
        }

        $query = MetricRollupCoverage::query()
            ->where('source_series_id', $source->id)
            ->where('target_tier', $targetTier);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $coverage = $query->first();
        if ($coverage === null
            || $coverage->covered_from->greaterThan($from)
            || $coverage->covered_until->lessThan($until)) {
            return false;
        }

        $target = $coverage->targetSeries;

        $matches = $target instanceof MetricSeries
            && $target->retention_tier === $targetTier
            && (int) $target->site_id === (int) $source->site_id
            && (int) $target->device_id === (int) $source->device_id
            && $target->monitor_id === $source->monitor_id
            && $target->metric === $source->metric
            && $target->dimensions_hash === $source->dimensions_hash
            && $target->unit === $source->unit
            && $target->source === $source->source
            && $target->data_class === $source->data_class
            && $target->privacy_class === $source->privacy_class;
        if (! $matches) {
            return false;
        }

        return $this->hasCurrentRollupEvidence($source, $target, $targetTier, $from, $until);
    }

    private function hasCurrentRollupEvidence(
        MetricSeries $source,
        MetricSeries $target,
        string $targetTier,
        CarbonImmutable $from,
        CarbonImmutable $until,
    ): bool {
        $cursor = $from;
        $sourceEvidenceFound = false;

        try {
            while ($cursor->lessThan($until)) {
                $windowEnd = $targetTier === 'hourly'
                    ? $cursor->addDay()
                    : $cursor->addDays(31);
                if ($windowEnd->greaterThan($until)) {
                    $windowEnd = $until;
                }

                $sourcePoints = $this->timeSeries->range(
                    (string) $source->external_key,
                    (string) $source->retention_tier,
                    $cursor,
                    $windowEnd,
                );
                $expectedBuckets = [];
                foreach ($sourcePoints as $point) {
                    if (! $this->pointMatchesSeries($point, $source, $cursor, $windowEnd)) {
                        return false;
                    }

                    $sourceEvidenceFound = true;
                    $bucket = $targetTier === 'hourly'
                        ? $point->observedAt->utc()->startOfHour()
                        : $point->observedAt->utc()->startOfDay();
                    $expectedBuckets[$bucket->format('Y-m-d\TH:i:s.u\Z')] = $bucket;
                }

                if ($expectedBuckets !== []) {
                    $expectedBucketKeys = array_keys($expectedBuckets);
                    sort($expectedBucketKeys, SORT_STRING);
                    $targetFrom = $expectedBuckets[$expectedBucketKeys[0]];
                    $lastBucket = $expectedBuckets[$expectedBucketKeys[array_key_last($expectedBucketKeys)]];
                    $targetUntil = $targetTier === 'hourly'
                        ? $lastBucket->addHour()
                        : $lastBucket->addDay();
                    $actualBuckets = [];
                    foreach ($this->timeSeries->range(
                        (string) $target->external_key,
                        $targetTier,
                        $targetFrom,
                        $targetUntil,
                    ) as $point) {
                        if (! $this->pointMatchesSeries($point, $target, $targetFrom, $targetUntil)) {
                            return false;
                        }

                        $bucket = $targetTier === 'hourly'
                            ? $point->observedAt->utc()->startOfHour()
                            : $point->observedAt->utc()->startOfDay();
                        if (! $bucket->equalTo($point->observedAt)) {
                            return false;
                        }
                        $actualBuckets[$bucket->format('Y-m-d\TH:i:s.u\Z')] = true;
                    }

                    foreach ($expectedBucketKeys as $expectedBucket) {
                        if (! isset($actualBuckets[$expectedBucket])) {
                            return false;
                        }
                    }
                }

                $cursor = $windowEnd;
            }
        } catch (Throwable) {
            return false;
        }

        return $sourceEvidenceFound;
    }

    private function pointMatchesSeries(
        mixed $point,
        MetricSeries $series,
        CarbonImmutable $from,
        CarbonImmutable $until,
    ): bool {
        return $point instanceof TimeSeriesPoint
            && $point->externalKey === $series->external_key
            && $point->seriesId === (int) $series->id
            && $point->tier === $series->retention_tier
            && $point->observedAt->greaterThanOrEqualTo($from)
            && $point->observedAt->lessThan($until);
    }

    private function hasExternalHold(MetricSeries $series): bool
    {
        return LegalHold::query()->active()->where(function ($query) use ($series): void {
            $query->where(function ($device) use ($series): void {
                $device->whereIn('holdable_type', [Device::class, 'security_device'])
                    ->where('holdable_id', $series->device_id);
            })->orWhere(function ($site) use ($series): void {
                $site->whereIn('holdable_type', [Site::class, 'site'])
                    ->where('holdable_id', $series->site_id);
            })->orWhere(function ($metric) use ($series): void {
                $metric->where('holdable_type', MetricSeries::class)
                    ->where('holdable_id', $series->id);
            });
        })->exists();
    }

    private function hasSnapshotHold(ConfigurationSnapshot $snapshot): bool
    {
        return LegalHold::query()->active()->where(function ($query) use ($snapshot): void {
            $query->where(function ($device) use ($snapshot): void {
                $device->whereIn('holdable_type', [Device::class, 'security_device'])
                    ->where('holdable_id', $snapshot->device_id);
            })->orWhere(function ($site) use ($snapshot): void {
                $site->whereIn('holdable_type', [Site::class, 'site'])
                    ->where('holdable_id', $snapshot->site_id);
            })->orWhere(function ($evidence) use ($snapshot): void {
                $evidence->where('holdable_type', ConfigurationSnapshot::class)
                    ->where('holdable_id', $snapshot->id);
            });
        })->exists();
    }
}
