<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Data\TimeSeriesPoint;
use App\Domain\Monitoring\Models\MetricRollupCoverage;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Models\MonitoringRetentionPolicy;
use App\Domain\Monitoring\Models\MonitoringRetentionTombstone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProductionRetentionEvidenceVerifier
{
    public function __construct(
        private readonly TimeSeriesStore $timeSeries,
        private readonly MonitoringRetentionPolicyMatcher $policyMatcher,
        private readonly RetentionEnforcer $retentionEnforcer,
    ) {}

    /**
     * Capture only the opaque record keys needed to prove that a due legal-hold
     * cohort survived the run. This state is never serialized to the artifact.
     *
     * @return array{held: array<int, array{first: string, last: string, external_key: string, tier: string, point_count: int, payload_sha256: string}>}
     */
    public function captureBefore(CarbonImmutable $now): array
    {
        $policies = MonitoringRetentionPolicy::query()->where('is_active', true)->get();
        $held = [];

        MetricSeries::query()
            ->whereNotNull('first_point_at')
            ->orderBy('id')
            ->chunkById(100, function ($batch) use ($policies, $now, &$held): void {
                foreach ($batch as $series) {
                    $matches = $this->policyMatcher->matchingSeries($series, $policies);
                    if ($matches->isEmpty()) {
                        continue;
                    }

                    $daysField = $series->retention_tier.'_days';
                    /** @var MonitoringRetentionPolicy $policy */
                    $policy = $matches->sortBy(
                        fn (MonitoringRetentionPolicy $candidate): int => (int) $candidate->{$daysField},
                    )->first();
                    $cutoff = RetentionEnforcer::retentionCutoff(
                        (string) $series->retention_tier,
                        $now,
                        (int) $policy->{$daysField},
                    );
                    if ($series->first_point_at->greaterThanOrEqualTo($cutoff)) {
                        continue;
                    }
                    if (! $matches->contains('legal_hold', true)
                        && ! $this->retentionEnforcer->hasExternalSeriesLegalHold($series)) {
                        continue;
                    }

                    $first = CarbonImmutable::instance($series->first_point_at)->utc();
                    $last = CarbonImmutable::instance($series->last_point_at)->utc();
                    $commitment = $this->seriesRangeCommitment($series, $first, $last->addMicrosecond());
                    if ($commitment['point_count'] < 1) {
                        throw new \RuntimeException('Due legal-hold payload evidence is unavailable.');
                    }
                    $held[(int) $series->id] = [
                        'first' => $first->format('Y-m-d\TH:i:s.u\Z'),
                        'last' => $last->format('Y-m-d\TH:i:s.u\Z'),
                        'external_key' => (string) $series->external_key,
                        'tier' => (string) $series->retention_tier,
                        'point_count' => $commitment['point_count'],
                        'payload_sha256' => $commitment['payload_sha256'],
                    ];
                }
            });

        return ['held' => $held];
    }

    /**
     * @param  array{held: array<int, array{first: string, last: string, external_key: string, tier: string, point_count: int, payload_sha256: string}>}  $before
     * @param array{
     *     metric_payloads_deleted: int,
     *     rollup_coverage_blocked_series: int,
     *     rollup_coverage_verified_series: int,
     *     occupied_rollup_buckets_verified: int
     * } $retention
     * @return array{
     *     execution: array<string, int>,
     *     integrity: array<string, int>,
     *     errors: list<string>
     * }
     */
    public function verify(
        string $jobReference,
        CarbonImmutable $started,
        CarbonImmutable $completed,
        array $before,
        array $retention,
    ): array {
        $errors = [];
        $tombstones = MonitoringRetentionTombstone::query()
            ->with(['series', 'deletionIntent'])
            ->where('job_reference', $jobReference)
            ->whereNotNull('series_id')
            ->get();
        $rawTombstones = $tombstones->where('retention_tier', 'raw');
        $hourlyTombstones = $tombstones->where('retention_tier', 'hourly');
        $privacyTombstones = $tombstones->filter(
            fn (MonitoringRetentionTombstone $tombstone): bool => $tombstone->deletionIntent?->policy_scope_kind === 'privacy',
        );

        if ($tombstones->isEmpty()) {
            $errors[] = 'retention_tombstone_missing';
        }
        if ($rawTombstones->isEmpty()) {
            $errors[] = 'raw_retention_not_exercised';
        }
        if ($hourlyTombstones->isEmpty()) {
            $errors[] = 'hourly_retention_not_exercised';
        }
        if ($privacyTombstones->isEmpty()) {
            $errors[] = 'privacy_retention_not_exercised';
        }
        if (($retention['rollup_coverage_blocked_series'] ?? -1) !== 0) {
            $errors[] = 'rollup_coverage_blocked';
        }
        if (($retention['unresolved_deletion_intents'] ?? -1) !== 0) {
            $errors[] = 'deletion_intent_unresolved';
        }
        if (($retention['metric_payloads_deleted'] ?? -1) !== $tombstones->count()) {
            $errors[] = 'retention_execution_count_mismatch';
        }

        $expectedCoveredDeletes = $rawTombstones->count() + $hourlyTombstones->count();
        if ($expectedCoveredDeletes < 1
            || ($retention['rollup_coverage_verified_series'] ?? -1) !== $expectedCoveredDeletes
            || ($retention['occupied_rollup_buckets_verified'] ?? 0) < $expectedCoveredDeletes) {
            $errors[] = 'occupied_bucket_coverage_not_proved';
        }

        $lineageGaps = 0;
        $deletedRangeGaps = 0;
        foreach ($tombstones as $tombstone) {
            $series = $tombstone->series;
            $intent = $tombstone->deletionIntent;
            if (! $series instanceof MetricSeries
                || $intent === null
                || $intent->state !== 'completed'
                || (int) $intent->series_id !== (int) $tombstone->series_id
                || $intent->job_reference !== $tombstone->job_reference
                || ! $intent->period_start->equalTo($tombstone->period_start)
                || ! $intent->period_end->equalTo($tombstone->period_end)
                || $intent->delete_acknowledged_at === null
                || $intent->completed_at === null
                || $intent->delete_acknowledged_at->greaterThan($intent->completed_at)
                || (int) $series->site_id !== (int) $tombstone->site_id
                || (int) $series->device_id !== (int) $tombstone->device_id
                || $series->monitor_id !== $tombstone->monitor_id
                || $series->data_class !== $tombstone->data_class
                || $series->retention_tier !== $tombstone->retention_tier
                || $tombstone->deleted_at->lessThan($started)
                || $tombstone->deleted_at->greaterThan($completed)) {
                $lineageGaps++;

                continue;
            }

            if (in_array($series->retention_tier, ['raw', 'hourly'], true)
                && ! $this->coverageMatchesTombstone($series, $tombstone)) {
                $lineageGaps++;
            }

            try {
                if ($this->timeSeries->exists(
                    (string) $series->external_key,
                    (string) $series->retention_tier,
                    CarbonImmutable::instance($tombstone->period_start)->utc(),
                    CarbonImmutable::instance($tombstone->period_end)->utc(),
                )) {
                    $deletedRangeGaps++;
                }
            } catch (Throwable) {
                $deletedRangeGaps++;
            }
        }

        $connectedChains = $this->connectedRollupChains(
            $rawTombstones->all(),
            $hourlyTombstones->all(),
            $started,
            $completed,
        );
        if ($connectedChains < 1) {
            $errors[] = 'raw_hourly_daily_execution_not_proved';
        }

        $heldGaps = 0;
        foreach ($before['held'] as $seriesId => $held) {
            $series = MetricSeries::query()->find($seriesId);
            if (! $series instanceof MetricSeries
                || $series->first_point_at === null
                || $series->last_point_at === null
                || CarbonImmutable::instance($series->first_point_at)->utc()->format('Y-m-d\TH:i:s.u\Z') !== $held['first']
                || CarbonImmutable::instance($series->last_point_at)->utc()->format('Y-m-d\TH:i:s.u\Z') !== $held['last']
                || $tombstones->contains('series_id', $seriesId)) {
                $heldGaps++;

                continue;
            }

            try {
                $commitment = $this->seriesRangeCommitment(
                    $series,
                    CarbonImmutable::parse($held['first']),
                    CarbonImmutable::parse($held['last'])->addMicrosecond(),
                );
                if ($commitment['point_count'] !== $held['point_count']
                    || ! hash_equals($held['payload_sha256'], $commitment['payload_sha256'])) {
                    $heldGaps++;
                }
            } catch (Throwable) {
                $heldGaps++;
            }
        }
        if ($before['held'] === []) {
            $errors[] = 'due_legal_hold_cohort_missing';
        }

        $businessReferenceGaps = $this->businessReferenceGaps();
        $pointerGaps = $this->pointerGaps();
        $externalReferenceGaps = $this->externalReferenceGaps();
        if ($lineageGaps > 0) {
            $errors[] = 'tombstone_lineage_gap';
        }
        if ($deletedRangeGaps > 0) {
            $errors[] = 'deleted_range_still_present';
        }
        if ($heldGaps > 0) {
            $errors[] = 'legal_hold_preservation_gap';
        }
        if ($businessReferenceGaps > 0) {
            $errors[] = 'mysql_business_reference_gap';
        }
        if ($pointerGaps > 0) {
            $errors[] = 'mysql_pointer_gap';
        }
        if ($externalReferenceGaps > 0) {
            $errors[] = 'timeseries_reference_gap';
        }

        sort($errors);

        return [
            'execution' => [
                'raw_to_hourly_chain_count' => $connectedChains,
                'hourly_to_daily_chain_count' => $connectedChains,
                'tombstone_count' => $tombstones->count(),
                'raw_tombstone_count' => $rawTombstones->count(),
                'hourly_tombstone_count' => $hourlyTombstones->count(),
                'privacy_tombstone_count' => $privacyTombstones->count(),
                'held_record_count' => count($before['held']),
                'coverage_verified_count' => (int) ($retention['rollup_coverage_verified_series'] ?? 0),
                'occupied_buckets_verified_count' => (int) ($retention['occupied_rollup_buckets_verified'] ?? 0),
                'coverage_blocked_count' => (int) ($retention['rollup_coverage_blocked_series'] ?? 0),
                'reconciled_deletion_intent_count' => (int) ($retention['reconciled_deletion_intents'] ?? 0),
                'unresolved_deletion_intent_count' => (int) ($retention['unresolved_deletion_intents'] ?? 0),
            ],
            'integrity' => [
                'tombstone_lineage_gap_count' => $lineageGaps,
                'deleted_range_gap_count' => $deletedRangeGaps,
                'legal_hold_gap_count' => $heldGaps,
                'business_reference_gap_count' => $businessReferenceGaps,
                'pointer_gap_count' => $pointerGaps,
                'timeseries_reference_gap_count' => $externalReferenceGaps,
            ],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    private function coverageMatchesTombstone(
        MetricSeries $series,
        MonitoringRetentionTombstone $tombstone,
    ): bool {
        $targetTier = $series->retention_tier === 'raw' ? 'hourly' : 'daily';
        $coverage = MetricRollupCoverage::query()
            ->where('source_series_id', $series->id)
            ->where('target_tier', $targetTier)
            ->first();
        if ($coverage === null
            || $coverage->covered_from->greaterThan($tombstone->period_start)
            || $coverage->covered_until->lessThan($tombstone->period_end)
            || $coverage->completed_at->greaterThan($tombstone->deleted_at)) {
            return false;
        }

        $target = $coverage->targetSeries;

        return $target instanceof MetricSeries && $this->matchingTarget($series, $target, $targetTier);
    }

    /**
     * @param  list<MonitoringRetentionTombstone>  $rawTombstones
     * @param  list<MonitoringRetentionTombstone>  $hourlyTombstones
     */
    private function connectedRollupChains(
        array $rawTombstones,
        array $hourlyTombstones,
        CarbonImmutable $started,
        CarbonImmutable $completed,
    ): int {
        $count = 0;
        foreach ($rawTombstones as $tombstone) {
            $hourly = MetricRollupCoverage::query()
                ->where('source_series_id', $tombstone->series_id)
                ->where('target_tier', 'hourly')
                ->whereBetween('completed_at', [$started, $completed])
                ->first();
            if ($hourly === null) {
                continue;
            }

            $hourlyDeleted = collect($hourlyTombstones)->contains(
                fn (MonitoringRetentionTombstone $candidate): bool => (int) $candidate->series_id === (int) $hourly->target_series_id,
            );
            if (! $hourlyDeleted) {
                continue;
            }

            $daily = MetricRollupCoverage::query()
                ->where('source_series_id', $hourly->target_series_id)
                ->where('target_tier', 'daily')
                ->whereBetween('completed_at', [$started, $completed])
                ->first();
            if ($daily !== null) {
                $count++;
            }
        }

        return $count;
    }

    private function matchingTarget(MetricSeries $source, MetricSeries $target, string $targetTier): bool
    {
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

    private function businessReferenceGaps(): int
    {
        return DB::table('monitoring_metric_series as series')
            ->leftJoin('sites', 'sites.id', '=', 'series.site_id')
            ->leftJoin('devices', 'devices.id', '=', 'series.device_id')
            ->leftJoin('monitors', 'monitors.id', '=', 'series.monitor_id')
            ->where(function ($query): void {
                $query->whereNull('sites.id')
                    ->orWhereNull('devices.id')
                    ->orWhere(function ($monitor): void {
                        $monitor->whereNotNull('series.monitor_id')->whereNull('monitors.id');
                    });
            })
            ->count();
    }

    private function pointerGaps(): int
    {
        return MetricSeries::query()
            ->whereRaw(
                '(first_point_at IS NULL AND last_point_at IS NOT NULL)'
                .' OR (first_point_at IS NOT NULL AND last_point_at IS NULL)'
                .' OR first_point_at > last_point_at',
            )
            ->count();
    }

    private function externalReferenceGaps(): int
    {
        $gaps = 0;
        MetricSeries::query()
            ->whereNotNull('last_point_at')
            ->orderBy('id')
            ->chunkById(100, function ($batch) use (&$gaps): void {
                foreach ($batch as $series) {
                    try {
                        $exists = $this->retentionEnforcer->hasExactSeriesBoundaryPoints($series);
                    } catch (Throwable) {
                        $exists = false;
                    }
                    if (! $exists) {
                        $gaps++;
                    }
                }
            });

        return $gaps;
    }

    /** @return array{point_count: int, payload_sha256: string} */
    private function seriesRangeCommitment(
        MetricSeries $series,
        CarbonImmutable $from,
        CarbonImmutable $until,
    ): array {
        $cursor = $from;
        $count = 0;
        $hash = hash_init('sha256');
        while ($cursor->lessThan($until)) {
            $windowEnd = $series->retention_tier === 'raw'
                ? $cursor->addDay()
                : $cursor->addDays(31);
            if ($windowEnd->greaterThan($until)) {
                $windowEnd = $until;
            }
            foreach ($this->timeSeries->range(
                (string) $series->external_key,
                (string) $series->retention_tier,
                $cursor,
                $windowEnd,
            ) as $point) {
                if (! $point instanceof TimeSeriesPoint
                    || $point->externalKey !== $series->external_key
                    || $point->seriesId !== (int) $series->id
                    || $point->tier !== $series->retention_tier
                    || $point->observedAt->lessThan($cursor)
                    || ! $point->observedAt->lessThan($windowEnd)) {
                    throw new \RuntimeException('Legal-hold payload evidence is invalid.');
                }
                $payload = [
                    'observed_at' => $point->observedAt->utc()->format('Y-m-d\TH:i:s.u\Z'),
                    'value' => $point->value,
                    'statistics' => $this->sorted($point->statistics),
                    'idempotency_key' => $point->idempotencyKey,
                ];
                hash_update($hash, json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n");
                $count++;
            }
            $cursor = $windowEnd;
        }

        return ['point_count' => $count, 'payload_sha256' => hash_final($hash)];
    }

    /** @param array<string, mixed> $value @return array<string, mixed> */
    private function sorted(array $value): array
    {
        ksort($value, SORT_STRING);

        return $value;
    }
}
