<?php

namespace App\Services\Integration;

use App\Domain\Monitoring\Enums\MonitorKind;
use App\Domain\Monitoring\Enums\MonitorState;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use App\Domain\SecurityDevices\Services\DeviceFieldOwnershipService;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\LocationHardware;
use App\Models\Site;
use App\Models\SiteRoom;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UnifiOperationalBridgeService
{
    private const MONITORING_PROFILE_NAME = 'UniFi Network provider status';

    private const WAN_MONITORING_PROFILE_NAME = 'UniFi WAN performance';

    public function __construct(
        private readonly DeviceAssignmentService $deviceAssignments,
        private readonly DeviceFieldOwnershipService $fieldOwnership,
    ) {}

    /**
     * @return array{device: Device, created: bool}
     */
    public function syncInventoryDevice(IntegrationSiteConfig $siteConfig, array $payload): array
    {
        $providerEntityId = $this->resolveProviderEntityId($payload);

        if ($providerEntityId === null) {
            throw new \InvalidArgumentException('UniFi payload is missing a provider entity id.');
        }
        if (mb_strlen($providerEntityId) > 255) {
            throw new \InvalidArgumentException('UniFi provider identity is invalid.');
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

        $productLine = strtolower((string) ($payload['productLine'] ?? ''));
        $isNetworkDevice = $productLine === 'network'
            || ($domain === 'it_infrastructure' && $category === 'network');
        $sourceApp = $productLine !== '' ? $productLine : ($isNetworkDevice ? 'network' : null);

        $observed = array_filter([
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
            'last_seen_at' => $lastSeenAt,
            'provider' => 'unifi',
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $device = $this->fieldOwnership->applyProviderObservation(
            $device,
            'unifi',
            $observed,
            $lastSeenAt,
            providerAttributes: [
                'external_ref' => [
                    'provider' => 'unifi',
                    'provider_entity_id' => $providerEntityId,
                    'provider_type' => $payload['shortname'] ?? $payload['productLine'] ?? null,
                    'source_app' => $sourceApp,
                    'host_id' => $payload['_resolved_host_id'] ?? null,
                ],
                'meta' => [
                    'provider_type' => $payload['shortname'] ?? null,
                    'model_long' => $payload['model'] ?? $payload['model_long_name'] ?? null,
                    'product_line' => $productLine !== '' ? $productLine : null,
                    'firmware_status' => $payload['firmwareStatus'] ?? null,
                    'uptime' => $payload['uptime'] ?? null,
                    'experience_score' => $payload['satisfaction'] ?? null,
                    'host_id' => $payload['_resolved_host_id'] ?? null,
                ],
            ],
        );

        $assignment = $this->ensureInventoryPlacement($device, $siteConfig->site_id);
        $roomId = $assignment->assignable_type === DeviceAssignment::TARGET_ROOM ? $assignment->assignable_id : null;
        if ($isNetworkDevice) {
            $this->ensureProviderMonitor($device, $providerEntityId);
        }
        if ($category === 'network' && $subcategory === 'router') {
            $hostId = is_scalar($payload['_resolved_host_id'] ?? null)
                ? trim((string) $payload['_resolved_host_id'])
                : '';
            $externalSiteId = is_scalar($siteConfig->mapped_external_site_id)
                ? trim((string) $siteConfig->mapped_external_site_id)
                : '';
            if ($hostId !== '' && $externalSiteId !== '') {
                $this->ensureWanMonitor($device, $hostId, $externalSiteId);
            }
        }

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

    public function monitorTargetFor(string $providerEntityId): string
    {
        $providerEntityId = trim($providerEntityId);
        if ($providerEntityId === '' || mb_strlen($providerEntityId) > 255) {
            throw new \InvalidArgumentException('UniFi monitor identity is invalid.');
        }

        return 'provider:unifi:'.hash('sha256', $providerEntityId);
    }

    public function wanMonitorTargetFor(string $consoleId, string $externalSiteId): string
    {
        $consoleId = trim($consoleId);
        $externalSiteId = trim($externalSiteId);
        if ($consoleId === '' || mb_strlen($consoleId) > 255
            || $externalSiteId === '' || mb_strlen($externalSiteId) > 255) {
            throw new \InvalidArgumentException('UniFi WAN monitor identity is invalid.');
        }

        return 'provider:unifi:wan:'.hash('sha256', $consoleId.'|'.$externalSiteId);
    }

    private function ensureProviderMonitor(Device $device, string $providerEntityId): void
    {
        $target = $this->monitorTargetFor($providerEntityId);
        $profile = $this->monitoringProfile();

        DB::transaction(function () use ($device, $target, $profile): void {
            Device::query()->lockForUpdate()->findOrFail($device->id);
            $matches = Monitor::query()
                ->where('device_id', $device->id)
                ->where('kind', MonitorKind::Provider->value)
                ->where('target', $target)
                ->lockForUpdate()
                ->get();

            if ($matches->count() > 1) {
                throw new \RuntimeException('UniFi provider monitor identity is ambiguous.');
            }

            $existing = $matches->first();
            if ($existing !== null) {
                $config = is_array($existing->config) ? $existing->config : [];
                if (($config['provider'] ?? null) !== 'unifi'
                    || ($config['collection'] ?? null) !== 'device_status') {
                    throw new \RuntimeException('UniFi provider monitor contract is inconsistent.');
                }

                return;
            }

            Monitor::query()->create([
                'device_id' => $device->id,
                'profile_id' => $profile->id,
                'kind' => MonitorKind::Provider,
                'name' => 'UniFi connectivity and performance',
                'target' => $target,
                'config' => [
                    'provider' => 'unifi',
                    'collection' => 'device_status',
                ],
                'current_state' => MonitorState::Unknown,
                'effective_state' => MonitorState::Unknown,
                'affects_availability' => true,
                'is_enabled' => true,
            ]);
        }, 3);
    }

    private function monitoringProfile(): MonitoringProfile
    {
        try {
            return MonitoringProfile::query()->firstOrCreate(
                ['name' => self::MONITORING_PROFILE_NAME],
                [
                    'description' => 'Current UniFi Network connectivity and bounded performance statistics.',
                    'interval_seconds' => 60,
                    'failure_confirmations' => 3,
                    'failure_duration_seconds' => 0,
                    'recovery_confirmations' => 2,
                    'recovery_duration_seconds' => 0,
                    'stale_after_seconds' => 180,
                    'is_active' => true,
                ],
            );
        } catch (QueryException $exception) {
            $profile = MonitoringProfile::query()
                ->where('name', self::MONITORING_PROFILE_NAME)
                ->first();
            if ($profile === null) {
                throw $exception;
            }

            return $profile;
        }
    }

    private function ensureWanMonitor(Device $device, string $consoleId, string $externalSiteId): void
    {
        $target = $this->wanMonitorTargetFor($consoleId, $externalSiteId);
        $profile = $this->wanMonitoringProfile();

        DB::transaction(function () use ($device, $target, $profile): void {
            Device::query()->lockForUpdate()->findOrFail($device->id);
            $matches = Monitor::query()
                ->where('kind', MonitorKind::Provider->value)
                ->where('target', $target)
                ->lockForUpdate()
                ->get();
            if ($matches->count() > 1) {
                throw new \RuntimeException('UniFi WAN monitor identity is ambiguous.');
            }

            $existing = $matches->first();
            if ($existing !== null) {
                $config = is_array($existing->config) ? $existing->config : [];
                if (($config['provider'] ?? null) !== 'unifi'
                    || ($config['collection'] ?? null) !== 'isp_metrics') {
                    throw new \RuntimeException('UniFi WAN monitor contract is inconsistent.');
                }

                return;
            }

            Monitor::query()->create([
                'device_id' => $device->id,
                'profile_id' => $profile->id,
                'kind' => MonitorKind::Provider,
                'name' => 'UniFi WAN performance',
                'target' => $target,
                'config' => [
                    'provider' => 'unifi',
                    'collection' => 'isp_metrics',
                    'warning_uptime_percent' => 99,
                    'warning_packet_loss_percent' => 5,
                    'warning_average_latency_ms' => 250,
                    'failure_uptime_percent' => 1,
                    'failure_downtime_seconds' => 300,
                ],
                'current_state' => MonitorState::Unknown,
                'effective_state' => MonitorState::Unknown,
                'affects_availability' => true,
                'is_enabled' => true,
            ]);
        }, 3);
    }

    private function wanMonitoringProfile(): MonitoringProfile
    {
        try {
            return MonitoringProfile::query()->firstOrCreate(
                ['name' => self::WAN_MONITORING_PROFILE_NAME],
                [
                    'description' => 'Five-minute UniFi Site WAN uptime, loss, latency, and throughput.',
                    'interval_seconds' => 300,
                    'failure_confirmations' => 2,
                    'failure_duration_seconds' => 0,
                    'recovery_confirmations' => 2,
                    'recovery_duration_seconds' => 0,
                    'stale_after_seconds' => 900,
                    'is_active' => true,
                ],
            );
        } catch (QueryException $exception) {
            $profile = MonitoringProfile::query()
                ->where('name', self::WAN_MONITORING_PROFILE_NAME)
                ->first();
            if ($profile === null) {
                throw $exception;
            }

            return $profile;
        }
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
            $observed = array_filter([
                'status' => $status,
                'health_status' => $this->mapHealthStatus($status),
                'last_seen_at' => $lastSeenAt,
            ], fn (mixed $value): bool => $value !== null && $value !== '');
            $this->fieldOwnership->applyProviderObservation(
                $device,
                'unifi',
                $observed,
                $lastSeenAt,
            );
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
        return $this->deviceAssignments->assign(
            device: $device,
            assignableType: $targetType,
            assignableId: $targetId,
            assignedByUserId: $userId,
            assignmentType: AssignmentType::Permanent,
        );
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
