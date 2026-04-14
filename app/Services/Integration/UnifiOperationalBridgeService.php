<?php

namespace App\Services\Integration;

use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\LocationHardware;
use App\Models\SiteRoom;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

        $device = $this->findCanonicalDevice($siteConfig, $providerEntityId, $payload) ?? new Device();
        $created = !$device->exists;

        $externalRef = is_array($device->external_ref) ? $device->external_ref : [];
        $meta = is_array($device->meta) ? $device->meta : [];
        $productLine = strtolower((string) ($payload['productLine'] ?? ''));

        $device->fill([
            'tenant_id' => $siteConfig->tenant_id,
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

        $shadow = $this->upsertLegacyShadow(
            $device,
            $siteConfig->site_id,
            $legacyCategory,
            $payload,
            $roomId,
        );

        if ($device->legacy_location_hardware_id !== $shadow->id) {
            $device->forceFill(['legacy_location_hardware_id' => $shadow->id])->save();
        }

        return [
            'device' => $device->fresh(),
            'created' => $created,
        ];
    }

    public function syncRoomAssignment(Device $device, ?SiteRoom $room, ?int $userId = null): DeviceAssignment
    {
        $siteId = $room?->site_id ?? $this->resolveSiteId($device);

        if ($siteId === null) {
            throw new \RuntimeException('UniFi device does not have a site context for room assignment.');
        }

        $targetType = $room ? DeviceAssignment::TARGET_ROOM : DeviceAssignment::TARGET_SITE;
        $targetId = $room?->id ?? $siteId;
        $active = $device->assignments()->active()->latest('id')->first();

        if ($active && $active->assignable_type === $targetType && $active->assignable_id === $targetId) {
            $this->syncLegacyShadowPlacement($device, $siteId, $room?->id);

            return $active;
        }

        $assignment = $this->replaceActiveAssignment($device, $targetType, $targetId, $userId);
        $this->syncLegacyShadowPlacement($device, $siteId, $room?->id);

        return $assignment;
    }

    public function applyHealthUpdate(IntegrationSiteConfig $siteConfig, array $entry): bool
    {
        $device = $this->resolveCanonicalDeviceForHealth($siteConfig, $entry);
        $legacyShadow = $this->resolveLegacyShadowForHealth($siteConfig, $entry, $device);
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

        if ($legacyShadow) {
            $legacyShadow->fill([
                'status' => $device ? $this->mapDeviceToLegacyStatus($device) : $this->mapLegacyStatus($entry['status'] ?? null),
                'last_seen_at' => $lastSeenAt ?? $legacyShadow->last_seen_at,
            ]);
            $legacyShadow->save();
        }

        return $device !== null || $legacyShadow !== null;
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
            DeviceAssignment::query()
                ->where('device_id', $device->id)
                ->whereNull('released_at')
                ->update([
                    'released_at' => now(),
                    'released_by_user_id' => $userId,
                    'updated_at' => now(),
                ]);

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

    private function syncLegacyShadowPlacement(Device $device, int $siteId, ?int $roomId): void
    {
        $legacyShadow = $this->findLegacyShadowForDevice($device);

        if (!$legacyShadow) {
            $legacyShadow = $this->upsertLegacyShadow(
                $device,
                $siteId,
                $this->resolveLegacyCategoryFromDevice($device),
                [],
                $roomId,
            );
        } else {
            $legacyShadow->fill([
                'site_id' => $siteId,
                'room_id' => $roomId,
                'status' => $this->mapDeviceToLegacyStatus($device),
                'last_seen_at' => $device->last_seen_at,
            ]);
            $legacyShadow->save();
        }

        if ($device->legacy_location_hardware_id !== $legacyShadow->id) {
            $device->forceFill(['legacy_location_hardware_id' => $legacyShadow->id])->save();
        }
    }

    private function upsertLegacyShadow(
        Device $device,
        int $siteId,
        string $legacyCategory,
        array $payload,
        ?int $roomId = null,
    ): LocationHardware {
        $providerEntityId = $this->resolveProviderEntityId($payload) ?? ($device->external_ref['provider_entity_id'] ?? null);
        $legacyShadow = $this->findLegacyShadowForDevice($device);

        if (!$legacyShadow && $providerEntityId) {
            $legacyShadow = LocationHardware::query()
                ->where('tenant_id', $device->tenant_id)
                ->where('provider', 'unifi')
                ->where('external_ref->provider_entity_id', $providerEntityId)
                ->latest('id')
                ->first();
        }

        $legacyShadow ??= new LocationHardware([
            'tenant_id' => $device->tenant_id,
            'provider' => 'unifi',
        ]);

        $externalRef = is_array($legacyShadow->external_ref) ? $legacyShadow->external_ref : [];
        $deviceExternalRef = is_array($device->external_ref) ? $device->external_ref : [];
        $meta = is_array($legacyShadow->meta) ? $legacyShadow->meta : [];
        $deviceMeta = is_array($device->meta) ? $device->meta : [];

        $legacyShadow->fill([
            'site_id' => $siteId,
            'room_id' => $roomId,
            'provider' => 'unifi',
            'category' => $legacyCategory,
            'name' => $device->name,
            'serial' => $device->serial_number,
            'mac' => $device->mac_address,
            'status' => $this->mapDeviceToLegacyStatus($device),
            'last_seen_at' => $device->last_seen_at,
            'external_ref' => array_merge($externalRef, $deviceExternalRef, [
                'provider' => 'unifi',
                'provider_entity_id' => $providerEntityId,
                'provider_type' => $payload['shortname'] ?? $deviceExternalRef['provider_type'] ?? null,
                'model' => $payload['model'] ?? $device->model,
                'firmware' => $payload['version'] ?? $payload['firmware_version'] ?? $device->firmware_version,
                'ip' => $payload['ip'] ?? $device->ip_address,
                'source_app' => $payload['productLine'] ?? $deviceExternalRef['source_app'] ?? null,
                'host_id' => $payload['_resolved_host_id'] ?? $deviceExternalRef['host_id'] ?? null,
            ]),
            'meta' => array_merge($meta, $deviceMeta, [
                'provider_type' => $payload['shortname'] ?? $deviceMeta['provider_type'] ?? null,
                'model_long' => $payload['model'] ?? $payload['model_long_name'] ?? $device->model,
                'product_line' => $payload['productLine'] ?? $deviceMeta['product_line'] ?? null,
                'firmware_status' => $payload['firmwareStatus'] ?? $deviceMeta['firmware_status'] ?? null,
                'uptime' => $payload['uptime'] ?? $deviceMeta['uptime'] ?? null,
                'experience_score' => $payload['satisfaction'] ?? $deviceMeta['experience_score'] ?? null,
                'host_id' => $payload['_resolved_host_id'] ?? $deviceMeta['host_id'] ?? null,
            ]),
            'notes' => $device->notes,
        ]);
        $legacyShadow->save();

        return $legacyShadow;
    }

    private function findCanonicalDevice(IntegrationSiteConfig $siteConfig, string $providerEntityId, array $payload): ?Device
    {
        $device = Device::query()
            ->forTenant($siteConfig->tenant_id)
            ->byProvider('unifi')
            ->where('external_ref->provider_entity_id', $providerEntityId)
            ->latest('id')
            ->first();

        if ($device) {
            return $device;
        }

        $legacyShadow = LocationHardware::query()
            ->where('tenant_id', $siteConfig->tenant_id)
            ->where('provider', 'unifi')
            ->where('external_ref->provider_entity_id', $providerEntityId)
            ->latest('id')
            ->first();

        if ($legacyShadow) {
            $device = Device::query()
                ->forTenant($siteConfig->tenant_id)
                ->where('legacy_location_hardware_id', $legacyShadow->id)
                ->latest('id')
                ->first();

            if ($device) {
                return $device;
            }
        }

        $serial = trim((string) ($payload['serial'] ?? ''));
        if ($serial !== '') {
            $serialMatch = Device::query()
                ->forTenant($siteConfig->tenant_id)
                ->byProvider('unifi')
                ->whereRaw('LOWER(serial_number) = ?', [strtolower($serial)])
                ->get();

            if ($serialMatch->count() === 1) {
                return $serialMatch->first();
            }
        }

        $mac = trim((string) ($payload['mac'] ?? ''));
        if ($mac !== '') {
            $macMatch = Device::query()
                ->forTenant($siteConfig->tenant_id)
                ->byProvider('unifi')
                ->whereRaw('LOWER(mac_address) = ?', [strtolower($mac)])
                ->get();

            if ($macMatch->count() === 1) {
                return $macMatch->first();
            }
        }

        return null;
    }

    private function resolveCanonicalDeviceForHealth(IntegrationSiteConfig $siteConfig, array $entry): ?Device
    {
        $deviceId = $entry['device_id'] ?? null;
        if ($deviceId) {
            return Device::query()
                ->forTenant($siteConfig->tenant_id)
                ->find($deviceId);
        }

        $providerEntityId = isset($entry['provider_entity_id']) ? trim((string) $entry['provider_entity_id']) : '';
        if ($providerEntityId !== '') {
            return Device::query()
                ->forTenant($siteConfig->tenant_id)
                ->byProvider('unifi')
                ->where('external_ref->provider_entity_id', $providerEntityId)
                ->latest('id')
                ->first();
        }

        $hardwareId = $entry['hardware_id'] ?? null;
        if ($hardwareId) {
            return Device::query()
                ->forTenant($siteConfig->tenant_id)
                ->where('legacy_location_hardware_id', $hardwareId)
                ->latest('id')
                ->first();
        }

        return null;
    }

    private function resolveLegacyShadowForHealth(
        IntegrationSiteConfig $siteConfig,
        array $entry,
        ?Device $device = null,
    ): ?LocationHardware {
        if ($device) {
            $shadow = $this->findLegacyShadowForDevice($device);
            if ($shadow) {
                return $shadow;
            }
        }

        $hardwareId = $entry['hardware_id'] ?? null;
        if ($hardwareId) {
            return LocationHardware::query()
                ->where('tenant_id', $siteConfig->tenant_id)
                ->find($hardwareId);
        }

        $providerEntityId = isset($entry['provider_entity_id']) ? trim((string) $entry['provider_entity_id']) : '';
        if ($providerEntityId !== '') {
            return LocationHardware::query()
                ->where('tenant_id', $siteConfig->tenant_id)
                ->where('provider', 'unifi')
                ->where('external_ref->provider_entity_id', $providerEntityId)
                ->latest('id')
                ->first();
        }

        return null;
    }

    private function findLegacyShadowForDevice(Device $device): ?LocationHardware
    {
        if ($device->legacy_location_hardware_id) {
            $shadow = LocationHardware::find($device->legacy_location_hardware_id);
            if ($shadow) {
                return $shadow;
            }
        }

        $providerEntityId = $device->external_ref['provider_entity_id'] ?? null;
        if ($providerEntityId) {
            return LocationHardware::query()
                ->where('tenant_id', $device->tenant_id)
                ->where('provider', 'unifi')
                ->where('external_ref->provider_entity_id', $providerEntityId)
                ->latest('id')
                ->first();
        }

        return null;
    }

    public function resolveSiteId(Device $device): ?int
    {
        $active = $device->assignments()->active()->latest('id')->first();

        if ($active) {
            if ($active->assignable_type === DeviceAssignment::TARGET_SITE) {
                return $active->assignable_id;
            }

            if ($active->assignable_type === DeviceAssignment::TARGET_ROOM) {
                return SiteRoom::query()
                    ->whereKey($active->assignable_id)
                    ->value('site_id');
            }
        }

        return $this->findLegacyShadowForDevice($device)?->site_id;
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

    private function resolveLegacyCategoryFromDevice(Device $device): string
    {
        return match ($device->category) {
            'network' => match ($device->subcategory) {
                'router' => LocationHardware::CATEGORY_GATEWAY,
                'switch' => LocationHardware::CATEGORY_SWITCH,
                'wireless_ap' => LocationHardware::CATEGORY_AP,
                default => LocationHardware::CATEGORY_OTHER,
            },
            'cctv' => $device->subcategory === 'nvr'
                ? LocationHardware::CATEGORY_NVR
                : LocationHardware::CATEGORY_CAMERA,
            'access_control' => LocationHardware::CATEGORY_DOOR,
            'environmental' => LocationHardware::CATEGORY_SENSOR,
            'server' => LocationHardware::CATEGORY_AI,
            default => LocationHardware::CATEGORY_OTHER,
        };
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

    private function mapDeviceToLegacyStatus(Device $device): string
    {
        return match ($device->status) {
            DeviceStatus::Active => LocationHardware::STATUS_ONLINE,
            DeviceStatus::Offline => LocationHardware::STATUS_OFFLINE,
            DeviceStatus::Decommissioned, DeviceStatus::Lost => LocationHardware::STATUS_RETIRED,
            default => LocationHardware::STATUS_UNKNOWN,
        };
    }

    private function mapLegacyStatus(mixed $status): string
    {
        if (is_string($status)) {
            return match (strtolower(trim($status))) {
                'active', 'online', 'connected', 'up' => LocationHardware::STATUS_ONLINE,
                'offline', 'disconnected', 'down' => LocationHardware::STATUS_OFFLINE,
                'retired', 'decommissioned', 'lost' => LocationHardware::STATUS_RETIRED,
                default => LocationHardware::STATUS_UNKNOWN,
            };
        }

        return match ($status) {
            1 => LocationHardware::STATUS_ONLINE,
            0 => LocationHardware::STATUS_OFFLINE,
            default => LocationHardware::STATUS_UNKNOWN,
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
