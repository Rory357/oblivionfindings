<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\CollectorCheckpoint;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use Carbon\CarbonImmutable;
use DomainException;
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
                $affected = $locked->monitors()->where('is_enabled', true)->get(['id', 'device_id']);
                $locked->monitors()->where('is_enabled', true)->update([
                    'effective_state' => MonitorState::Stale->value,
                    'suppression_reason' => 'collector_path',
                    'suppressed_at' => $at,
                ]);
                $this->event($locked, 'offline', 'high', [
                    'affected_device_count' => $affected->pluck('device_id')->unique()->count(),
                    'affected_monitor_count' => $affected->count(),
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
            $hasGap = $checkpoint->gap_from !== null || (int) $locked->gap_count > 0;
            $nextStatus = $wasUnavailable && $hasGap
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
                'last_recovered_at' => $wasUnavailable && ! $hasGap ? $receivedAt : $locked->last_recovered_at,
            ])->save();
            if ($wasUnavailable && ! $hasGap) {
                DB::table('monitors')->where('collector_id', $locked->id)->where('is_enabled', true)->update([
                    'effective_state' => DB::raw('current_state'),
                    'suppression_reason' => null,
                    'suppressed_at' => null,
                ]);
                $backlogAge = $heartbeat['oldest_spool_item_at'] === null
                    ? 0
                    : max(0, $heartbeat['oldest_spool_item_at']->diffInSeconds($receivedAt));
                $this->event($locked, 'online', 'info', [
                    'affected_device_count' => $locked->monitors()->distinct()->count('device_id'),
                    'affected_monitor_count' => $locked->monitors()->count(),
                    'backlog_items' => $heartbeat['spool_items'],
                    'backlog_age_seconds' => $backlogAge,
                    'gap_count' => 0,
                    'clock_drift_seconds' => $locked->last_clock_drift_seconds,
                ], $receivedAt);
            }

            return $locked->fresh(['checkpoint']);
        }, 3);
    }

    /** @param array<string, mixed> $status @return array{reported_at: CarbonImmutable, state: string, spool_items: int, spool_bytes: int, corrupted_frames: int, oldest_spool_item_at: ?CarbonImmutable, runtime: array<string, int|string|bool|null>} */
    private function validatedHeartbeat(array $status): array
    {
        $reportedAt = $status['reported_at'] ?? null;
        $state = $status['state'] ?? null;
        $items = $status['spool_items'] ?? null;
        $bytes = $status['spool_bytes'] ?? null;
        $corrupt = $status['corrupted_frames'] ?? null;
        $runtime = $status['runtime'] ?? null;
        if (! is_string($reportedAt) || ! in_array($state, ['writable', 'buffer_full'], true)
            || ! is_int($items) || $items < 0 || $items > 100_000
            || ! is_int($bytes) || $bytes < 0 || $bytes > 67_108_864
            || ! is_int($corrupt) || $corrupt < 0 || $corrupt > 100_000
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

        return [
            'reported_at' => $reported,
            'state' => $state,
            'spool_items' => $items,
            'spool_bytes' => $bytes,
            'corrupted_frames' => $corrupt,
            'oldest_spool_item_at' => $oldest,
            'runtime' => $runtime,
        ];
    }

    /** @param array<string, int|string|null> $evidence */
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
        $correlationKey = hash(
            'sha256',
            "site:{$collector->site_id}:collector:{$collector->collector_uuid}:condition:collector_path",
        );
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
                'monitor_correlation_key' => $correlationKey,
                ...$evidence,
            ],
        ]);
    }
}
