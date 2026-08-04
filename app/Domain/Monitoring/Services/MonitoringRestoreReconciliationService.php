<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\RestoreDependencyProbe;
use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialLeaseGrant;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Services\CredentialReferenceRules;
use App\Services\Integration\IntegrationAdapterRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class MonitoringRestoreReconciliationService
{
    public function __construct(
        private readonly SnapshotStore $snapshots,
        private readonly TimeSeriesStore $timeSeries,
        private readonly IntegrationAdapterRegistry $providers,
        private readonly RestoreDependencyProbe $dependencies,
        private readonly CredentialReferenceRules $credentialRules,
    ) {}

    /** @return array<string, int|string> */
    public function report(): array
    {
        $dependencies = $this->dependencyHealth();

        return [
            'outbox_gap' => $this->outboxGap(),
            'inbox_checkpoint_gap' => $this->inboxCheckpointGap(),
            'orphan_series' => $this->orphanSeries(),
            'timeseries_pointer_gap' => $this->timeSeriesPointerGap($dependencies['timeseries']),
            'snapshot_hash_mismatch' => $this->snapshotHashMismatch(),
            'topology_pointer_gap' => $this->topologyPointerGap(),
            'collector_sequence_regression' => $this->collectorSequenceRegression(),
            'stale_unpublished_delivery' => $this->staleUnpublishedDelivery(),
            'published_projection_gap' => $this->publishedProjectionGap(),
            'provider_cursor_scope_gap' => $this->providerCursorScopeGap(),
            'provider_cursor_stall' => $this->providerCursorStall(),
            'credential_reference_recovery_gap' => $this->credentialReferenceRecoveryGap(),
            'credential_lease_recovery_gap' => $this->credentialLeaseRecoveryGap(),
            'redis_unavailable' => $dependencies['redis'] ? 0 : 1,
            'timeseries_unavailable' => $dependencies['timeseries'] ? 0 : 1,
            'snapshot_store_unavailable' => $dependencies['snapshots'] ? 0 : 1,
            'secret_manager_unavailable' => $dependencies['secret_manager'] ? 0 : 1,
            'checked_at' => now()->utc()->toIso8601String(),
        ];
    }

    /** @return array{redis: bool, timeseries: bool, snapshots: bool, secret_manager: bool} */
    private function dependencyHealth(): array
    {
        try {
            $health = $this->dependencies->health();
        } catch (Throwable) {
            return ['redis' => false, 'timeseries' => false, 'snapshots' => false, 'secret_manager' => false];
        }

        if (array_keys($health) !== ['redis', 'timeseries', 'snapshots', 'secret_manager']
            || ! is_bool($health['redis'])
            || ! is_bool($health['timeseries'])
            || ! is_bool($health['snapshots'])
            || ! is_bool($health['secret_manager'])) {
            return ['redis' => false, 'timeseries' => false, 'snapshots' => false, 'secret_manager' => false];
        }

        return $health;
    }

    private function outboxGap(): int
    {
        return DB::table('monitoring_outbox')
            ->select('source')
            ->selectRaw('MIN(sequence) AS minimum_sequence')
            ->selectRaw('MAX(sequence) AS maximum_sequence')
            ->selectRaw('COUNT(DISTINCT sequence) AS sequence_count')
            ->groupBy('source')
            ->get()
            ->sum(function (object $source): int {
                $minimum = (int) $source->minimum_sequence;
                $maximum = (int) $source->maximum_sequence;
                $count = (int) $source->sequence_count;

                return max(0, $minimum - 1) + max(0, ($maximum - $minimum + 1) - $count);
            });
    }

    private function inboxCheckpointGap(): int
    {
        $processed = DB::table('monitoring_inbox')
            ->whereNotNull('processed_at')
            ->select(['consumer', 'source'])
            ->selectRaw('MAX(sequence) AS maximum_sequence')
            ->groupBy(['consumer', 'source']);

        return DB::table('monitoring_consumer_checkpoints as checkpoints')
            ->leftJoinSub($processed, 'processed', function ($join): void {
                $join->on('processed.consumer', '=', 'checkpoints.consumer')
                    ->on('processed.source', '=', 'checkpoints.source');
            })
            ->where(function ($query): void {
                $query->whereNotNull('checkpoints.gap_from')
                    ->orWhereNotNull('checkpoints.gap_to')
                    ->orWhereRaw('checkpoints.last_sequence > COALESCE(processed.maximum_sequence, 0)');
            })
            ->count();
    }

    private function orphanSeries(): int
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

    private function timeSeriesPointerGap(bool $timeSeriesHealthy): int
    {
        if (! $timeSeriesHealthy) {
            return 0;
        }

        $gaps = 0;
        DB::table('monitoring_metric_series')
            ->whereNotNull('first_point_at')
            ->whereNotNull('last_point_at')
            ->orderBy('id')
            ->chunkById(100, function ($series) use (&$gaps): void {
                foreach ($series as $metric) {
                    try {
                        $from = CarbonImmutable::parse($metric->first_point_at)->utc();
                        $to = CarbonImmutable::parse($metric->last_point_at)->utc()->addMicrosecond();
                        if ($to->lessThanOrEqualTo($from)
                            || ! $this->timeSeries->exists(
                                (string) $metric->external_key,
                                (string) $metric->retention_tier,
                                $from,
                                $to,
                            )) {
                            $gaps++;
                        }
                    } catch (Throwable) {
                        $gaps++;
                    }
                }
            });

        return $gaps;
    }

    private function snapshotHashMismatch(): int
    {
        $mismatches = 0;

        ConfigurationSnapshot::query()
            ->where('storage_state', 'available')
            ->whereNull('payload_deleted_at')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$mismatches): void {
                foreach ($rows as $snapshot) {
                    try {
                        if (! $this->snapshots->exists($snapshot->storage_path)) {
                            $mismatches++;

                            continue;
                        }

                        $payload = $this->snapshots->read($snapshot->storage_path);
                        if (! hash_equals((string) $snapshot->content_hash, hash('sha256', $payload))
                            || strlen($payload) !== (int) $snapshot->content_size) {
                            $mismatches++;
                        }
                    } catch (Throwable) {
                        $mismatches++;
                    }
                }
            });

        return $mismatches;
    }

    private function topologyPointerGap(): int
    {
        $mismatched = DB::table('monitoring_topology_snapshots as snapshots')
            ->leftJoin('monitoring_topology_nodes as nodes', 'nodes.topology_snapshot_id', '=', 'snapshots.id')
            ->leftJoin('monitoring_topology_edges as edges', 'edges.topology_snapshot_id', '=', 'snapshots.id')
            ->where('snapshots.status', 'completed')
            ->groupBy([
                'snapshots.id',
                'snapshots.node_count',
                'snapshots.edge_count',
                'snapshots.completed_at',
            ])
            ->havingRaw('snapshots.completed_at IS NULL OR COUNT(DISTINCT nodes.id) <> snapshots.node_count OR COUNT(DISTINCT edges.id) <> snapshots.edge_count')
            ->pluck('snapshots.id');
        $latest = DB::table('monitoring_topology_snapshots')
            ->select(['site_id', 'source'])
            ->selectRaw('MAX(id) AS latest_id')
            ->groupBy(['site_id', 'source']);
        $incompleteLatest = DB::table('monitoring_topology_snapshots as snapshots')
            ->joinSub($latest, 'latest', 'latest.latest_id', '=', 'snapshots.id')
            ->where(function ($query): void {
                $query->where('snapshots.status', '!=', 'completed')
                    ->orWhereNull('snapshots.completed_at');
            })
            ->pluck('snapshots.id');

        return $mismatched->merge($incompleteLatest)->unique()->count();
    }

    private function collectorSequenceRegression(): int
    {
        return DB::table('monitoring_collectors as collectors')
            ->leftJoin('monitoring_collector_checkpoints as checkpoints', 'checkpoints.collector_id', '=', 'collectors.id')
            ->where(function ($query): void {
                $query->whereRaw('COALESCE(collectors.acknowledged_source_sequence, 0) > COALESCE(collectors.highest_seen_source_sequence, 0)')
                    ->orWhereRaw('COALESCE(checkpoints.acknowledged_source_sequence, 0) > COALESCE(checkpoints.highest_seen_source_sequence, 0)')
                    ->orWhereRaw('(checkpoints.gap_from IS NULL AND checkpoints.gap_to IS NOT NULL) OR (checkpoints.gap_from IS NOT NULL AND checkpoints.gap_to IS NULL)')
                    ->orWhereRaw('checkpoints.gap_from IS NOT NULL AND checkpoints.gap_from > checkpoints.gap_to');
            })
            ->count();
    }

    private function staleUnpublishedDelivery(): int
    {
        $cutoff = now()->subSeconds((int) config('monitoring.restore.stale_delivery_seconds', 900));

        return DB::table('monitoring_outbox')
            ->whereNull('published_at')
            ->where('available_at', '<=', $cutoff)
            ->count();
    }

    private function publishedProjectionGap(): int
    {
        $cutoff = now()->subSeconds((int) config('monitoring.restore.stale_delivery_seconds', 900));

        return DB::table('monitoring_outbox as outbox')
            ->whereNotNull('outbox.published_at')
            ->where('outbox.published_at', '<=', $cutoff)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('monitoring_inbox as inbox')
                    ->whereColumn('inbox.message_id', 'outbox.message_id')
                    ->whereColumn('inbox.source', 'outbox.source')
                    ->whereNotNull('inbox.processed_at');
            })
            ->count();
    }

    private function providerCursorScopeGap(): int
    {
        $scopes = DB::table('monitoring_provider_cursors as cursors')
            ->leftJoin('integration_site_configs as configs', function (JoinClause $join): void {
                $join->on('configs.site_id', '=', 'cursors.site_id')
                    ->on('configs.provider', '=', 'cursors.provider')
                    ->where('configs.is_active', true);
            })
            ->select(['cursors.provider', 'cursors.capability'])
            ->selectRaw('COUNT(cursors.id) AS cursor_count')
            ->selectRaw('COUNT(configs.id) AS active_scope_count')
            ->groupBy(['cursors.provider', 'cursors.capability'])
            ->get();

        return $scopes->sum(function (object $scope): int {
            $cursorCount = (int) $scope->cursor_count;

            if (! $this->providers->hasCapability($scope->provider, $scope->capability)) {
                return $cursorCount;
            }

            return max(0, $cursorCount - (int) $scope->active_scope_count);
        });
    }

    private function providerCursorStall(): int
    {
        $cutoff = now()->subSeconds((int) config('monitoring.restore.provider_cursor_stall_seconds', 900));

        return DB::table('monitoring_provider_cursors')
            ->whereNotNull('last_started_at')
            ->where('last_started_at', '<=', $cutoff)
            ->where(function ($query): void {
                $query->whereNull('last_completed_at')
                    ->orWhereColumn('last_completed_at', '<', 'last_started_at');
            })
            ->count();
    }

    private function credentialReferenceRecoveryGap(): int
    {
        $gaps = 0;

        CredentialReference::query()
            ->with('site:id')
            ->orderBy('id')
            ->chunkById(100, function ($references) use (&$gaps): void {
                foreach ($references as $reference) {
                    try {
                        $status = CredentialReferenceStatus::tryFrom((string) $reference->getRawOriginal('status'));
                        $rotation = CredentialRotationStatus::tryFrom((string) $reference->getRawOriginal('rotation_status'));
                        $test = CredentialTestStatus::tryFrom((string) $reference->getRawOriginal('test_status'));
                        $externalReference = (string) $reference->secret_manager_reference;
                        $capabilities = $this->credentialRules->capabilities($reference->capabilities ?? []);
                        $valid = $reference->site !== null
                            && Str::isUuid((string) $reference->reference_uuid)
                            && (int) $reference->version >= 1
                            && $status !== null
                            && $rotation !== null
                            && $test !== null
                            && $this->credentialRules->referenceKey((string) $reference->reference_key) === $reference->reference_key
                            && $this->credentialRules->provider((string) $reference->provider) === $reference->provider
                            && $this->credentialRules->purpose((string) $reference->purpose) === $reference->purpose
                            && $this->credentialRules->externalReference($externalReference) === $externalReference
                            && hash_equals(
                                (string) $reference->secret_manager_reference_hash,
                                $this->credentialRules->fingerprint($externalReference),
                            )
                            && $capabilities === array_values($reference->capabilities ?? []);

                        if ($status === CredentialReferenceStatus::Active) {
                            $valid = $valid
                                && $rotation === CredentialRotationStatus::Current
                                && $test === CredentialTestStatus::Passed;
                        }
                        if ($status === CredentialReferenceStatus::Revoked) {
                            $valid = $valid && $reference->revoked_at !== null;
                        }
                    } catch (Throwable) {
                        $valid = false;
                    }

                    if (! $valid) {
                        $gaps++;
                    }
                }
            });

        return $gaps;
    }

    private function credentialLeaseRecoveryGap(): int
    {
        $gaps = 0;
        $active = [CredentialLeaseGrant::STATUS_ISSUED, CredentialLeaseGrant::STATUS_REVOKE_PENDING];
        $terminal = [
            CredentialLeaseGrant::STATUS_RELEASED,
            CredentialLeaseGrant::STATUS_CONTAINED,
            CredentialLeaseGrant::STATUS_EXPIRED,
        ];

        CredentialLeaseGrant::query()
            ->with('reference:id,site_id,status,version')
            ->orderBy('id')
            ->chunkById(100, function ($grants) use (&$gaps, $active, $terminal): void {
                foreach ($grants as $grant) {
                    try {
                        $status = (string) $grant->getRawOriginal('status');
                        $reference = $grant->reference;
                        $capabilities = $this->credentialRules->capabilities($grant->capabilities ?? []);
                        $valid = $reference !== null
                            && Str::isUuid((string) $grant->grant_uuid)
                            && in_array($status, [...$active, ...$terminal], true)
                            && (int) $grant->site_id === (int) $reference?->site_id
                            && (int) $grant->reference_version <= (int) $reference?->version
                            && $capabilities === array_values($grant->capabilities ?? []);

                        if (in_array($status, $active, true)) {
                            $leaseId = (string) $grant->lease_id;
                            $valid = $valid
                                && $leaseId !== ''
                                && $grant->ended_at === null
                                && $grant->expires_at instanceof CarbonImmutable
                                && $grant->expires_at->isFuture()
                                && (int) $grant->reference_version === (int) $reference?->version
                                && (string) $reference?->getRawOriginal('status') === CredentialReferenceStatus::Active->value
                                && hash_equals(
                                    (string) $grant->lease_fingerprint,
                                    $this->credentialRules->fingerprint($leaseId),
                                );
                        } else {
                            $valid = $valid
                                && $grant->getRawOriginal('lease_id') === null
                                && $grant->ended_at !== null;
                        }
                    } catch (Throwable) {
                        $valid = false;
                    }

                    if (! $valid) {
                        $gaps++;
                    }
                }
            });

        return $gaps;
    }
}
