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
use App\Domain\Monitoring\Models\MonitoringRetentionDeletionIntent;
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

    /**
     * @return array{
     *     metric_payloads_deleted: int,
     *     snapshot_payloads_deleted: int,
     *     held_series: int,
     *     held_snapshots: int,
     *     rollup_coverage_blocked_series: int,
     *     rollup_coverage_verified_series: int,
     *     occupied_rollup_buckets_verified: int,
     *     reconciled_deletion_intents: int,
     *     unresolved_deletion_intents: int
     * }
     */
    public function enforce(
        ?CarbonImmutable $now = null,
        ?int $actorId = null,
        ?string $jobReference = null,
        bool $includeSnapshots = true,
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
            'rollup_coverage_verified_series' => 0,
            'occupied_rollup_buckets_verified' => 0,
            'reconciled_deletion_intents' => 0,
            'unresolved_deletion_intents' => 0,
        ];
        $this->reconcileDeletionIntents($result, $jobReference);
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
                    $retentionDays = (int) $policy->{$daysField};
                    $cutoff = self::retentionCutoff(
                        (string) $series->retention_tier,
                        $now,
                        $retentionDays,
                    );
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
                    $prepared = DB::transaction(function () use (
                        $series,
                        $policy,
                        $periodStart,
                        $cutoff,
                        $jobReference,
                        $daysField,
                        $retentionDays,
                    ): array|null {
                        $locked = MetricSeries::query()->lockForUpdate()->findOrFail($series->id);
                        if ($locked->first_point_at === null
                            || ! CarbonImmutable::instance($locked->first_point_at)->utc()->equalTo($periodStart)) {
                            return null;
                        }
                        $currentPolicy = MonitoringRetentionPolicy::query()
                            ->whereKey($policy->id)
                            ->where('is_active', true)
                            ->lockForUpdate()
                            ->first();
                        if (! $currentPolicy instanceof MonitoringRetentionPolicy
                            || $currentPolicy->legal_hold
                            || (int) $currentPolicy->version !== (int) $policy->version
                            || (int) $currentPolicy->{$daysField} !== $retentionDays
                            || $this->hasExternalHold($locked)) {
                            return ['outcome' => 'held_or_changed'];
                        }
                        $coverage = $this->requiredRollupCoverage(
                            $locked,
                            $periodStart,
                            $cutoff,
                            lockForUpdate: true,
                        );
                        if (! $coverage['verified']) {
                            return ['outcome' => 'coverage_blocked'];
                        }
                        $intent = MonitoringRetentionDeletionIntent::query()->firstOrCreate([
                            'series_id' => $locked->id,
                            'retention_tier' => $locked->retention_tier,
                            'period_start' => $periodStart,
                            'period_end' => $cutoff,
                        ], [
                            'intent_uuid' => (string) Str::uuid(),
                            'job_reference' => $jobReference,
                            'site_id' => $locked->site_id,
                            'device_id' => $locked->device_id,
                            'monitor_id' => $locked->monitor_id,
                            'policy_id' => $currentPolicy->id,
                            'policy_version' => $currentPolicy->version,
                            'policy_scope_kind' => $currentPolicy->scope_kind,
                            'policy_identity_key' => $currentPolicy->identity_key,
                            'retention_days' => $retentionDays,
                            'data_class' => $locked->data_class,
                            'occupied_bucket_count' => $coverage['occupied_buckets'],
                            'rollup_evidence_sha256' => $coverage['commitment'],
                            'state' => 'pending',
                        ]);

                        return [
                            'outcome' => 'prepared',
                            'intent_id' => (int) $intent->id,
                            'occupied_buckets' => $coverage['occupied_buckets'],
                        ];
                    }, 3);
                    if (($prepared['outcome'] ?? null) === 'held_or_changed') {
                        $result['held_series']++;

                        continue;
                    }
                    if (($prepared['outcome'] ?? null) === 'coverage_blocked') {
                        $result['rollup_coverage_blocked_series']++;
                    } elseif (($prepared['outcome'] ?? null) === 'prepared') {
                        try {
                            $outcome = $this->completeDeletionIntent(
                                (int) $prepared['intent_id'],
                                $actorId,
                            );
                        } catch (Throwable) {
                            $result['unresolved_deletion_intents']++;

                            continue;
                        }
                        if ($outcome['outcome'] !== 'completed') {
                            continue;
                        }
                        $result['metric_payloads_deleted']++;
                        if (in_array($series->retention_tier, ['raw', 'hourly'], true)) {
                            $result['rollup_coverage_verified_series']++;
                            $result['occupied_rollup_buckets_verified'] += $prepared['occupied_buckets'];
                        }
                    }
                }
            });

        if (! $includeSnapshots) {
            return $result;
        }

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

    /** @param array<string, int> $result */
    private function reconcileDeletionIntents(array &$result, string $currentJobReference): void
    {
        MonitoringRetentionDeletionIntent::query()
            ->whereIn('state', ['pending', 'delete_acknowledged'])
            ->orderBy('id')
            ->chunkById(100, function ($batch) use (&$result, $currentJobReference): void {
                foreach ($batch as $intent) {
                    try {
                        $outcome = $this->completeDeletionIntent((int) $intent->id, null);
                        if ($outcome['outcome'] === 'completed') {
                            $result['reconciled_deletion_intents']++;
                            if (hash_equals($currentJobReference, (string) $intent->job_reference)) {
                                $result['metric_payloads_deleted']++;
                                if (in_array($intent->retention_tier, ['raw', 'hourly'], true)) {
                                    $result['rollup_coverage_verified_series']++;
                                    $result['occupied_rollup_buckets_verified'] += (int) $intent->occupied_bucket_count;
                                }
                            }
                        }
                    } catch (Throwable) {
                        $result['unresolved_deletion_intents']++;
                    }
                }
            });
    }

    /** @return array{outcome: string} */
    private function completeDeletionIntent(int $intentId, ?int $actorId): array
    {
        return DB::transaction(function () use ($intentId, $actorId): array {
            $intent = MonitoringRetentionDeletionIntent::query()->lockForUpdate()->findOrFail($intentId);
            if ($intent->state === 'completed') {
                return ['outcome' => 'already_completed'];
            }

            $series = MetricSeries::query()->lockForUpdate()->findOrFail($intent->series_id);
            $policy = MonitoringRetentionPolicy::query()->whereKey($intent->policy_id)->lockForUpdate()->first();
            if (! $policy instanceof MonitoringRetentionPolicy
                || ! $policy->is_active
                || $policy->legal_hold
                || (int) $policy->version !== (int) $intent->policy_version
                || ! hash_equals((string) $intent->policy_identity_key, (string) $policy->identity_key)
                || $this->hasExternalHold($series)) {
                throw new \RuntimeException('Retention deletion intent is no longer authorised.');
            }

            $from = CarbonImmutable::instance($intent->period_start)->utc();
            $until = CarbonImmutable::instance($intent->period_end)->utc();
            if ($series->first_point_at === null
                || ! CarbonImmutable::instance($series->first_point_at)->utc()->equalTo($from)) {
                throw new \RuntimeException('Retention deletion intent source pointer changed.');
            }

            if ($intent->state === 'pending') {
                $sourcePresent = $this->timeSeries->exists(
                    (string) $series->external_key,
                    (string) $series->retention_tier,
                    $from,
                    $until,
                );
                if ($sourcePresent) {
                    $coverage = $this->requiredRollupCoverage($series, $from, $until, lockForUpdate: true);
                    if (! $coverage['verified']
                        || $coverage['occupied_buckets'] !== (int) $intent->occupied_bucket_count
                        || ! hash_equals((string) $intent->rollup_evidence_sha256, $coverage['commitment'])) {
                        throw new \RuntimeException('Retention deletion intent evidence changed.');
                    }

                    $this->timeSeries->deleteRange(
                        (string) $series->external_key,
                        (string) $series->retention_tier,
                        $from,
                        $until,
                    );
                    if ($this->timeSeries->exists(
                        (string) $series->external_key,
                        (string) $series->retention_tier,
                        $from,
                        $until,
                    )) {
                        throw new \RuntimeException('Retention deletion intent range remains present.');
                    }
                }

                $intent->forceFill([
                    'state' => 'delete_acknowledged',
                    'delete_acknowledged_at' => CarbonImmutable::now('UTC'),
                ])->save();
            }

            $summary = MetricCurrentSummary::query()
                ->where('series_id', $series->id)
                ->lockForUpdate()
                ->first();
            if ($summary?->observed_at !== null && $summary->observed_at->lessThan($until)) {
                $summary->delete();
            }

            $nextPoint = null;
            if ($series->last_point_at !== null && $series->last_point_at->greaterThanOrEqualTo($until)) {
                $remaining = $this->timeSeries->range(
                    (string) $series->external_key,
                    (string) $series->retention_tier,
                    $until,
                    CarbonImmutable::instance($series->last_point_at)->utc()->addMicrosecond(),
                );
                $nextPoint = $remaining[0] ?? null;
                if ($nextPoint !== null && ! $nextPoint instanceof TimeSeriesPoint) {
                    throw new \RuntimeException('Retention deletion intent remaining pointer is invalid.');
                }
            }
            $series->first_point_at = $nextPoint?->observedAt;
            $series->last_point_at = $nextPoint === null ? null : $series->last_point_at;
            $series->save();

            $deletedAt = $intent->delete_acknowledged_at ?? CarbonImmutable::now('UTC');
            MonitoringRetentionTombstone::query()->firstOrCreate([
                'deletion_intent_id' => $intent->id,
            ], [
                'tombstone_uuid' => (string) Str::uuid(),
                'series_id' => $series->id,
                'site_id' => $intent->site_id,
                'device_id' => $intent->device_id,
                'monitor_id' => $intent->monitor_id,
                'data_class' => $intent->data_class,
                'retention_tier' => $intent->retention_tier,
                'period_start' => $from,
                'period_end' => $until,
                'policy_id' => $intent->policy_id,
                'deleted_by_user_id' => $actorId,
                'job_reference' => $intent->job_reference,
                'deleted_at' => $deletedAt,
            ]);
            $intent->forceFill([
                'state' => 'completed',
                'completed_at' => CarbonImmutable::now('UTC'),
            ])->save();

            return ['outcome' => 'completed'];
        }, 3);
    }

    public static function retentionCutoff(string $tier, CarbonImmutable $now, int $retentionDays): CarbonImmutable
    {
        $cutoff = $now->subDays($retentionDays);

        return match ($tier) {
            'raw' => $cutoff->startOfHour(),
            'hourly' => $cutoff->startOfDay(),
            default => $cutoff,
        };
    }

    /** @return list<int> */
    public function validatePointers(): array
    {
        $missing = [];
        MetricSeries::query()->whereNotNull('last_point_at')->orderBy('id')->chunkById(100, function ($batch) use (&$missing): void {
            foreach ($batch as $series) {
                try {
                    $exists = $this->hasExactSeriesBoundaryPoints($series);
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

    public function hasExactSeriesBoundaryPoints(MetricSeries $series): bool
    {
        if ($series->first_point_at === null || $series->last_point_at === null) {
            return false;
        }

        $first = CarbonImmutable::instance($series->first_point_at)->utc();
        $last = CarbonImmutable::instance($series->last_point_at)->utc();
        $boundaries = $first->equalTo($last) ? [$first] : [$first, $last];

        foreach ($boundaries as $boundary) {
            $points = $this->timeSeries->range(
                (string) $series->external_key,
                (string) $series->retention_tier,
                $boundary,
                $boundary->addMicrosecond(),
            );
            if (count($points) !== 1
                || ! $this->pointMatchesExactBoundary($points[0], $series, $boundary)) {
                return false;
            }
        }

        return true;
    }

    public function hasExternalSeriesLegalHold(MetricSeries $series): bool
    {
        return $this->hasExternalHold($series);
    }

    /** @return array{verified: bool, occupied_buckets: int, commitment: string} */
    private function requiredRollupCoverage(
        MetricSeries $source,
        CarbonImmutable $from,
        CarbonImmutable $until,
        bool $lockForUpdate = false,
    ): array {
        $targetTier = match ($source->retention_tier) {
            'raw' => 'hourly',
            'hourly' => 'daily',
            default => null,
        };
        if ($targetTier === null) {
            return $this->currentSourceEvidence($source, $from, $until);
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
            return ['verified' => false, 'occupied_buckets' => 0, 'commitment' => str_repeat('0', 64)];
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
            return ['verified' => false, 'occupied_buckets' => 0, 'commitment' => str_repeat('0', 64)];
        }

        return $this->currentRollupEvidence($source, $target, $targetTier, $from, $until);
    }

    /** @return array{verified: bool, occupied_buckets: int, commitment: string} */
    private function currentRollupEvidence(
        MetricSeries $source,
        MetricSeries $target,
        string $targetTier,
        CarbonImmutable $from,
        CarbonImmutable $until,
    ): array {
        $cursor = $from;
        $sourceEvidenceFound = false;
        $verifiedBuckets = 0;
        $commitment = hash_init('sha256');

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
                        return ['verified' => false, 'occupied_buckets' => 0, 'commitment' => str_repeat('0', 64)];
                    }

                    $sourceEvidenceFound = true;
                    $bucket = $targetTier === 'hourly'
                        ? $point->observedAt->utc()->startOfHour()
                        : $point->observedAt->utc()->startOfDay();
                    $expectedBuckets[$bucket->format('Y-m-d\TH:i:s.u\Z')][] = $point;
                }

                if ($expectedBuckets !== []) {
                    $expectedBucketKeys = array_keys($expectedBuckets);
                    sort($expectedBucketKeys, SORT_STRING);
                    $targetFrom = CarbonImmutable::parse($expectedBucketKeys[0]);
                    $lastBucket = CarbonImmutable::parse($expectedBucketKeys[array_key_last($expectedBucketKeys)]);
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
                            return ['verified' => false, 'occupied_buckets' => 0, 'commitment' => str_repeat('0', 64)];
                        }

                        $bucket = $targetTier === 'hourly'
                            ? $point->observedAt->utc()->startOfHour()
                            : $point->observedAt->utc()->startOfDay();
                        if (! $bucket->equalTo($point->observedAt)) {
                            return ['verified' => false, 'occupied_buckets' => 0, 'commitment' => str_repeat('0', 64)];
                        }
                        $actualBuckets[$bucket->format('Y-m-d\TH:i:s.u\Z')] = $point;
                    }

                    foreach ($expectedBucketKeys as $expectedBucket) {
                        $actual = $actualBuckets[$expectedBucket] ?? null;
                        $expected = MetricRollupStatistics::calculate($expectedBuckets[$expectedBucket]);
                        if (! $actual instanceof TimeSeriesPoint
                            || ! $this->statisticsMatch($expected, $actual)) {
                            return ['verified' => false, 'occupied_buckets' => 0, 'commitment' => str_repeat('0', 64)];
                        }
                        hash_update($commitment, $expectedBucket."\n".$this->canonicalJson([
                            'statistics' => $expected,
                            'value' => $expected['p95'],
                        ])."\n");
                    }
                    $verifiedBuckets += count($expectedBucketKeys);
                }

                $cursor = $windowEnd;
            }
        } catch (Throwable) {
            return ['verified' => false, 'occupied_buckets' => 0, 'commitment' => str_repeat('0', 64)];
        }

        return [
            'verified' => $sourceEvidenceFound,
            'occupied_buckets' => $sourceEvidenceFound ? $verifiedBuckets : 0,
            'commitment' => $sourceEvidenceFound ? hash_final($commitment) : str_repeat('0', 64),
        ];
    }

    /** @return array{verified: bool, occupied_buckets: int, commitment: string} */
    private function currentSourceEvidence(
        MetricSeries $source,
        CarbonImmutable $from,
        CarbonImmutable $until,
    ): array {
        $cursor = $from;
        $count = 0;
        $commitment = hash_init('sha256');

        try {
            while ($cursor->lessThan($until)) {
                $windowEnd = $cursor->addDays(31);
                if ($windowEnd->greaterThan($until)) {
                    $windowEnd = $until;
                }
                foreach ($this->timeSeries->range(
                    (string) $source->external_key,
                    (string) $source->retention_tier,
                    $cursor,
                    $windowEnd,
                ) as $point) {
                    if (! $this->pointMatchesSeries($point, $source, $cursor, $windowEnd)) {
                        return ['verified' => false, 'occupied_buckets' => 0, 'commitment' => str_repeat('0', 64)];
                    }
                    hash_update($commitment, $this->pointCommitment($point)."\n");
                    $count++;
                }
                $cursor = $windowEnd;
            }
        } catch (Throwable) {
            return ['verified' => false, 'occupied_buckets' => 0, 'commitment' => str_repeat('0', 64)];
        }

        return [
            'verified' => $count > 0,
            'occupied_buckets' => $count,
            'commitment' => $count > 0 ? hash_final($commitment) : str_repeat('0', 64),
        ];
    }

    /** @param array{p50: float, p95: float, min: float, max: float, count: int} $expected */
    private function statisticsMatch(array $expected, TimeSeriesPoint $actual): bool
    {
        if (array_keys($actual->statistics) !== ['p50', 'p95', 'min', 'max', 'count']
            || (int) $actual->statistics['count'] !== $expected['count']) {
            return false;
        }
        foreach (['p50', 'p95', 'min', 'max'] as $name) {
            if (abs((float) $actual->statistics[$name] - $expected[$name]) > 0.000000001) {
                return false;
            }
        }

        return abs($actual->value - $expected['p95']) <= 0.000000001;
    }

    private function pointCommitment(TimeSeriesPoint $point): string
    {
        return hash('sha256', $this->canonicalJson([
            'observed_at' => $point->observedAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'value' => $point->value,
            'statistics' => $point->statistics,
            'idempotency_key' => $point->idempotencyKey,
        ]));
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            if (is_array($item) && ! array_is_list($item)) {
                $item = json_decode($this->canonicalJson($item), true, flags: JSON_THROW_ON_ERROR);
            }
        }
        unset($item);

        return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
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

    private function pointMatchesExactBoundary(
        mixed $point,
        MetricSeries $series,
        CarbonImmutable $boundary,
    ): bool {
        return $point instanceof TimeSeriesPoint
            && $point->externalKey === $series->external_key
            && $point->seriesId === (int) $series->id
            && $point->siteId === (int) $series->site_id
            && $point->deviceId === (int) $series->device_id
            && $point->monitorId === $series->monitor_id
            && $point->metric === $series->metric
            && $point->unit === $series->unit
            && $point->dimensions === (array) $series->dimensions
            && $point->tier === $series->retention_tier
            && $point->observedAt->equalTo($boundary);
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
