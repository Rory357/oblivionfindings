<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Data\ProtocolObservation;
use App\Domain\Monitoring\Exceptions\SnapshotStoreUnavailable;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Services\Integration\Contracts\SnapshotCollectionCapability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class ConfigurationSnapshotService
{
    private const array RETRIEVABLE_STORAGE_STATES = [
        'available',
        'integrity_failed',
        'missing',
        'unavailable',
    ];

    private const array ROOT_ALLOWLIST = [
        'configuration',
        'firmware_version',
        'hostname',
        'interfaces',
        'inventory_profile',
        'inventory_status',
        'completed_operations',
        'boot_time',
        'disk_bytes_free',
        'disk_bytes_total',
        'disk_usage_percent_max',
        'failed_service_count',
        'failed_operations',
        'installed_packages',
        'manufacturer',
        'model',
        'os_version',
        'routes',
        'serial_number',
        'services',
        'system',
        'volume_count',
    ];

    public function __construct(
        private readonly SnapshotStore $store,
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function captureFromProvider(
        SnapshotCollectionCapability $capability,
        Device $device,
        int $siteId,
        string $provider,
        array $payload,
        ?CarbonImmutable $capturedAt = null,
        ?int $retentionPolicyId = null,
        ?int $actorId = null,
    ): ConfigurationSnapshot {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $provider) !== 1) {
            throw new InvalidArgumentException('Snapshot provider is invalid.');
        }

        // The capability type is the authorization boundary: arbitrary adapter
        // objects cannot place payloads in governed snapshot storage.
        unset($capability);

        return $this->capture(
            $device,
            $siteId,
            sourceKind: 'provider',
            source: $provider,
            payload: $payload,
            capturedAt: $capturedAt ?? CarbonImmutable::now('UTC'),
            retentionPolicyId: $retentionPolicyId,
            actorId: $actorId,
        );
    }

    public function captureFromInventory(
        Device $device,
        int $siteId,
        ProtocolObservation $observation,
        ?int $retentionPolicyId = null,
        ?int $actorId = null,
    ): ConfigurationSnapshot {
        $sourceKind = match (true) {
            str_starts_with($observation->reasonCode, 'ssh_inventory_') => 'ssh',
            str_starts_with($observation->reasonCode, 'winrm_inventory_') => 'winrm',
            default => null,
        };
        if ($sourceKind === null
            || $observation->state->value !== 'healthy'
            || $observation->reasonCode !== "{$sourceKind}_inventory_ok"
            || ($observation->evidence['inventory_status'] ?? null) !== 'ok'
            || ! is_int($observation->evidence['completed_operations'] ?? null)
            || $observation->evidence['completed_operations'] < 1
            || ($observation->evidence['failed_operations'] ?? null) !== 0) {
            throw new InvalidArgumentException('Snapshot requires a complete approved read-only inventory result.');
        }

        return $this->capture(
            $device,
            $siteId,
            sourceKind: $sourceKind,
            source: 'native_read_only_inventory',
            payload: $observation->evidence,
            capturedAt: $observation->observedAt,
            retentionPolicyId: $retentionPolicyId,
            actorId: $actorId,
        );
    }

    public function retrieve(ConfigurationSnapshot $snapshot, User $actor): string
    {
        abort_unless($actor->canDo('securityDevices.devices.view'), 403);
        if ($snapshot->source_kind === 'provider') {
            abort_unless($actor->canDo('securityDevices.integrations.view'), 403);
        }

        $device = Device::query()->findOrFail($snapshot->device_id);
        $this->access->assertCanViewDevice($actor, $device);
        abort_unless($this->sites->resolve((int) $device->id) === (int) $snapshot->site_id, 404);
        abort_unless(
            $snapshot->payload_deleted_at === null
                && in_array($snapshot->storage_state, self::RETRIEVABLE_STORAGE_STATES, true),
            404,
        );

        return $this->verifiedPersistedPayload($snapshot);
    }

    private function capture(
        Device $device,
        int $siteId,
        string $sourceKind,
        string $source,
        array $payload,
        CarbonImmutable $capturedAt,
        ?int $retentionPolicyId,
        ?int $actorId,
    ): ConfigurationSnapshot {
        if ($this->sites->resolve((int) $device->id) !== $siteId) {
            throw new InvalidArgumentException('Snapshot Site does not match the canonical Device.');
        }

        $safe = $this->allowlistedPayload($payload);
        if ($safe === []) {
            throw new InvalidArgumentException('Snapshot contains no approved evidence.');
        }
        $encoded = $this->json($safe);
        $maximum = (int) config('monitoring.storage.snapshots.maximum_payload_bytes', 10_485_760);
        if ($maximum < 1 || strlen($encoded) > $maximum) {
            throw new InvalidArgumentException('Snapshot exceeds the maximum payload size.');
        }

        $configuration = isset($safe['configuration']) && is_array($safe['configuration'])
            ? $safe['configuration']
            : $safe;
        $configurationHash = hash('sha256', $this->json($configuration));
        $contentHash = hash('sha256', $encoded);
        $firmware = isset($safe['firmware_version']) && is_string($safe['firmware_version'])
            ? $safe['firmware_version']
            : null;
        if ($firmware !== null && strlen($firmware) > 128) {
            throw new InvalidArgumentException('Snapshot firmware evidence is invalid.');
        }

        $existing = ConfigurationSnapshot::query()
            ->where('device_id', $device->id)
            ->where('site_id', $siteId)
            ->where('source_kind', $sourceKind)
            ->where('source', $source)
            ->where('captured_at', $capturedAt->utc())
            ->where('content_hash', $contentHash)
            ->where('storage_state', 'available')
            ->first();
        if ($existing !== null) {
            $this->verifiedPersistedPayload($existing);
            $this->ensureRestoreHealthSentinel();
            $existing->refresh();

            return $existing;
        }

        $previous = ConfigurationSnapshot::query()
            ->where('device_id', $device->id)
            ->where('site_id', $siteId)
            ->where('source_kind', $sourceKind)
            ->where('source', $source)
            ->where('storage_state', 'available')
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();
        $previousPayload = [];
        if ($previous !== null) {
            $previousPayload = $this->decode($this->verifiedPersistedPayload($previous));
        }
        $diff = $this->structuralDiff($previousPayload, $safe);
        $uuid = (string) Str::uuid();
        $path = "monitoring/configuration-snapshots/site-{$siteId}/device-{$device->id}/{$uuid}.json.enc";

        $this->store->put($path, $encoded);
        try {
            $this->assertStoredPayload($path, $contentHash, strlen($encoded));
            $this->ensureRestoreHealthSentinel();
        } catch (Throwable $exception) {
            $this->deleteStoredPathBestEffort($path);

            throw $exception instanceof SnapshotStoreUnavailable
                ? $exception
                : new SnapshotStoreUnavailable('Snapshot storage verification failed.', previous: $exception);
        }

        try {
            return DB::transaction(function () use (
                $uuid,
                $siteId,
                $device,
                $sourceKind,
                $source,
                $path,
                $contentHash,
                $configurationHash,
                $encoded,
                $firmware,
                $capturedAt,
                $retentionPolicyId,
                $previous,
                $diff,
                $actorId,
            ): ConfigurationSnapshot {
                $snapshot = ConfigurationSnapshot::query()->create([
                    'snapshot_uuid' => $uuid,
                    'site_id' => $siteId,
                    'device_id' => $device->id,
                    'source_kind' => $sourceKind,
                    'source' => $source,
                    'storage_disk' => (string) config('monitoring.storage.snapshots.disk', 'private'),
                    'storage_path' => $path,
                    'storage_path_hash' => hash('sha256', $path),
                    'storage_state' => 'available',
                    'content_hash' => $contentHash,
                    'configuration_hash' => $configurationHash,
                    'content_size' => strlen($encoded),
                    'mime_type' => 'application/json',
                    'firmware_version' => $firmware,
                    'captured_at' => $capturedAt->utc(),
                    'retention_policy_id' => $retentionPolicyId,
                    'previous_snapshot_id' => $previous?->id,
                    'diff_summary' => $diff,
                    'created_by_user_id' => $actorId,
                ]);

                /** @var Device $locked */
                $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
                $meta = is_array($locked->meta) ? $locked->meta : [];
                $observed = is_array($meta['observed'] ?? null) ? $meta['observed'] : [];
                $meta['observed'] = [
                    ...$observed,
                    'configuration_hash' => $configurationHash,
                    'configuration_at' => $capturedAt->utc()->toISOString(),
                    'configuration_snapshot_id' => $snapshot->id,
                    ...($firmware === null ? [] : ['firmware_at' => $capturedAt->utc()->toISOString()]),
                ];
                $locked->forceFill([
                    'firmware_version' => $firmware ?? $locked->firmware_version,
                    'meta' => $meta,
                ])->save();

                return $snapshot;
            }, 3);
        } catch (Throwable $exception) {
            $this->deleteStoredPathBestEffort($path);

            throw $exception;
        }
    }

    private function verifiedPersistedPayload(ConfigurationSnapshot $snapshot): string
    {
        try {
            if (! $this->store->exists((string) $snapshot->storage_path)) {
                $this->recordStorageStateBestEffort($snapshot, 'missing');

                throw new SnapshotStoreUnavailable('Stored snapshot payload is missing.');
            }

            $payload = $this->store->read((string) $snapshot->storage_path);
        } catch (SnapshotStoreUnavailable $exception) {
            if ($snapshot->storage_state !== 'missing') {
                $this->recordStorageStateBestEffort($snapshot, 'unavailable');
            }

            throw $exception;
        } catch (Throwable $exception) {
            $this->recordStorageStateBestEffort($snapshot, 'unavailable');

            throw new SnapshotStoreUnavailable('Snapshot storage is unavailable.', previous: $exception);
        }

        if (strlen($payload) !== (int) $snapshot->content_size
            || ! hash_equals((string) $snapshot->content_hash, hash('sha256', $payload))) {
            $this->recordStorageStateBestEffort($snapshot, 'integrity_failed');

            throw new SnapshotStoreUnavailable('Stored snapshot payload failed its integrity check.');
        }

        $this->recordStorageStateBestEffort($snapshot, 'available');

        return $payload;
    }

    private function assertStoredPayload(string $path, string $expectedHash, int $expectedSize): void
    {
        $payload = $this->store->read($path);
        if (strlen($payload) !== $expectedSize
            || ! hash_equals($expectedHash, hash('sha256', $payload))) {
            throw new SnapshotStoreUnavailable('Stored snapshot payload failed its integrity check.');
        }
    }

    private function ensureRestoreHealthSentinel(): void
    {
        if ($this->store->exists(SnapshotStore::RESTORE_HEALTH_PATH)) {
            $this->assertRestoreHealthSentinel();

            return;
        }

        $this->store->put(SnapshotStore::RESTORE_HEALTH_PATH, SnapshotStore::RESTORE_HEALTH_CONTENT);
        $this->assertRestoreHealthSentinel();
    }

    private function assertRestoreHealthSentinel(): void
    {
        if (! $this->store->exists(SnapshotStore::RESTORE_HEALTH_PATH)) {
            throw new SnapshotStoreUnavailable('Snapshot restore sentinel could not be verified.');
        }

        $contents = $this->store->read(SnapshotStore::RESTORE_HEALTH_PATH);
        if (! hash_equals(SnapshotStore::RESTORE_HEALTH_CONTENT, $contents)) {
            throw new SnapshotStoreUnavailable('Snapshot restore sentinel failed its integrity check.');
        }
    }

    private function recordStorageStateBestEffort(ConfigurationSnapshot $snapshot, string $state): void
    {
        if ($snapshot->storage_state === $state) {
            return;
        }

        try {
            DB::transaction(function () use ($snapshot, $state): void {
                $locked = ConfigurationSnapshot::query()->lockForUpdate()->find($snapshot->id);
                if (! $locked instanceof ConfigurationSnapshot
                    || $locked->payload_deleted_at !== null
                    || $locked->storage_state === 'deleted') {
                    return;
                }

                $locked->forceFill(['storage_state' => $state])->save();
                $snapshot->setAttribute('storage_state', $state);
            }, 3);
        } catch (Throwable) {
            // Retained hash/size evidence remains available for reconciliation.
        }
    }

    private function deleteStoredPathBestEffort(string $path): void
    {
        try {
            $this->store->delete($path);
        } catch (Throwable) {
            // No metadata row presents the unverified object as governed
            // evidence; preserve the original write or transaction failure.
        }
    }

    /** @return array<string, mixed> */
    private function allowlistedPayload(array $payload): array
    {
        $safe = [];
        foreach (self::ROOT_ALLOWLIST as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $candidate = $this->sanitise($payload[$key], 1);
            if ($candidate !== null) {
                $safe[$key] = $candidate;
            }
        }
        ksort($safe, SORT_STRING);

        return $safe;
    }

    private function sanitise(mixed $value, int $depth): mixed
    {
        if ($depth > 16) {
            throw new InvalidArgumentException('Snapshot structure is too deep.');
        }
        if (is_string($value)) {
            return strlen($value) <= 4096 && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) !== 1
                ? $value
                : null;
        }
        if (is_int($value) || is_bool($value) || $value === null) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }
        if (! is_array($value) || count($value) > 1000) {
            return null;
        }

        $safe = [];
        foreach ($value as $key => $candidate) {
            if (! is_int($key)
                && (! is_string($key)
                    || strlen($key) > 128
                    || preg_match('/authorization|cookie|credential|key_material|passphrase|password|private_key|secret|token|raw_/i', $key) === 1)) {
                continue;
            }
            $sanitised = $this->sanitise($candidate, $depth + 1);
            if ($sanitised !== null) {
                $safe[$key] = $sanitised;
            }
        }
        if (! array_is_list($safe)) {
            ksort($safe, SORT_STRING);
        }

        return $safe;
    }

    /** @return array{added: list<string>, removed: list<string>, changed: list<string>, truncated: bool} */
    private function structuralDiff(array $before, array $after): array
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
        $maximum = max(1, (int) config('monitoring.storage.snapshots.maximum_diff_paths', 200));
        $all = [];
        foreach (['added' => $added, 'removed' => $removed, 'changed' => $changed] as $kind => $paths) {
            foreach ($paths as $path) {
                $all[] = [$kind, $path];
            }
        }
        $truncated = count($all) > $maximum;
        $result = ['added' => [], 'removed' => [], 'changed' => [], 'truncated' => $truncated];
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

    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Snapshot JSON is invalid.', previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $encoded): array
    {
        try {
            $decoded = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SnapshotStoreUnavailable('Stored snapshot is invalid.', previous: $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new SnapshotStoreUnavailable('Stored snapshot is invalid.');
        }

        return $decoded;
    }
}
