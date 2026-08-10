<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\CollectorCheckpoint;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitorObservation;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class CollectorHealthService
{
    public function evaluate(MonitoringCollector $collector, ?CarbonImmutable $at = null): MonitoringCollector
    {
        $at ??= CarbonImmutable::now('UTC');
        $staleAfter = max(60, min(3600, (int) config('monitoring.collector.heartbeat_stale_seconds', 180)));

        return DB::transaction(function () use ($collector, $at, $staleAfter): MonitoringCollector {
            $locked = MonitoringCollector::query()->with('checkpoint')->whereKey($collector->id)->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at !== null || $locked->status === 'revoked') {
                return $locked;
            }
            $last = $locked->last_heartbeat_at ?? $locked->last_seen_at;
            if ($last === null || CarbonImmutable::instance($last)->gt($at->subSeconds($staleAfter))) {
                return $locked;
            }
            if ($locked->status !== 'unavailable') {
                $locked->forceFill(['status' => 'unavailable'])->save();
                $affected = $this->lockEnabledRoster($locked);
                $roster = $this->rosterEvidence($affected);
                if ($roster['affected_monitor_ids'] !== []) {
                    Monitor::query()->whereIn('id', $roster['affected_monitor_ids'])->update([
                        'effective_state' => MonitorState::Stale->value,
                        'suppression_reason' => 'collector_path',
                        'suppressed_at' => $at,
                    ]);
                }
                $this->event($locked, 'offline', 'high', [
                    ...$roster,
                    'backlog_items' => (int) $locked->backlog_items,
                    'gap_count' => (int) $locked->gap_count,
                ], $at);
            }

            return $locked->fresh(['checkpoint']);
        }, 3);
    }

    /** @param array<string, mixed> $status */
    public function recordHeartbeat(
        MonitoringCollector $collector,
        array $status,
        ?CarbonImmutable $receivedAt = null,
    ): MonitoringCollector {
        $receivedAt ??= CarbonImmutable::now('UTC');
        $heartbeat = $this->validatedHeartbeat($status);

        return DB::transaction(function () use ($collector, $heartbeat, $receivedAt): MonitoringCollector {
            $locked = MonitoringCollector::query()->with('checkpoint')->whereKey($collector->id)->lockForUpdate()->firstOrFail();
            if ($locked->revoked_at !== null || $locked->status === 'revoked') {
                throw new DomainException('Collector is unavailable.');
            }
            $wasUnavailable = $locked->status === 'unavailable';
            $checkpoint = $locked->checkpoint ?? CollectorCheckpoint::query()->create(['collector_id' => $locked->id]);
            $hasGap = $checkpoint->gap_from !== null
                || $checkpoint->gap_to !== null
                || (int) $locked->gap_count > 0;
            $checkpointIsContiguous = (int) $checkpoint->acknowledged_source_sequence
                    === (int) $checkpoint->highest_seen_source_sequence
                && (int) $locked->acknowledged_source_sequence
                    === (int) $locked->highest_seen_source_sequence
                && (int) $checkpoint->acknowledged_source_sequence
                    === (int) $locked->acknowledged_source_sequence
                && (int) $checkpoint->highest_seen_source_sequence
                    === (int) $locked->highest_seen_source_sequence;
            $collectorMirrorsCheckpoint = $heartbeat['acknowledged_source_sequence']
                    === (int) $checkpoint->acknowledged_source_sequence
                && $heartbeat['highest_seen_source_sequence']
                    === (int) $checkpoint->highest_seen_source_sequence;
            $pathIsContiguous = ! $hasGap
                && $checkpointIsContiguous
                && $collectorMirrorsCheckpoint;
            $transportRecoveryReady = $pathIsContiguous
                && $heartbeat['state'] === 'writable'
                && $heartbeat['spool_items'] === 0
                && $heartbeat['spool_bytes'] === 0
                && $heartbeat['corrupted_frames'] === 0;
            $outage = $wasUnavailable ? $this->activeOutage($locked) : null;
            $recoveryReady = $transportRecoveryReady
                && (! $wasUnavailable
                    || ($outage !== null && $this->fullAffectedRosterObserved($locked, $outage)));
            $nextStatus = ! $pathIsContiguous || ($wasUnavailable && ! $recoveryReady)
                ? 'unavailable'
                : ($heartbeat['state'] === 'buffer_full' ? 'degraded' : 'online');
            $locked->forceFill([
                'status' => $nextStatus,
                'last_seen_at' => $receivedAt,
                'last_heartbeat_at' => $receivedAt,
                'backlog_items' => $heartbeat['spool_items'],
                'spool_bytes' => $heartbeat['spool_bytes'],
                'corrupted_frames' => $heartbeat['corrupted_frames'],
                'runtime_state' => $heartbeat['state'],
                'runtime_status' => $heartbeat['runtime'],
                'backlog_oldest_at' => $heartbeat['oldest_spool_item_at'],
                'last_clock_drift_seconds' => $receivedAt->diffInSeconds($heartbeat['reported_at'], false) * -1,
                'last_recovered_at' => $wasUnavailable && $recoveryReady ? $receivedAt : $locked->last_recovered_at,
            ])->save();
            if (! $wasUnavailable && ! $pathIsContiguous) {
                $affected = $this->lockEnabledRoster($locked);
                $roster = $this->rosterEvidence($affected);
                if ($roster['affected_monitor_ids'] !== []) {
                    Monitor::query()->whereIn('id', $roster['affected_monitor_ids'])->update([
                        'effective_state' => MonitorState::Stale->value,
                        'suppression_reason' => 'collector_path',
                        'suppressed_at' => $receivedAt,
                    ]);
                }
                $this->event($locked, 'offline', 'high', [
                    ...$roster,
                    'backlog_items' => $heartbeat['spool_items'],
                    'gap_count' => (int) $locked->gap_count,
                    'sequence_path_incomplete' => 1,
                    'collector_checkpoint_mismatch' => $collectorMirrorsCheckpoint ? 0 : 1,
                ], $receivedAt);
            }
            if ($wasUnavailable && ! $recoveryReady && $outage !== null && $outage['monitor_ids'] !== []) {
                Monitor::query()->whereIn('id', $outage['monitor_ids'])->update([
                    'effective_state' => MonitorState::Stale->value,
                    'suppression_reason' => 'collector_path',
                    'suppressed_at' => $outage['started_at'],
                ]);
            }
            if ($wasUnavailable && $recoveryReady && $outage !== null) {
                if ($outage['monitor_ids'] !== []) {
                    DB::table('monitors')->whereIn('id', $outage['monitor_ids'])->update([
                        'effective_state' => DB::raw('current_state'),
                        'suppression_reason' => null,
                        'suppressed_at' => null,
                    ]);
                }
                $backlogAge = $heartbeat['oldest_spool_item_at'] === null
                    ? 0
                    : max(0, $heartbeat['oldest_spool_item_at']->diffInSeconds($receivedAt));
                $this->event($locked, 'online', 'info', [
                    'affected_monitor_ids' => $outage['monitor_ids'],
                    'affected_monitor_roster_sha256' => $outage['roster_sha256'],
                    'affected_device_count' => $outage['device_count'],
                    'affected_monitor_count' => $outage['monitor_count'],
                    'backlog_items' => $heartbeat['spool_items'],
                    'backlog_age_seconds' => $backlogAge,
                    'gap_count' => 0,
                    'clock_drift_seconds' => $locked->last_clock_drift_seconds,
                ], $receivedAt);
            }

            return $locked->fresh(['checkpoint']);
        }, 3);
    }

    /**
     * @return array{
     *     started_at: CarbonImmutable,
     *     monitor_ids: list<int>,
     *     roster_sha256: string,
     *     monitor_count: int,
     *     device_count: int
     * }|null
     */
    private function activeOutage(MonitoringCollector $collector): ?array
    {
        if ($collector->collector_device_id === null) {
            return null;
        }

        $event = DeviceEvent::query()
            ->where('device_id', $collector->collector_device_id)
            ->whereIn('event_type', ['offline', 'online'])
            ->where('source', 'oblivion_monitoring')
            ->where('payload->monitor_correlation_key', $this->correlationKey($collector))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first(['event_type', 'occurred_at', 'payload']);

        if ($event?->event_type !== 'offline' || $event->occurred_at === null || ! is_array($event->payload)) {
            return null;
        }

        $payload = $event->payload;
        $ids = $payload['affected_monitor_ids'] ?? null;
        $fingerprint = $payload['affected_monitor_roster_sha256'] ?? null;
        $monitorCount = $payload['affected_monitor_count'] ?? null;
        $deviceCount = $payload['affected_device_count'] ?? null;

        if (! is_array($ids)
            || ! array_is_list($ids)
            || ! is_string($fingerprint)
            || preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1
            || ! is_int($monitorCount)
            || ! is_int($deviceCount)
            || $monitorCount < 0
            || $deviceCount < 0
            || ($payload['root_cause'] ?? null) !== 'collector_path'
            || ($payload['collector_id'] ?? null) !== (int) $collector->id
            || ($payload['collector_uuid'] ?? null) !== $collector->collector_uuid
            || ($payload['site_id'] ?? null) !== (int) $collector->site_id
            || ($payload['monitor_correlation_key'] ?? null) !== $this->correlationKey($collector)) {
            return null;
        }

        foreach ($ids as $id) {
            if (! is_int($id) || $id <= 0) {
                return null;
            }
        }

        $sorted = $ids;
        sort($sorted, SORT_NUMERIC);

        if ($ids !== $sorted
            || count(array_unique($ids, SORT_REGULAR)) !== count($ids)
            || $monitorCount !== count($ids)
            || ! hash_equals($this->rosterFingerprint($ids), $fingerprint)) {
            return null;
        }

        $current = $collector->monitors()
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'collector_id', 'device_id', 'is_enabled']);
        $pinned = $ids === []
            ? new Collection
            : Monitor::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'collector_id', 'device_id', 'is_enabled']);
        $pinnedIds = $pinned->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $enabledIds = $current
            ->filter(fn (Monitor $monitor): bool => (bool) $monitor->is_enabled)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($pinnedIds !== $ids
            || $enabledIds !== $ids
            || $pinned->contains(fn (Monitor $monitor): bool => (int) $monitor->collector_id !== (int) $collector->id)
            || $pinned->contains(fn (Monitor $monitor): bool => ! (bool) $monitor->is_enabled)
            || $pinned->pluck('device_id')->unique()->count() !== $deviceCount) {
            return null;
        }

        return [
            'started_at' => CarbonImmutable::instance($event->occurred_at)->utc(),
            'monitor_ids' => $ids,
            'roster_sha256' => $fingerprint,
            'monitor_count' => $monitorCount,
            'device_count' => $deviceCount,
        ];
    }

    /** @param array{started_at: CarbonImmutable, monitor_ids: list<int>} $outage */
    private function fullAffectedRosterObserved(MonitoringCollector $collector, array $outage): bool
    {
        if ($outage['monitor_ids'] === []) {
            return true;
        }

        $boundary = $outage['started_at']->format('Y-m-d H:i:s.u');

        return ! Monitor::query()
            ->whereIn('id', $outage['monitor_ids'])
            ->whereNotExists(function ($query) use ($collector, $boundary): void {
                $query->selectRaw('1')
                    ->from((new MonitorObservation)->getTable())
                    ->whereColumn('monitor_observations.monitor_id', 'monitors.id')
                    ->where('monitor_observations.collector_id', $collector->id)
                    ->where('monitor_observations.observed_at', '>', $boundary)
                    ->where('monitor_observations.ingested_at', '>', $boundary);
            })
            ->exists();
    }

    /** @return Collection<int, Monitor> */
    private function lockEnabledRoster(MonitoringCollector $collector): Collection
    {
        return $collector->monitors()
            ->where('is_enabled', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'device_id']);
    }

    /**
     * @param  Collection<int, Monitor>  $monitors
     * @return array{affected_monitor_ids: list<int>, affected_monitor_roster_sha256: string, affected_device_count: int, affected_monitor_count: int}
     */
    private function rosterEvidence(Collection $monitors): array
    {
        $ids = $monitors->pluck('id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();

        return [
            'affected_monitor_ids' => $ids,
            'affected_monitor_roster_sha256' => $this->rosterFingerprint($ids),
            'affected_device_count' => $monitors->pluck('device_id')->unique()->count(),
            'affected_monitor_count' => count($ids),
        ];
    }

    /** @param list<int> $ids */
    private function rosterFingerprint(array $ids): string
    {
        return hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $status @return array{reported_at: CarbonImmutable, state: string, spool_items: int, spool_bytes: int, corrupted_frames: int, acknowledged_source_sequence: int, highest_seen_source_sequence: int, oldest_spool_item_at: ?CarbonImmutable, runtime: array<string, int|string|bool|null>} */
    private function validatedHeartbeat(array $status): array
    {
        $reportedAt = $status['reported_at'] ?? null;
        $state = $status['state'] ?? null;
        $items = $status['spool_items'] ?? null;
        $bytes = $status['spool_bytes'] ?? null;
        $corrupt = $status['corrupted_frames'] ?? null;
        $acknowledged = $status['acknowledged_source_sequence'] ?? null;
        $highestSeen = $status['highest_seen_source_sequence'] ?? null;
        $runtime = $status['runtime'] ?? null;
        if (! is_string($reportedAt) || ! in_array($state, ['writable', 'buffer_full'], true)
            || ! is_int($items) || $items < 0 || $items > 100_000
            || ! is_int($bytes) || $bytes < 0 || $bytes > 67_108_864
            || ! is_int($corrupt) || $corrupt < 0 || $corrupt > 100_000
            || ! is_int($acknowledged) || $acknowledged < 0
            || ! is_int($highestSeen) || $highestSeen < $acknowledged
            || ! is_array($runtime) || array_is_list($runtime) || count($runtime) > 16
            || array_any($runtime, fn (mixed $value, mixed $key): bool => ! is_string($key)
                || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $key) !== 1
                || (! is_scalar($value) && $value !== null)
                || (is_string($value) && strlen($value) > 256))) {
            throw new DomainException('Collector heartbeat is invalid.');
        }
        try {
            $reported = CarbonImmutable::parse($reportedAt)->utc();
            $oldest = is_string($status['oldest_spool_item_at'] ?? null)
                ? CarbonImmutable::parse($status['oldest_spool_item_at'])->utc()
                : null;
        } catch (\Throwable) {
            throw new DomainException('Collector heartbeat timestamp is invalid.');
        }
        if (($items === 0 && $oldest !== null) || ($items > 0 && $oldest === null)) {
            throw new DomainException('Collector heartbeat backlog evidence is invalid.');
        }
        if ($items === 0 && $acknowledged !== $highestSeen) {
            throw new DomainException('Collector heartbeat checkpoint evidence is invalid.');
        }
        if ($items > 0 && $highestSeen <= $acknowledged) {
            throw new DomainException('Collector heartbeat checkpoint evidence is invalid.');
        }

        return [
            'reported_at' => $reported,
            'state' => $state,
            'spool_items' => $items,
            'spool_bytes' => $bytes,
            'corrupted_frames' => $corrupt,
            'acknowledged_source_sequence' => $acknowledged,
            'highest_seen_source_sequence' => $highestSeen,
            'oldest_spool_item_at' => $oldest,
            'runtime' => $runtime,
        ];
    }

    /** @param array<string, mixed> $evidence */
    private function event(
        MonitoringCollector $collector,
        string $eventType,
        string $severity,
        array $evidence,
        CarbonImmutable $at,
    ): void {
        if ($collector->collector_device_id === null) {
            return;
        }
        DeviceEvent::query()->create([
            'device_id' => $collector->collector_device_id,
            'event_type' => $eventType,
            'severity' => $severity,
            'source' => 'oblivion_monitoring',
            'occurred_at' => $at,
            'payload' => [
                'root_cause' => 'collector_path',
                'collector_id' => $collector->id,
                'collector_uuid' => $collector->collector_uuid,
                'site_id' => (int) $collector->site_id,
                'monitor_correlation_key' => $this->correlationKey($collector),
                ...$evidence,
            ],
        ]);
    }

    private function correlationKey(MonitoringCollector $collector): string
    {
        return hash(
            'sha256',
            "site:{$collector->site_id}:collector:{$collector->collector_uuid}:condition:collector_path",
        );
    }
}
