<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\SecurityDevices\Endpoints\Models\IntuneDeviceLink;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSyncLog;
use App\Models\Site;
use App\Models\User;
use App\Support\SafeOperationalData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final readonly class IntuneBulkImportService
{
    private const MAX_DISCOVERED_CACHE = 500;

    public function __construct(
        private IntuneReadClient $client,
        private IntuneVisibilitySync $visibilitySync,
        private SecurityDevicesAccessService $access,
    ) {}

    /**
     * Discover Windows computers in Microsoft Intune and cache bounded metadata.
     *
     * @return list<array<string, mixed>>
     */
    public function discover(IntegrationProviderConnection $connection, User $actor): array
    {
        $windowsDevices = $this->fetchWindowsSnapshots($connection);
        $config = is_array($connection->config) ? $connection->config : [];
        $connection->update([
            'config' => array_merge($config, [
                'discovered_devices' => array_slice($windowsDevices, 0, self::MAX_DISCOVERED_CACHE),
                'discovered_at' => now()->toISOString(),
            ]),
            'last_synced_at' => now(),
            'last_error' => null,
        ]);

        return $this->presentDiscovered($connection->fresh() ?? $connection, $actor);
    }

    /**
     * Present cached discovered Windows computers with current link status scoped to the viewer.
     *
     * @return list<array<string, mixed>>
     */
    public function presentDiscovered(?IntegrationProviderConnection $connection, User $actor): array
    {
        $config = is_array($connection?->config) ? $connection->config : [];
        $cached = collect($config['discovered_devices'] ?? [])
            ->filter(static fn (mixed $item): bool => is_array($item) && is_string($item['id'] ?? null) && $item['id'] !== '')
            ->values();

        if ($cached->isEmpty()) {
            return [];
        }

        $managedIds = $cached
            ->map(static fn (array $item): string => strtolower((string) $item['id']))
            ->unique()
            ->values()
            ->all();

        $links = Schema::hasTable('endpoint_intune_links')
            ? IntuneDeviceLink::query()
                ->whereIn('managed_device_id', $managedIds)
                ->with([
                    'device' => static fn ($query) => $query->select(['id', 'name']),
                    'device.assignments' => static fn ($query) => $query->active(),
                ])
                ->get()
                ->keyBy(static fn (IntuneDeviceLink $link): string => strtolower($link->managed_device_id))
            : collect();

        $linkedDeviceIds = $links
            ->pluck('device_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $visibleDeviceIds = $linkedDeviceIds === []
            ? collect()
            : $this->access->visibleDevices($actor)
                ->whereIn('devices.id', $linkedDeviceIds)
                ->pluck('devices.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->flip();

        $accessibleSiteIds = $this->access->accessibleSiteIds($actor);
        $siteNames = $accessibleSiteIds === []
            ? collect()
            : Site::query()
                ->whereIn('id', $accessibleSiteIds)
                ->pluck('name', 'id');

        return $cached->map(function (array $snapshot) use ($links, $siteNames, $visibleDeviceIds): array {
            $id = strtolower((string) $snapshot['id']);
            /** @var IntuneDeviceLink|null $link */
            $link = $links->get($id);
            $linkedDevice = $link?->device;
            $isVisible = $linkedDevice !== null && $visibleDeviceIds->has((int) $linkedDevice->id);

            $siteName = null;
            if ($isVisible && $linkedDevice !== null) {
                $activeSiteAssignment = $linkedDevice->assignments
                    ->first(static fn (DeviceAssignment $assignment): bool => $assignment->assignable_type === DeviceAssignment::TARGET_SITE
                        && $assignment->released_at === null);
                $assignedSiteId = $activeSiteAssignment ? (int) $activeSiteAssignment->assignable_id : null;
                if ($assignedSiteId !== null && $siteNames->has($assignedSiteId)) {
                    $siteName = (string) $siteNames->get($assignedSiteId);
                }
            }

            return [
                'managed_device_id' => $id,
                'device_name' => $this->deviceName($snapshot, $id),
                'operating_system' => $this->nullableTrimmed($snapshot['operatingSystem'] ?? null, 120) ?? 'Windows',
                'os_version' => $this->nullableTrimmed($snapshot['osVersion'] ?? null, 120),
                'compliance_state' => $this->nullableTrimmed($snapshot['complianceState'] ?? null, 60),
                'is_encrypted' => is_bool($snapshot['isEncrypted'] ?? null) ? $snapshot['isEncrypted'] : null,
                'manufacturer' => $this->nullableTrimmed($snapshot['manufacturer'] ?? null, 120),
                'model' => $this->nullableTrimmed($snapshot['model'] ?? null, 160),
                'serial_number' => $this->nullableTrimmed($snapshot['serialNumber'] ?? null, 120),
                'owner_type' => $this->nullableTrimmed($snapshot['managedDeviceOwnerType'] ?? null, 60),
                'last_sync_at' => $this->nullableTrimmed($snapshot['lastSyncDateTime'] ?? null, 64),
                'link_state' => $link === null ? 'unlinked' : ($isVisible ? 'linked' : 'linked_restricted'),
                'linked_device' => $isVisible && $linkedDevice !== null ? [
                    'id' => (int) $linkedDevice->id,
                    'name' => (string) $linkedDevice->name,
                    'href' => "/security-devices/devices/{$linkedDevice->id}",
                    'site_name' => $siteName,
                ] : null,
            ];
        })->values()->all();
    }

    /**
     * Import selected Microsoft Intune Windows computers into Devices assigned to a Site.
     *
     * @param  list<string>  $managedDeviceIds
     * @return array{processed: int, created: int, updated: int, errored: int}
     */
    public function import(
        IntegrationProviderConnection $connection,
        User $actor,
        int $siteId,
        array $managedDeviceIds,
    ): array {
        if (! Schema::hasTable('endpoint_intune_links')) {
            throw new RuntimeException('Intune link storage is unavailable.');
        }

        $this->access->assertCanViewSite($actor, $siteId);
        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where(static fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
            ->whereNull('archived_at')
            ->first();
        if ($site === null) {
            throw new RuntimeException('Selected Site is not active.');
        }

        $selectedIds = collect($managedDeviceIds)
            ->map(static fn (string $id): string => strtolower(trim($id)))
            ->filter(static fn (string $id): bool => $id !== '')
            ->unique()
            ->values()
            ->all();

        $syncLog = IntegrationSyncLog::query()->create([
            'provider' => 'intune',
            'site_id' => $site->id,
            'action' => 'import_devices',
            'status' => IntegrationSyncLog::STATUS_STARTED,
            'started_at' => now(),
        ]);

        try {
            $windowsSnapshots = $this->fetchWindowsSnapshots($connection);
            $config = is_array($connection->config) ? $connection->config : [];
            $connection->update([
                'config' => array_merge($config, [
                    'discovered_devices' => array_slice($windowsSnapshots, 0, self::MAX_DISCOVERED_CACHE),
                    'discovered_at' => now()->toISOString(),
                ]),
            ]);

            $byId = collect($windowsSnapshots)->keyBy(static fn (array $item): string => strtolower((string) $item['id']));

            $processed = 0;
            $created = 0;
            $updated = 0;
            $errored = 0;

            foreach ($selectedIds as $managedId) {
                $processed++;
                $snapshot = $byId->get($managedId);
                if (! is_array($snapshot)) {
                    $errored++;

                    continue;
                }

                try {
                    $wasCreated = DB::transaction(function () use ($actor, $managedId, $site, $snapshot): bool {
                        $existing = IntuneDeviceLink::query()
                            ->where('managed_device_id', $managedId)
                            ->lockForUpdate()
                            ->first();

                        if ($existing !== null) {
                            $existing->fill([
                                'snapshot' => $snapshot,
                                'synced_at' => now(),
                                'last_attempt_at' => now(),
                                'sync_status' => 'current',
                            ])->save();

                            return false;
                        }

                        $category = $this->inferCategory($snapshot);
                        $device = Device::query()->create([
                            'name' => $this->deviceName($snapshot, $managedId),
                            'domain' => 'it_infrastructure',
                            'category' => $category,
                            'subcategory' => $category === 'server' ? 'windows_server' : 'desktop',
                            'manufacturer' => $this->nullableTrimmed($snapshot['manufacturer'] ?? null, 120),
                            'model' => $this->nullableTrimmed($snapshot['model'] ?? null, 160),
                            'serial_number' => $this->nullableTrimmed($snapshot['serialNumber'] ?? null, 120),
                            'firmware_version' => $this->nullableTrimmed($snapshot['osVersion'] ?? null, 120),
                            'status' => DeviceStatus::Active,
                            'health_status' => $this->inferHealthStatus($snapshot),
                            'provider' => 'intune',
                            'external_ref' => [
                                'provider_entity_id' => $managedId,
                                'intune_managed_device_id' => $managedId,
                            ],
                            'provider_observed_state' => [
                                'compliance_state' => $snapshot['complianceState'] ?? null,
                                'is_encrypted' => $snapshot['isEncrypted'] ?? null,
                                'operating_system' => $snapshot['operatingSystem'] ?? null,
                                'os_version' => $snapshot['osVersion'] ?? null,
                                'last_sync_at' => $snapshot['lastSyncDateTime'] ?? null,
                            ],
                            'created_by_user_id' => $actor->id,
                        ]);

                        DeviceAssignment::query()->create([
                            'device_id' => $device->id,
                            'assignable_type' => DeviceAssignment::TARGET_SITE,
                            'assignable_id' => $site->id,
                            'custody_site_id' => $site->id,
                            'assigned_at' => now(),
                            'assigned_by_user_id' => $actor->id,
                            'notes' => 'Imported from Microsoft Intune.',
                        ]);

                        IntuneDeviceLink::query()->create([
                            'device_id' => $device->id,
                            'managed_device_id' => $managedId,
                            'snapshot' => $snapshot,
                            'synced_at' => now(),
                            'last_attempt_at' => now(),
                            'sync_status' => 'current',
                            'linked_by_user_id' => $actor->id,
                        ]);

                        DeviceEvent::query()->create([
                            'device_id' => $device->id,
                            'event_type' => 'endpoint_intune_imported',
                            'severity' => 'info',
                            'source' => 'intune',
                            'occurred_at' => now(),
                            'payload' => [
                                'actor_user_id' => (int) $actor->id,
                                'managed_device_id' => $managedId,
                                'site_id' => (int) $site->id,
                            ],
                        ]);

                        return true;
                    });

                    if ($wasCreated) {
                        $created++;
                    } else {
                        $updated++;
                    }
                } catch (\Throwable) {
                    $errored++;
                }
            }

            $syncLog->update([
                'items_processed' => $processed,
                'items_created' => $created,
                'items_updated' => $updated,
                'items_errored' => $errored,
            ]);

            $status = $errored === 0
                ? IntegrationSyncLog::STATUS_SUCCESS
                : (($created + $updated) > 0 ? IntegrationSyncLog::STATUS_PARTIAL : IntegrationSyncLog::STATUS_FAILED);

            $syncLog->markCompleted(
                $status,
                $status === IntegrationSyncLog::STATUS_FAILED ? SafeOperationalData::failureSummary() : null,
            );

            $connection->update([
                'last_synced_at' => now(),
                'last_error' => $status === IntegrationSyncLog::STATUS_FAILED ? SafeOperationalData::failureSummary() : null,
            ]);

            return [
                'processed' => $processed,
                'created' => $created,
                'updated' => $updated,
                'errored' => $errored,
            ];
        } catch (\Throwable $error) {
            $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, SafeOperationalData::failureSummary());
            $connection->update([
                'last_error' => SafeOperationalData::failureSummary(),
            ]);

            throw $error;
        }
    }

    /**
     * Refresh posture snapshots for all computers already linked to Microsoft Intune.
     *
     * @return array{updated: int, not_found: int}
     */
    public function syncLinked(IntegrationProviderConnection $connection): array
    {
        $syncLog = IntegrationSyncLog::query()->create([
            'provider' => 'intune',
            'action' => 'sync_linked_devices',
            'status' => IntegrationSyncLog::STATUS_STARTED,
            'started_at' => now(),
        ]);

        try {
            $summary = $this->visibilitySync->sync();
            $processed = (int) ($summary['updated'] ?? 0) + (int) ($summary['not_found'] ?? 0);
            $syncLog->update([
                'items_processed' => $processed,
                'items_updated' => (int) ($summary['updated'] ?? 0),
                'items_errored' => (int) ($summary['not_found'] ?? 0),
            ]);
            $syncLog->markCompleted(
                ($summary['not_found'] ?? 0) > 0 && ($summary['updated'] ?? 0) > 0
                    ? IntegrationSyncLog::STATUS_PARTIAL
                    : IntegrationSyncLog::STATUS_SUCCESS,
            );
            $connection->update([
                'last_synced_at' => now(),
                'last_error' => null,
            ]);

            return [
                'updated' => (int) ($summary['updated'] ?? 0),
                'not_found' => (int) ($summary['not_found'] ?? 0),
            ];
        } catch (\Throwable $error) {
            $syncLog->markCompleted(IntegrationSyncLog::STATUS_FAILED, SafeOperationalData::failureSummary());
            $connection->update([
                'last_error' => SafeOperationalData::failureSummary(),
            ]);

            throw $error;
        }
    }

    /** @return list<array<string, mixed>> */
    private function fetchWindowsSnapshots(IntegrationProviderConnection $connection): array
    {
        return collect($this->client->devicesForConnection($connection))
            ->filter(static function (array $record): bool {
                $os = strtolower(trim((string) ($record['operatingSystem'] ?? '')));

                return str_contains($os, 'windows');
            })
            ->sortBy(fn (array $record): string => strtolower($this->deviceName($record, (string) ($record['id'] ?? ''))))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $snapshot */
    private function deviceName(array $snapshot, string $managedId): string
    {
        $name = $this->nullableTrimmed($snapshot['deviceName'] ?? null, 180);

        return $name ?? 'Windows computer '.substr($managedId, 0, 8);
    }

    /** @param array<string, mixed> $snapshot */
    private function inferCategory(array $snapshot): string
    {
        $haystack = strtolower(trim(implode(' ', [
            (string) ($snapshot['operatingSystem'] ?? ''),
            (string) ($snapshot['model'] ?? ''),
        ])));

        return str_contains($haystack, 'server') ? 'server' : 'endpoint';
    }

    /** @param array<string, mixed> $snapshot */
    private function inferHealthStatus(array $snapshot): HealthStatus
    {
        $compliance = strtolower(trim((string) ($snapshot['complianceState'] ?? '')));

        return match ($compliance) {
            'noncompliant' => HealthStatus::Warning,
            'compliant' => HealthStatus::Healthy,
            default => HealthStatus::Unknown,
        };
    }

    private function nullableTrimmed(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $maxLength);
    }
}
