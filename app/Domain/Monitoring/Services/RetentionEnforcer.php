<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Exceptions\TimeSeriesUnavailable;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\MetricCurrentSummary;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Models\MonitoringRetentionPolicy;
use App\Domain\Monitoring\Models\MonitoringRetentionTombstone;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\LegalHold;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RetentionEnforcer
{
    public function __construct(
        private readonly TimeSeriesStore $timeSeries,
        private readonly SnapshotStore $snapshots,
    ) {}

    /** @return array{metric_payloads_deleted: int, snapshot_payloads_deleted: int, held_series: int, held_snapshots: int} */
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
                    $matches = $this->matchingPolicies($series, $policies);
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
                    if ($series->first_point_at->greaterThan($cutoff)) {
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

                    $this->timeSeries->deleteRange(
                        $series->external_key,
                        $series->retention_tier,
                        $periodStart,
                        $cutoff,
                    );

                    DB::transaction(function () use (
                        $series,
                        $policy,
                        $periodStart,
                        $cutoff,
                        $actorId,
                        $jobReference,
                        $now,
                    ): void {
                        $locked = MetricSeries::query()->lockForUpdate()->findOrFail($series->id);
                        $summary = MetricCurrentSummary::query()
                            ->where('series_id', $series->id)
                            ->lockForUpdate()
                            ->first();
                        if ($summary?->observed_at !== null && $summary->observed_at->lessThanOrEqualTo($cutoff)) {
                            $summary->delete();
                        }
                        $locked->first_point_at = $locked->last_point_at !== null
                            && $locked->last_point_at->greaterThan($cutoff)
                                ? $cutoff->addMicrosecond()
                                : null;
                        if ($locked->last_point_at !== null && $locked->last_point_at->lessThanOrEqualTo($cutoff)) {
                            $locked->last_point_at = null;
                        }
                        $locked->save();

                        MonitoringRetentionTombstone::query()->create([
                            'tombstone_uuid' => (string) Str::uuid(),
                            'series_id' => $series->id,
                            'site_id' => $series->site_id,
                            'device_id' => $series->device_id,
                            'monitor_id' => $series->monitor_id,
                            'data_class' => $series->data_class,
                            'retention_tier' => $series->retention_tier,
                            'period_start' => $periodStart,
                            'period_end' => $cutoff,
                            'policy_id' => $policy->id,
                            'deleted_by_user_id' => $actorId,
                            'job_reference' => $jobReference,
                            'deleted_at' => $now,
                        ]);
                    }, 3);
                    $result['metric_payloads_deleted']++;
                }
            });

        ConfigurationSnapshot::query()
            ->where('storage_state', 'available')
            ->orderBy('id')
            ->chunkById(100, function ($batch) use ($policies, $now, $actorId, $jobReference, &$result): void {
                foreach ($batch as $snapshot) {
                    $matches = $policies->filter(fn (MonitoringRetentionPolicy $policy): bool => $this->policyMatchesSnapshot($policy, $snapshot));
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

    /** @param Collection<int, MonitoringRetentionPolicy> $policies
     * @return Collection<int, MonitoringRetentionPolicy>
     */
    private function matchingPolicies(MetricSeries $series, Collection $policies): Collection
    {
        return $policies->filter(fn (MonitoringRetentionPolicy $policy): bool => match ($policy->scope_kind) {
            'application' => true,
            'site' => (int) $policy->site_id === (int) $series->site_id,
            'device' => (int) $policy->device_id === (int) $series->device_id,
            'data_class' => $policy->data_class === $series->data_class,
            'privacy' => $policy->privacy_class === $series->privacy_class,
            default => false,
        })->values();
    }

    private function policyMatchesSnapshot(
        MonitoringRetentionPolicy $policy,
        ConfigurationSnapshot $snapshot,
    ): bool {
        return match ($policy->scope_kind) {
            'application' => true,
            'site' => (int) $policy->site_id === (int) $snapshot->site_id,
            'device' => (int) $policy->device_id === (int) $snapshot->device_id,
            'data_class' => $policy->data_class === 'configuration',
            default => false,
        };
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
