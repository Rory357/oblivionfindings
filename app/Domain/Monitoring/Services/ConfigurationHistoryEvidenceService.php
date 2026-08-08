<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Data\TimeSeriesPoint;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Support\ConfigurationHistoryEvidenceContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

final class ConfigurationHistoryEvidenceService
{
    private const array CHECKS = [
        'isolated_restored_runtime',
        'verified_restore_artifact',
        'restored_snapshot_sentinel',
        'external_contract_linkage',
        'mysql_snapshot_references',
        'authoritative_real_host_inventory',
        'later_changed_snapshot',
        'bounded_structural_diff',
        'firmware_history',
        'restored_snapshot_payload_integrity',
        'mysql_capacity_history_reference',
        'restored_capacity_history_integrity',
        'restored_browser_companion_linkage',
    ];

    public function __construct(
        private readonly SnapshotStore $snapshots,
        private readonly TimeSeriesStore $timeSeries,
        private readonly ConfigurationHistoryEvidenceContract $contract,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $browser
     * @param  array{document: array<string, mixed>, sha256: string}  $restore
     * @return array<string, mixed>
     */
    public function report(array $manifest, array $browser, array $restore): array
    {
        $checks = array_fill_keys(self::CHECKS, 'not_verified');
        $now = CarbonImmutable::now('UTC');

        if (! $this->contract->restoredRuntimeIsIsolated(
            $this->snapshots::class,
            $this->timeSeries::class,
        )) {
            return $this->result($checks, $manifest, $browser, $restore, $now);
        }
        $checks['isolated_restored_runtime'] = 'verified';

        try {
            $this->contract->assertLinked($manifest, $browser, $restore);
            $checks['external_contract_linkage'] = 'verified';
            $checks['verified_restore_artifact'] = 'verified';

            if (! $this->restoredSentinelMatches()) {
                return $this->result($checks, $manifest, $browser, $restore, $now);
            }
            $checks['restored_snapshot_sentinel'] = 'verified';

            $baselineId = (int) data_get($manifest, 'mysql.baseline_snapshot_id');
            $changedId = (int) data_get($manifest, 'mysql.changed_snapshot_id');
            $records = ConfigurationSnapshot::query()
                ->whereKey([$baselineId, $changedId])
                ->get()
                ->keyBy('id');
            $baseline = $records->get($baselineId);
            $changed = $records->get($changedId);
            if (! $baseline instanceof ConfigurationSnapshot
                || ! $changed instanceof ConfigurationSnapshot
                || ! $this->snapshotReferencesMatch($baseline, $changed, $manifest)) {
                return $this->result($checks, $manifest, $browser, $restore, $now);
            }
            $checks['mysql_snapshot_references'] = 'verified';

            [$baselinePayload, $changedPayload] = $this->restoredPayloads($baseline, $changed, $manifest);
            $checks['restored_snapshot_payload_integrity'] = 'verified';

            if (! $this->authoritativeInventory($baseline, $baselinePayload)
                || ! $this->authoritativeInventory($changed, $changedPayload)) {
                return $this->result($checks, $manifest, $browser, $restore, $now);
            }
            $checks['authoritative_real_host_inventory'] = 'verified';

            if (! $this->changedHistoryMatches($baseline, $changed, $manifest, $baselinePayload, $changedPayload)) {
                return $this->result($checks, $manifest, $browser, $restore, $now);
            }
            $checks['later_changed_snapshot'] = 'verified';

            if (! $this->boundedDiffMatches($changed, $manifest, $baselinePayload, $changedPayload)) {
                return $this->result($checks, $manifest, $browser, $restore, $now);
            }
            $checks['bounded_structural_diff'] = 'verified';

            if (! $this->firmwareMatches($baseline, $changed, $manifest, $baselinePayload, $changedPayload)) {
                return $this->result($checks, $manifest, $browser, $restore, $now);
            }
            $checks['firmware_history'] = 'verified';

            $series = MetricSeries::query()->find((int) data_get($manifest, 'mysql.capacity_series_id'));
            $event = DB::table('monitoring_metric_series_pointer_events')
                ->where('id', (int) data_get($manifest, 'mysql.capacity_pointer_event_id'))
                ->first();
            if (! $series instanceof MetricSeries
                || $event === null
                || ! $this->capacityReferenceMatches($series, $event, $changed, $manifest)) {
                return $this->result($checks, $manifest, $browser, $restore, $now);
            }
            $checks['mysql_capacity_history_reference'] = 'verified';

            if (! $this->restoredCapacityHistoryMatches($series, $event)) {
                return $this->result($checks, $manifest, $browser, $restore, $now);
            }
            $checks['restored_capacity_history_integrity'] = 'verified';
            $checks['restored_browser_companion_linkage'] = 'verified';
        } catch (Throwable) {
            // Evidence reports are deliberately value-free. Dependency and
            // payload exceptions are represented only as not_verified.
        }

        return $this->result($checks, $manifest, $browser, $restore, $now);
    }

    /** @param array<string, mixed> $manifest */
    private function snapshotReferencesMatch(
        ConfigurationSnapshot $baseline,
        ConfigurationSnapshot $changed,
        array $manifest,
    ): bool {
        return (int) $baseline->id !== (int) $changed->id
            && hash_equals((string) data_get($manifest, 'mysql.baseline_snapshot_uuid'), (string) $baseline->snapshot_uuid)
            && hash_equals((string) data_get($manifest, 'mysql.changed_snapshot_uuid'), (string) $changed->snapshot_uuid)
            && hash_equals((string) $baseline->storage_path_hash, hash('sha256', (string) $baseline->storage_path))
            && hash_equals((string) $changed->storage_path_hash, hash('sha256', (string) $changed->storage_path))
            && hash_equals(
                (string) data_get($manifest, 'commitments.baseline_storage_path_hmac_sha256'),
                $this->contract->commitment((string) $baseline->storage_path),
            )
            && hash_equals(
                (string) data_get($manifest, 'commitments.changed_storage_path_hmac_sha256'),
                $this->contract->commitment((string) $changed->storage_path),
            )
            && hash_equals(
                (string) data_get($manifest, 'commitments.target_identity_hmac_sha256'),
                $this->contract->commitment("site:{$baseline->site_id}|device:{$baseline->device_id}"),
            )
            && $baseline->storage_disk === 'monitoring-restore'
            && $changed->storage_disk === 'monitoring-restore'
            && $baseline->storage_state === 'available'
            && $changed->storage_state === 'available'
            && $baseline->payload_deleted_at === null
            && $changed->payload_deleted_at === null
            && $baseline->mime_type === 'application/json'
            && $changed->mime_type === 'application/json';
    }

    /** @return array{array<string, mixed>, array<string, mixed>} */
    private function restoredPayloads(
        ConfigurationSnapshot $baseline,
        ConfigurationSnapshot $changed,
        array $manifest,
    ): array {
        $payloads = [];
        foreach ([
            ['snapshot' => $baseline, 'commitment' => 'baseline_content_hmac_sha256'],
            ['snapshot' => $changed, 'commitment' => 'changed_content_hmac_sha256'],
        ] as $entry) {
            /** @var ConfigurationSnapshot $snapshot */
            $snapshot = $entry['snapshot'];
            $path = (string) $snapshot->storage_path;
            if (! $this->snapshots->exists($path)) {
                throw new \RuntimeException('Snapshot integrity is not verified.');
            }
            $encoded = $this->snapshots->read($path);
            if (strlen($encoded) !== (int) $snapshot->content_size
                || ! hash_equals((string) $snapshot->content_hash, hash('sha256', $encoded))
                || ! hash_equals(
                    (string) data_get($manifest, 'commitments.'.$entry['commitment']),
                    $this->contract->commitment($encoded),
                )) {
                throw new \RuntimeException('Snapshot integrity is not verified.');
            }
            try {
                $decoded = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new \RuntimeException('Snapshot integrity is not verified.');
            }
            if (! is_array($decoded) || array_is_list($decoded)) {
                throw new \RuntimeException('Snapshot integrity is not verified.');
            }
            $payloads[] = $decoded;
        }

        return [$payloads[0], $payloads[1]];
    }

    /** @param array<string, mixed> $payload */
    private function authoritativeInventory(ConfigurationSnapshot $snapshot, array $payload): bool
    {
        return in_array($snapshot->source_kind, ['ssh', 'winrm'], true)
            && $snapshot->source === 'native_read_only_inventory'
            && ($payload['inventory_status'] ?? null) === 'ok'
            && is_int($payload['completed_operations'] ?? null)
            && $payload['completed_operations'] > 0
            && ($payload['failed_operations'] ?? null) === 0
            && isset($payload['configuration'])
            && is_array($payload['configuration'])
            && $payload['configuration'] !== [];
    }

    /** @param array<string, mixed> $manifest @param array<string, mixed> $baselinePayload @param array<string, mixed> $changedPayload */
    private function changedHistoryMatches(
        ConfigurationSnapshot $baseline,
        ConfigurationSnapshot $changed,
        array $manifest,
        array $baselinePayload,
        array $changedPayload,
    ): bool {
        $started = CarbonImmutable::parse((string) $manifest['observation_started_at_utc'])->utc();
        $completed = CarbonImmutable::parse((string) $manifest['observation_completed_at_utc'])->utc();
        $baselineConfigurationHash = hash('sha256', $this->json($baselinePayload['configuration']));
        $changedConfigurationHash = hash('sha256', $this->json($changedPayload['configuration']));

        return (int) $baseline->site_id === (int) $changed->site_id
            && (int) $baseline->device_id === (int) $changed->device_id
            && $baseline->source_kind === $changed->source_kind
            && $baseline->source === $changed->source
            && (int) $changed->previous_snapshot_id === (int) $baseline->id
            && $baseline->captured_at->betweenIncluded($started, $completed)
            && $changed->captured_at->betweenIncluded($started, $completed)
            && $changed->captured_at->gt($baseline->captured_at)
            && ! hash_equals((string) $baseline->content_hash, (string) $changed->content_hash)
            && ! hash_equals((string) $baseline->configuration_hash, (string) $changed->configuration_hash)
            && hash_equals((string) $baseline->configuration_hash, $baselineConfigurationHash)
            && hash_equals((string) $changed->configuration_hash, $changedConfigurationHash)
            && hash_equals(
                (string) data_get($manifest, 'commitments.baseline_configuration_hmac_sha256'),
                $this->contract->commitment($this->json($baselinePayload['configuration'])),
            )
            && hash_equals(
                (string) data_get($manifest, 'commitments.changed_configuration_hmac_sha256'),
                $this->contract->commitment($this->json($changedPayload['configuration'])),
            );
    }

    /** @param array<string, mixed> $manifest */
    private function boundedDiffMatches(
        ConfigurationSnapshot $changed,
        array $manifest,
        array $baselinePayload,
        array $changedPayload,
    ): bool {
        $diff = $changed->diff_summary;
        if (! is_array($diff) || array_is_list($diff)) {
            return false;
        }
        $keys = array_keys($diff);
        sort($keys, SORT_STRING);
        if ($keys !== ['added', 'changed', 'removed', 'truncated']) {
            return false;
        }
        $diff = [
            'added' => $diff['added'],
            'removed' => $diff['removed'],
            'changed' => $diff['changed'],
            'truncated' => $diff['truncated'],
        ];
        $paths = [];
        foreach (['added', 'removed', 'changed'] as $kind) {
            if (! is_array($diff[$kind]) || ! array_is_list($diff[$kind])) {
                return false;
            }
            foreach ($diff[$kind] as $path) {
                if (! is_string($path) || $path === '' || strlen($path) > 2048) {
                    return false;
                }
                $paths[] = $kind.':'.$path;
            }
        }
        $maximum = max(1, (int) config('monitoring.storage.snapshots.maximum_diff_paths', 200));
        $expected = $this->structuralDiff($baselinePayload, $changedPayload, $maximum);

        return is_bool($diff['truncated'])
            && $paths !== []
            && count($paths) <= $maximum
            && count($paths) === count(array_unique($paths))
            && (! $diff['truncated'] || count($paths) === $maximum)
            && $diff === $expected
            && hash_equals(
                (string) data_get($manifest, 'commitments.diff_summary_hmac_sha256'),
                $this->contract->commitment($this->json($diff)),
            );
    }

    /** @param array<string, mixed> $manifest @param array<string, mixed> $baselinePayload @param array<string, mixed> $changedPayload */
    private function firmwareMatches(
        ConfigurationSnapshot $baseline,
        ConfigurationSnapshot $changed,
        array $manifest,
        array $baselinePayload,
        array $changedPayload,
    ): bool {
        $baselineFirmware = $baselinePayload['firmware_version'] ?? null;
        $changedFirmware = $changedPayload['firmware_version'] ?? null;

        return is_string($baselineFirmware)
            && is_string($changedFirmware)
            && $baselineFirmware !== ''
            && $changedFirmware !== ''
            && strlen($baselineFirmware) <= 128
            && strlen($changedFirmware) <= 128
            && hash_equals((string) $baseline->firmware_version, $baselineFirmware)
            && hash_equals((string) $changed->firmware_version, $changedFirmware)
            && hash_equals(
                (string) data_get($manifest, 'commitments.baseline_firmware_hmac_sha256'),
                $this->contract->commitment($baselineFirmware),
            )
            && hash_equals(
                (string) data_get($manifest, 'commitments.changed_firmware_hmac_sha256'),
                $this->contract->commitment($changedFirmware),
            );
    }

    /** @param object $event @param array<string, mixed> $manifest */
    private function capacityReferenceMatches(
        MetricSeries $series,
        object $event,
        ConfigurationSnapshot $changed,
        array $manifest,
    ): bool {
        $fromFirst = $this->time($event->from_first_point_at ?? null);
        $toFirst = $this->time($event->to_first_point_at ?? null);
        $fromLast = $this->time($event->from_last_point_at ?? null);
        $toLast = $this->time($event->to_last_point_at ?? null);
        $occurred = $this->time($event->occurred_at ?? null);
        $started = CarbonImmutable::parse((string) $manifest['observation_started_at_utc'])->utc();
        $completed = CarbonImmutable::parse((string) $manifest['observation_completed_at_utc'])->utc();

        return (int) ($event->series_id ?? 0) === (int) $series->id
            && ($event->transition_kind ?? null) === 'range_extended'
            && $fromFirst !== null
            && $toFirst !== null
            && $fromLast !== null
            && $toLast !== null
            && $occurred !== null
            && $toFirst->lessThanOrEqualTo($fromFirst)
            && $toLast->gt($fromLast)
            && $occurred->betweenIncluded($started, $completed)
            && $series->first_point_at !== null
            && $series->last_point_at !== null
            && $series->first_point_at->lessThanOrEqualTo($toFirst)
            && $series->last_point_at->greaterThanOrEqualTo($toLast)
            && (int) $series->site_id === (int) $changed->site_id
            && (int) $series->device_id === (int) $changed->device_id
            && $this->capacityMetric((string) $series->metric, (string) $series->unit)
            && hash_equals(
                (string) data_get($manifest, 'commitments.capacity_external_key_hmac_sha256'),
                $this->contract->commitment((string) $series->external_key),
            );
    }

    private function restoredCapacityHistoryMatches(MetricSeries $series, object $event): bool
    {
        $from = $this->time($event->from_last_point_at ?? null);
        $to = $this->time($event->to_last_point_at ?? null);
        if ($from === null || $to === null || ! $this->timeSeries->healthy()) {
            return false;
        }
        $points = $this->timeSeries->range(
            (string) $series->external_key,
            (string) $series->retention_tier,
            $from,
            $to->addMicrosecond(),
        );
        if (count($points) < 2) {
            return false;
        }

        $hasFrom = false;
        $hasTo = false;
        foreach ($points as $point) {
            if (! $point instanceof TimeSeriesPoint
                || $point->seriesId !== (int) $series->id
                || $point->siteId !== (int) $series->site_id
                || $point->deviceId !== (int) $series->device_id
                || $point->metric !== (string) $series->metric
                || $point->tier !== (string) $series->retention_tier
                || ! is_finite($point->value)) {
                return false;
            }
            $hasFrom = $hasFrom || $point->observedAt->equalTo($from);
            $hasTo = $hasTo || $point->observedAt->equalTo($to);
        }

        return $hasFrom && $hasTo;
    }

    private function restoredSentinelMatches(): bool
    {
        try {
            return $this->snapshots->exists(SnapshotStore::RESTORE_HEALTH_PATH)
                && hash_equals(
                    SnapshotStore::RESTORE_HEALTH_CONTENT,
                    $this->snapshots->read(SnapshotStore::RESTORE_HEALTH_PATH),
                );
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{added: list<string>, removed: list<string>, changed: list<string>, truncated: bool} */
    private function structuralDiff(array $before, array $after, int $maximum): array
    {
        $beforePaths = $this->leafPaths($before);
        $afterPaths = $this->leafPaths($after);
        $added = array_values(array_diff(array_keys($afterPaths), array_keys($beforePaths)));
        $removed = array_values(array_diff(array_keys($beforePaths), array_keys($afterPaths)));
        $changed = [];
        foreach (array_intersect(array_keys($beforePaths), array_keys($afterPaths)) as $path) {
            if ($beforePaths[$path] !== $afterPaths[$path]) {
                $changed[] = $path;
            }
        }
        sort($added, SORT_STRING);
        sort($removed, SORT_STRING);
        sort($changed, SORT_STRING);
        $all = [];
        foreach (['added' => $added, 'removed' => $removed, 'changed' => $changed] as $kind => $paths) {
            foreach ($paths as $path) {
                $all[] = [$kind, $path];
            }
        }
        $result = [
            'added' => [],
            'removed' => [],
            'changed' => [],
            'truncated' => count($all) > $maximum,
        ];
        foreach (array_slice($all, 0, $maximum) as [$kind, $path]) {
            $result[$kind][] = $path;
        }

        return $result;
    }

    /** @return array<string, string> */
    private function leafPaths(array $document, string $prefix = ''): array
    {
        $paths = [];
        foreach ($document as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $paths += $this->leafPaths($value, $path);
            } else {
                $paths[$path] = hash('sha256', $this->json(['leaf' => $value]));
            }
        }

        return $paths;
    }

    private function capacityMetric(string $metric, string $unit): bool
    {
        if (! in_array($unit, [
            'percent',
            'bytes',
            'bits_per_second',
            'kilobits_per_second',
            'megabits_per_second',
            'gigabits_per_second',
        ], true)) {
            return false;
        }

        foreach ([
            'capacity',
            'utilization',
            'utilisation',
            'disk_bytes_free',
            'disk_bytes_total',
            'disk_usage_percent_max',
            'cpu_utilization_percent',
            'memory_utilization_percent',
            'in_bps',
            'out_bps',
        ] as $suffix) {
            if ($metric === $suffix || str_ends_with($metric, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        try {
            return CarbonImmutable::parse((string) $value, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, string>  $checks
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $browser
     * @param  array{document: array<string, mixed>, sha256: string}  $restore
     * @return array<string, mixed>
     */
    private function result(
        array $checks,
        array $manifest,
        array $browser,
        array $restore,
        CarbonImmutable $now,
    ): array {
        $allVerified = collect($checks)->every(fn (string $state): bool => $state === 'verified');

        return [
            'checked_at' => $now->toIso8601String(),
            'evidence_fingerprint' => hash('sha256', $this->json([
                'manifest' => $manifest,
                'browser' => $browser,
                'restore_sha256' => $restore['sha256'] ?? null,
            ])),
            'all_verified' => $allVerified,
            'checks' => $checks,
            'verified_mysql_rows' => $allVerified ? 4 : 0,
            'verified_snapshot_payloads' => $checks['restored_snapshot_payload_integrity'] === 'verified' ? 2 : 0,
            'verified_capacity_boundary_points' => $checks['restored_capacity_history_integrity'] === 'verified' ? 2 : 0,
        ];
    }
}
