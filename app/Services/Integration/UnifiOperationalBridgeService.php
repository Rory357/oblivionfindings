<?php

namespace App\Services\Integration;

use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\LocationHardware;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Services\Sites\SiteTypePlanPinStatusService;
use App\Support\LegacyStorageContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UnifiOperationalBridgeService
{
    /**
     * @return array{device: Device, created: bool}
     */
    public function syncInventoryDevice(IntegrationSiteConfig $siteConfig, array $payload): array
    {
        $providerEntityId = $this->resolveProviderEntityId($payload);

        if ($providerEntityId === null) {
            throw new \InvalidArgumentException('UniFi payload is missing a provider entity id.');
        }

        $legacyCategory = $this->resolveLegacyCategory($payload);
        [$domain, $category, $subcategory] = $this->mapLegacyCategoryToCanonical($legacyCategory);
        $status = $this->mapCanonicalStatus($payload['status'] ?? $payload['state'] ?? null);
        $lastSeenAt = $this->parseTimestamp(
            $payload['lastSeen']
                ?? $payload['last_seen']
                ?? $payload['startupTime']
                ?? $payload['adoptionTime']
                ?? null
        );

        $device = $this->findCanonicalDevice($siteConfig, $providerEntityId, $payload) ?? new Device;
        $created = ! $device->exists;

        $externalRef = is_array($device->external_ref) ? $device->external_ref : [];
        $meta = is_array($device->meta) ? $device->meta : [];
        $productLine = strtolower((string) ($payload['productLine'] ?? ''));

        $device->fill([
            'tenant_id' => LegacyStorageContext::id(),
            'name' => $this->resolveDeviceName($payload),
            'domain' => $domain,
            'category' => $category,
            'subcategory' => $subcategory,
            'manufacturer' => 'Ubiquiti',
            'model' => $payload['model'] ?? $payload['model_long_name'] ?? null,
            'serial_number' => $payload['serial'] ?? null,
            'mac_address' => $payload['mac'] ?? null,
            'firmware_version' => $payload['version'] ?? $payload['firmware_version'] ?? null,
            'ip_address' => $payload['ip'] ?? null,
            'status' => $status,
            'health_status' => $this->mapHealthStatus($status),
            'last_seen_at' => $lastSeenAt ?? $device->last_seen_at,
            'provider' => 'unifi',
            'external_ref' => array_merge($externalRef, [
                'provider' => 'unifi',
                'provider_entity_id' => $providerEntityId,
                'provider_type' => $payload['shortname'] ?? $payload['productLine'] ?? null,
                'model' => $payload['model'] ?? null,
                'firmware' => $payload['version'] ?? $payload['firmware_version'] ?? null,
                'ip' => $payload['ip'] ?? null,
                'source_app' => $productLine !== '' ? $productLine : null,
                'host_id' => $payload['_resolved_host_id'] ?? null,
            ]),
            'meta' => array_merge($meta, [
                'provider_type' => $payload['shortname'] ?? null,
                'model_long' => $payload['model'] ?? $payload['model_long_name'] ?? null,
                'product_line' => $productLine !== '' ? $productLine : null,
                'firmware_status' => $payload['firmwareStatus'] ?? null,
                'uptime' => $payload['uptime'] ?? null,
                'experience_score' => $payload['satisfaction'] ?? null,
                'host_id' => $payload['_resolved_host_id'] ?? null,
            ]),
        ]);
        $device->save();

        $assignment = $this->ensureInventoryPlacement($device, $siteConfig->site_id);
        $roomId = $assignment->assignable_type === DeviceAssignment::TARGET_ROOM ? $assignment->assignable_id : null;

        // Phase 1 (PR P): legacy location_hardware shadow writes are disabled.
        // The canonical Device above is the source of truth; provenance now
        // flows through integration_events.canonical_device_id.
        $this->upsertLegacyShadow(
            $device,
            $siteConfig->site_id,
            $legacyCategory,
            $payload,
            $roomId,
        );

        return [
            'device' => $device->fresh(),
            'created' => $created,
        ];
    }

    public function syncRoomAssignment(
        Device $device,
        ?SiteRoom $room,
        ?int $userId,
        ?int $expectedSiteId,
    ): DeviceAssignment {
        return DB::transaction(function () use ($device, $room, $userId, $expectedSiteId) {
            $freshDevice = Device::query()
                ->byProvider('unifi')
                ->lockForUpdate()
                ->find($device->id);
            abort_unless($freshDevice, 404);

            $siteId = $this->resolveCurrentSiteId($freshDevice, lockForUpdate: true);
            abort_unless($siteId !== null, 404);
            abort_unless($expectedSiteId === null || $siteId === $expectedSiteId, 404);

            $freshRoom = null;
            if ($room !== null) {
                $freshRoom = $this->findScopedRoom($room->id, $siteId, lockForUpdate: true);
                abort_unless($freshRoom, 404);
            }

            $targetType = $freshRoom ? DeviceAssignment::TARGET_ROOM : DeviceAssignment::TARGET_SITE;
            $targetId = $freshRoom?->id ?? $siteId;
            $active = $freshDevice->assignments()->active()->latest('id')->lockForUpdate()->first();

            // Phase 1 (PR P): shadow placement sync is disabled. The canonical
            // DeviceAssignment above carries the authoritative site/room binding.
            if ($active && $active->assignable_type === $targetType && $active->assignable_id === $targetId) {
                return $active;
            }

            return $this->replaceActiveAssignment($freshDevice, $targetType, $targetId, $userId);
        });
    }

    public function applyHealthUpdate(IntegrationSiteConfig $siteConfig, array $entry): bool
    {
        // Phase 1 (PR P): health updates now only touch the canonical Device.
        // Legacy location_hardware shadow writes were removed because
        // provenance is carried by integration_events.canonical_device_id.
        $device = $this->resolveCanonicalDeviceForHealth($siteConfig, $entry);
        $lastSeenAt = $this->parseTimestamp($entry['last_seen_at'] ?? null);

        if ($device) {
            $status = $this->mapCanonicalStatus($entry['status'] ?? null);
            $device->fill([
                'status' => $status,
                'health_status' => $this->mapHealthStatus($status),
                'last_seen_at' => $lastSeenAt ?? $device->last_seen_at,
            ]);
            $device->save();
        }

        return $device !== null;
    }

    private function ensureInventoryPlacement(Device $device, int $siteId): DeviceAssignment
    {
        $active = $device->assignments()->active()->latest('id')->first();

        if ($active) {
            if ($active->assignable_type === DeviceAssignment::TARGET_SITE && $active->assignable_id === $siteId) {
                return $active;
            }

            if ($active->assignable_type === DeviceAssignment::TARGET_ROOM) {
                $roomSiteId = SiteRoom::query()
                    ->whereKey($active->assignable_id)
                    ->value('site_id');

                if ((int) $roomSiteId === $siteId) {
                    return $active;
                }
            }
        }

        return $this->replaceActiveAssignment($device, DeviceAssignment::TARGET_SITE, $siteId, null);
    }

    private function replaceActiveAssignment(Device $device, string $targetType, int $targetId, ?int $userId): DeviceAssignment
    {
        return DB::transaction(function () use ($device, $targetType, $targetId, $userId) {
            $releasedAt = now();
            $released = DeviceAssignment::query()
                ->where('device_id', $device->id)
                ->whereNull('released_at')
                ->update([
                    'released_at' => $releasedAt,
                    'released_by_user_id' => $userId,
                    'updated_at' => now(),
                ]);
            if ($released > 0) {
                app(SiteTypePlanPinStatusService::class)->markDevicePinsStale($device, 'assignment_replaced', $releasedAt);
            }

            return DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => $targetType,
                'assignable_id' => $targetId,
                'assignment_type' => AssignmentType::Permanent,
                'assigned_at' => now(),
                'assigned_by_user_id' => $userId,
            ]);
        });
    }

    /**
     * Phase 1 (PR P) no-op shadow writer.
     *
     * Historically this wrote to the legacy `location_hardware` table to keep
     * the UniFi compatibility shadow in sync with the canonical Device. That
     * responsibility is retired: provenance is now carried by
     * `integration_events.canonical_device_id` (seeded in migration
     * 2026_04_14_000004). This method is intentionally preserved as a stub so
     * the restructure can be staged across PRs without breaking existing call
     * signatures or tests. It emits a single debug log per device per request
     * to aid observability, and must not throw.
     *
     * @return null Always null — callers must not depend on a LocationHardware return value.
     */
    private function upsertLegacyShadow(
        Device $device,
        int $siteId,
        string $legacyCategory,
        array $payload,
        ?int $roomId = null,
    ): null {
        static $loggedDevices = [];

        $key = (int) ($device->id ?? 0);

        if ($key > 0 && ! isset($loggedDevices[$key])) {
            $loggedDevices[$key] = true;

            Log::debug('UnifiOperationalBridgeService::upsertLegacyShadow is a no-op (PR P Phase 1): legacy location_hardware writes disabled.', [
                'device_id' => $device->id,
                'tenant_id' => $device->tenant_id,
                'site_id' => $siteId,
                'room_id' => $roomId,
                'legacy_category' => $legacyCategory,
            ]);
        }

        return null;
    }

    private function findCanonicalDevice(IntegrationSiteConfig $siteConfig, string $providerEntityId, array $payload): ?Device
    {
        return app(CanonicalIntegrationDeviceResolver::class)->resolveInventory(
            $siteConfig,
            'unifi',
            $providerEntityId,
            $payload,
        );
    }

    private function resolveCanonicalDeviceForHealth(IntegrationSiteConfig $siteConfig, array $entry): ?Device
    {
        return app(CanonicalIntegrationDeviceResolver::class)->resolveHealth($siteConfig, 'unifi', $entry);
    }

    private function findLegacyShadowForDevice(Device $device, bool $lockForUpdate = false): ?LocationHardware
    {
        if ($device->legacy_location_hardware_id) {
            $query = LocationHardware::query()
                ->where('provider', 'unifi')
                ->whereKey($device->legacy_location_hardware_id);
            if ($lockForUpdate) {
                $query->lockForUpdate();
            }
            $shadow = $query->first();
            if ($shadow) {
                return $shadow;
            }
        }

        $providerEntityId = $device->external_ref['provider_entity_id'] ?? null;
        if ($providerEntityId) {
            $query = LocationHardware::query()
                ->where('provider', 'unifi')
                ->where('external_ref->provider_entity_id', $providerEntityId)
                ->latest('id');
            if ($lockForUpdate) {
                $query->lockForUpdate();
            }

            return $query->first();
        }

        return null;
    }

    public function resolveSiteId(Device $device): ?int
    {
        $freshDevice = Device::query()
            ->byProvider('unifi')
            ->find($device->id);

        if (! $freshDevice) {
            return null;
        }

        return $this->resolveCurrentSiteId($freshDevice);
    }

    private function resolveCurrentSiteId(
        Device $device,
        bool $lockForUpdate = false,
    ): ?int {
        $assignmentQuery = $device->assignments()->active()->latest('id');
        if ($lockForUpdate) {
            $assignmentQuery->lockForUpdate();
        }
        $active = $assignmentQuery->first();

        if ($active) {
            if ($active->assignable_type === DeviceAssignment::TARGET_SITE) {
                return $this->findScopedSiteId($active->assignable_id, $lockForUpdate);
            }

            if ($active->assignable_type === DeviceAssignment::TARGET_ROOM) {
                $room = $this->findScopedRoom($active->assignable_id, lockForUpdate: $lockForUpdate);

                return $room?->site_id;
            }

            return null;
        }

        $shadow = $this->findLegacyShadowForDevice($device, $lockForUpdate);

        return $shadow
            ? $this->findScopedSiteId($shadow->site_id, $lockForUpdate)
            : null;
    }

    private function findScopedRoom(
        int $roomId,
        ?int $siteId = null,
        bool $lockForUpdate = false,
    ): ?SiteRoom {
        $query = SiteRoom::query()
            ->when($siteId !== null, fn ($roomQuery) => $roomQuery->where('site_id', $siteId))
            ->whereKey($roomId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $room = $query->first();

        if (! $room || $this->findScopedSiteId($room->site_id, $lockForUpdate) === null) {
            return null;
        }

        return $room;
    }

    private function findScopedSiteId(int $siteId, bool $lockForUpdate = false): ?int
    {
        $query = Site::query()
            ->whereKey($siteId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->value('id');
    }

    private function resolveProviderEntityId(array $payload): ?string
    {
        $providerEntityId = trim((string) ($payload['id'] ?? $payload['_id'] ?? $payload['mac'] ?? ''));

        return $providerEntityId !== '' ? $providerEntityId : null;
    }

    private function resolveLegacyCategory(array $payload): string
    {
        $productLine = strtolower((string) ($payload['productLine'] ?? ''));
        $category = $this->mapDeviceTypeToLegacyCategory((string) ($payload['model'] ?? $payload['shortname'] ?? ''));

        if ($productLine === 'protect' && $category === LocationHardware::CATEGORY_OTHER) {
            return LocationHardware::CATEGORY_CAMERA;
        }

        return $category;
    }

    /**
     * @return array{string, string, string|null}
     */
    private function mapLegacyCategoryToCanonical(string $legacyCategory): array
    {
        return match ($legacyCategory) {
            LocationHardware::CATEGORY_GATEWAY => ['it_infrastructure', 'network', 'router'],
            LocationHardware::CATEGORY_SWITCH => ['it_infrastructure', 'network', 'switch'],
            LocationHardware::CATEGORY_AP => ['it_infrastructure', 'network', 'wireless_ap'],
            LocationHardware::CATEGORY_CAMERA => ['security', 'cctv', 'dome_camera'],
            LocationHardware::CATEGORY_DOOR => ['security', 'access_control', 'card_reader'],
            LocationHardware::CATEGORY_SENSOR => ['iot_healthcare', 'environmental', 'temperature'],
            LocationHardware::CATEGORY_NVR => ['security', 'cctv', 'nvr'],
            LocationHardware::CATEGORY_AI => ['it_infrastructure', 'server', 'physical_server'],
            default => ['facilities', 'building_safety', null],
        };
    }

    private function mapDeviceTypeToLegacyCategory(string $typeOrModel): string
    {
        $lower = strtolower($typeOrModel);

        return match (true) {
            str_starts_with($lower, 'udm'),
            str_starts_with($lower, 'uxg'),
            str_starts_with($lower, 'ucg') => LocationHardware::CATEGORY_GATEWAY,
            str_starts_with($lower, 'usw') => LocationHardware::CATEGORY_SWITCH,
            str_starts_with($lower, 'uap'),
            str_starts_with($lower, 'u6'),
            str_starts_with($lower, 'u7') => LocationHardware::CATEGORY_AP,
            str_starts_with($lower, 'uvc') => LocationHardware::CATEGORY_CAMERA,
            str_starts_with($lower, 'ua') => LocationHardware::CATEGORY_DOOR,
            str_starts_with($lower, 'unvr') => LocationHardware::CATEGORY_NVR,
            str_starts_with($lower, 'uai') => LocationHardware::CATEGORY_AI,
            default => LocationHardware::CATEGORY_OTHER,
        };
    }

    private function mapCanonicalStatus(mixed $state): DeviceStatus
    {
        if (is_string($state)) {
            return match (strtolower(trim($state))) {
                'active', 'online', 'connected', 'up' => DeviceStatus::Active,
                'offline', 'disconnected', 'down' => DeviceStatus::Offline,
                'degraded', 'warn', 'warning', 'unknown' => DeviceStatus::Degraded,
                'maintenance' => DeviceStatus::Maintenance,
                'decommissioned', 'retired' => DeviceStatus::Decommissioned,
                'in_stock' => DeviceStatus::InStock,
                'lost' => DeviceStatus::Lost,
                default => DeviceStatus::Degraded,
            };
        }

        return match ($state) {
            1 => DeviceStatus::Active,
            0 => DeviceStatus::Offline,
            default => DeviceStatus::Degraded,
        };
    }

    private function mapHealthStatus(DeviceStatus $status): HealthStatus
    {
        return match ($status) {
            DeviceStatus::Active => HealthStatus::Healthy,
            DeviceStatus::Degraded, DeviceStatus::Maintenance => HealthStatus::Warning,
            DeviceStatus::Offline => HealthStatus::Critical,
            default => HealthStatus::Unknown,
        };
    }

    private function resolveDeviceName(array $device): string
    {
        $name = trim((string) ($device['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $hostname = trim((string) ($device['hostname'] ?? ''));
        if ($hostname !== '') {
            return $hostname;
        }

        $modelLong = trim((string) ($device['model_long_name'] ?? ''));
        $model = trim((string) ($device['model'] ?? ''));
        $type = trim((string) ($device['type'] ?? ''));
        $base = $modelLong !== '' ? $modelLong : ($model !== '' ? $model : ($type !== '' ? strtoupper($type) : 'UniFi Device'));
        $suffix = $this->resolveDeviceSuffix($device);

        return $suffix ? "{$base} ({$suffix})" : $base;
    }

    private function resolveDeviceSuffix(array $device): string
    {
        $mac = preg_replace('/[^a-fA-F0-9]/', '', (string) ($device['mac'] ?? ''));
        if ($mac !== '') {
            return strtoupper(substr($mac, -4));
        }

        $serial = trim((string) ($device['serial'] ?? ''));
        if ($serial !== '') {
            return strtoupper(substr($serial, -4));
        }

        return '';
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_numeric($value)) {
            $numeric = (int) $value;

            if ($numeric > 1000000000000) {
                return Carbon::createFromTimestamp($numeric / 1000);
            }

            return Carbon::createFromTimestamp($numeric);
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
