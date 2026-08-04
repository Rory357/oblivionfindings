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
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\SiteRoom;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Projects bounded Milesight inventory fields into the canonical Device registry.
 *
 * Provider payloads are never stored wholesale. Identity reconciliation remains
 * fail-closed and Site-scoped through CanonicalIntegrationDeviceResolver.
 */
final class MilesightOperationalBridgeService
{
    private const MONITORING_PROFILE_NAME = 'Milesight provider status';

    /**
     * @return array{device: Device, created: bool}
     */
    public function syncInventoryDevice(IntegrationSiteConfig $siteConfig, array $payload): array
    {
        $providerEntityId = $this->string($payload['deviceId'] ?? null);
        if ($providerEntityId === null || Str::length($providerEntityId) > 255) {
            throw new \InvalidArgumentException('Milesight payload has no valid device id.');
        }

        $identity = [
            'serial' => $this->string($payload['sn'] ?? null),
            'mac' => $this->string($payload['mac'] ?? $payload['wlanMac'] ?? null),
        ];
        $device = app(CanonicalIntegrationDeviceResolver::class)->resolveInventory(
            $siteConfig,
            'milesight',
            $providerEntityId,
            $identity,
        ) ?? new Device;
        $created = ! $device->exists;
        [$domain, $category, $subcategory] = $this->taxonomy($payload);
        $status = $this->status($payload['connectStatus'] ?? null);
        $lastSeenAt = $this->timestamp($payload['lastUpdateTime'] ?? null);
        $battery = $this->battery($payload['electricity'] ?? null);
        $applicationId = $this->string(data_get($payload, 'application.applicationId'));
        $applicationName = $this->string(data_get($payload, 'application.applicationName'));
        if ($applicationId !== null && Str::length($applicationId) > 255) {
            throw new \InvalidArgumentException('Milesight application identity is invalid.');
        }
        $externalRef = is_array($device->external_ref) ? $device->external_ref : [];
        $meta = is_array($device->meta) ? $device->meta : [];

        DB::transaction(function () use (
            $device,
            $siteConfig,
            $payload,
            $providerEntityId,
            $domain,
            $category,
            $subcategory,
            $status,
            $lastSeenAt,
            $battery,
            $applicationId,
            $applicationName,
            $externalRef,
            $meta,
        ): void {
            $device->fill([
                'name' => $this->deviceName($payload, $providerEntityId),
                'domain' => $domain,
                'category' => $category,
                'subcategory' => $subcategory,
                'manufacturer' => 'Milesight',
                'model' => $this->bounded($payload['model'] ?? null) ?? $device->model,
                'serial_number' => $this->bounded($payload['sn'] ?? null) ?? $device->serial_number,
                'mac_address' => $this->bounded($payload['mac'] ?? $payload['wlanMac'] ?? null) ?? $device->mac_address,
                'imei' => $this->bounded($payload['imei'] ?? null) ?? $device->imei,
                'firmware_version' => $this->bounded($payload['firmwareVersion'] ?? null) ?? $device->firmware_version,
                'status' => $status,
                'health_status' => $this->health($status),
                'last_seen_at' => $lastSeenAt ?? $device->last_seen_at,
                'battery_level' => $battery ?? $device->battery_level,
                'battery_updated_at' => $battery !== null ? ($lastSeenAt ?? now()) : $device->battery_updated_at,
                'provider' => 'milesight',
                'external_ref' => array_merge($externalRef, [
                    'provider' => 'milesight',
                    'provider_entity_id' => $providerEntityId,
                    'provider_type' => $this->bounded($payload['deviceType'] ?? null),
                    'application_id' => $applicationId,
                ]),
                'meta' => array_merge($meta, [
                    'application_name' => $applicationName,
                    'hardware_version' => $this->bounded($payload['hardwareVersion'] ?? null),
                    'license_status' => $this->bounded($payload['licenseStatus'] ?? null),
                ]),
            ]);
            $device->save();

            $this->ensureSitePlacement($device, (int) $siteConfig->site_id);
            $this->ensureProviderMonitor($device, $providerEntityId);
        });

        return ['device' => $device->fresh(), 'created' => $created];
    }

    public function monitorTargetFor(string $providerEntityId): string
    {
        $providerEntityId = trim($providerEntityId);
        if ($providerEntityId === '' || Str::length($providerEntityId) > 255) {
            throw new \InvalidArgumentException('Milesight monitor identity is invalid.');
        }

        return 'provider:milesight:'.hash('sha256', $providerEntityId);
    }

    private function ensureProviderMonitor(Device $device, string $providerEntityId): void
    {
        $target = $this->monitorTargetFor($providerEntityId);
        $profile = $this->monitoringProfile();

        Device::query()->lockForUpdate()->findOrFail($device->id);
        $matches = Monitor::query()
            ->where('device_id', $device->id)
            ->where('kind', MonitorKind::Provider->value)
            ->where('target', $target)
            ->lockForUpdate()
            ->get();

        if ($matches->count() > 1) {
            throw new \RuntimeException('Milesight provider monitor identity is ambiguous.');
        }

        $existing = $matches->first();
        if ($existing !== null) {
            $config = is_array($existing->config) ? $existing->config : [];
            if (($config['provider'] ?? null) !== 'milesight'
                || ($config['collection'] ?? null) !== 'device_status') {
                throw new \RuntimeException('Milesight provider monitor contract is inconsistent.');
            }

            return;
        }

        Monitor::query()->create([
            'device_id' => $device->id,
            'profile_id' => $profile->id,
            'kind' => MonitorKind::Provider,
            'name' => 'Milesight connectivity',
            'target' => $target,
            'config' => [
                'provider' => 'milesight',
                'collection' => 'device_status',
            ],
            'current_state' => MonitorState::Unknown,
            'effective_state' => MonitorState::Unknown,
            'affects_availability' => true,
            'is_enabled' => true,
        ]);
    }

    private function monitoringProfile(): MonitoringProfile
    {
        try {
            return MonitoringProfile::query()->firstOrCreate(
                ['name' => self::MONITORING_PROFILE_NAME],
                [
                    'description' => 'Current Milesight provider connectivity with bounded freshness and confirmation policy.',
                    'interval_seconds' => 300,
                    'failure_confirmations' => 3,
                    'failure_duration_seconds' => 0,
                    'recovery_confirmations' => 2,
                    'recovery_duration_seconds' => 0,
                    'stale_after_seconds' => 900,
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

    private function ensureSitePlacement(Device $device, int $siteId): void
    {
        $active = $device->assignments()->active()->latest('id')->first();
        if ($active?->assignable_type === DeviceAssignment::TARGET_SITE
            && (int) $active->assignable_id === $siteId) {
            return;
        }

        if ($active?->assignable_type === DeviceAssignment::TARGET_ROOM) {
            $roomSiteId = SiteRoom::query()->whereKey($active->assignable_id)->value('site_id');
            if ((int) $roomSiteId === $siteId) {
                return;
            }
        }

        if ($active !== null) {
            // The resolver should already have rejected this. Keep a second
            // fail-closed guard beside the mutation boundary.
            throw new \RuntimeException('Milesight device belongs to another Site and requires reconciliation.');
        }

        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $siteId,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now(),
        ]);
    }

    /** @return array{string, string, string|null} */
    private function taxonomy(array $payload): array
    {
        if (strtoupper((string) ($payload['deviceType'] ?? '')) === 'GATEWAY') {
            return ['it_infrastructure', 'network', 'lte_gateway'];
        }

        $model = strtolower(implode(' ', array_filter([
            $this->string($payload['model'] ?? null),
            $this->string($payload['name'] ?? null),
            $this->string($payload['description'] ?? null),
        ])));

        return match (true) {
            str_contains($model, 'fall') => ['iot_healthcare', 'fall_detection', 'room_fall_sensor'],
            str_contains($model, 'bed') => ['iot_healthcare', 'bed_sensor', 'pressure_mat'],
            str_contains($model, 'panic'), str_contains($model, 'button') => ['iot_healthcare', 'nurse_call', 'nurse_pendant'],
            str_contains($model, 'door'), str_contains($model, 'contact') => ['iot_healthcare', 'occupancy', 'door_contact'],
            str_contains($model, 'occupancy'), str_contains($model, 'pir') => ['iot_healthcare', 'occupancy', 'pir_occupancy'],
            str_contains($model, 'leak'), str_contains($model, 'water') => ['facilities', 'leak_detection', 'water_sensor'],
            str_contains($model, 'freezer') => ['facilities', 'cold_chain', 'freezer_sensor'],
            str_contains($model, 'fridge'), str_contains($model, 'cold chain') => ['facilities', 'cold_chain', 'fridge_sensor'],
            str_contains($model, 'co2'), str_contains($model, 'air quality') => ['iot_healthcare', 'environmental', 'air_quality'],
            str_contains($model, 'humidity') => ['iot_healthcare', 'environmental', 'humidity'],
            default => ['iot_healthcare', 'environmental', 'temperature'],
        };
    }

    private function status(mixed $value): DeviceStatus
    {
        return match (strtoupper(trim((string) $value))) {
            'ONLINE' => DeviceStatus::Active,
            'OFFLINE', 'DISCONNECT', 'DISCONNECTED' => DeviceStatus::Offline,
            default => DeviceStatus::Degraded,
        };
    }

    private function health(DeviceStatus $status): HealthStatus
    {
        return match ($status) {
            DeviceStatus::Active => HealthStatus::Healthy,
            DeviceStatus::Offline => HealthStatus::Critical,
            default => HealthStatus::Warning,
        };
    }

    private function deviceName(array $payload, string $providerEntityId): string
    {
        return $this->bounded($payload['name'] ?? null)
            ?? $this->bounded($payload['model'] ?? null)
            ?? 'Milesight device '.strtoupper(substr($providerEntityId, -6));
    }

    private function battery(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $timestamp = (int) $value;

                return Carbon::createFromTimestamp($timestamp > 1_000_000_000_000 ? $timestamp / 1000 : $timestamp);
            }

            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function bounded(mixed $value, int $limit = 255): ?string
    {
        $value = $this->string($value);

        return $value === null ? null : Str::limit($value, $limit, '');
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
