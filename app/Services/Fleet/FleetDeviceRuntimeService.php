<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AssetTelemetrySnapshot;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FleetDeviceRuntimeService
{
    public function recentSnapshotsForDevice(Device $device, int $limit = 20): Collection
    {
        return AssetTelemetrySnapshot::query()
            ->where(function (Builder $query) use ($device): void {
                $query->where('device_id', $device->id);

                if ($device->legacy_asset_tracker_id) {
                    $query->orWhere(function (Builder $fallback) use ($device): void {
                        $fallback->whereNull('device_id')
                            ->where('asset_tracker_id', $device->legacy_asset_tracker_id);
                    });
                }
            })
            ->latest('occurred_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function resolveCanonicalDevice(string $vendor, array $normalized, ?AssetTracker $tracker = null): ?Device
    {
        $vendor = trim($vendor);

        $imei = $this->normalizeIdentifier($normalized['imei'] ?? $tracker?->imei);
        if ($imei) {
            $match = $this->trackingDevices($vendor)
                ->where('imei', $imei)
                ->first();

            if ($match) {
                return $match;
            }
        }

        $serialNumber = $this->normalizeIdentifier($normalized['serial_number'] ?? $tracker?->serial_number);
        if ($serialNumber) {
            $match = $this->trackingDevices($vendor)
                ->whereRaw('LOWER(serial_number) = ?', [strtolower($serialNumber)])
                ->first();

            if ($match) {
                return $match;
            }
        }

        $trackerDeviceUid = $this->normalizeIdentifier($normalized['device_uid'] ?? $tracker?->device_uid);
        if ($trackerDeviceUid) {
            $match = $this->trackingDevices($vendor)
                ->where('device_uid', $trackerDeviceUid)
                ->first();

            if ($match) {
                return $match;
            }
        }

        if ($tracker) {
            return Device::query()
                ->where('legacy_asset_tracker_id', $tracker->id)
                ->first();
        }

        return null;
    }

    public function ensureCanonicalDeviceForTracker(AssetTracker $tracker): Device
    {
        $device = $this->resolveCanonicalDevice($tracker->vendor, [
            'device_uid' => $tracker->device_uid,
            'imei' => $tracker->imei,
            'serial_number' => $tracker->serial_number,
        ], $tracker);

        if ($device) {
            $device->fill([
                'legacy_asset_tracker_id' => $tracker->id,
                'provider' => $device->provider ?: $tracker->vendor,
                'manufacturer' => $device->manufacturer ?: $tracker->vendor,
                'imei' => $device->imei ?: $tracker->imei,
                'serial_number' => $device->serial_number ?: $tracker->serial_number,
                'last_seen_at' => $tracker->last_seen_at ?: $device->last_seen_at,
                'external_ref' => $device->external_ref ?: $tracker->vendor_metadata,
            ]);

            $device->save();

            return $device;
        }

        return Device::create([
            'tenant_id' => $this->resolveTenantIdForTracker($tracker),
            'name' => "Tracker {$tracker->device_uid}",
            'domain' => 'tracking',
            'category' => 'vehicle_tracker',
            'subcategory' => 'hardwired_gps',
            'manufacturer' => $tracker->vendor,
            'imei' => $tracker->imei,
            'serial_number' => $tracker->serial_number,
            'status' => $this->mapTrackerStatus($tracker->status),
            'last_seen_at' => $tracker->last_seen_at,
            'provider' => $tracker->vendor,
            'external_ref' => $tracker->vendor_metadata,
            'legacy_asset_tracker_id' => $tracker->id,
        ]);
    }

    public function resolveConsentContext(Device $device): array
    {
        $device->loadMissing([
            'activeAssetLinks.asset.client:id,first_name,last_name',
            'legacyAssetTracker.asset.client:id,first_name,last_name',
            'legacyAssetTracker.consent.givenBy:id,name',
        ]);

        $assignment = DeviceAssignment::query()
            ->with(['consent.givenBy:id,name'])
            ->where('device_id', $device->id)
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
            ->whereNull('released_at')
            ->latest('assigned_at')
            ->first();

        $tracker = $device->legacyAssetTracker;
        $assignmentConsent = $assignment?->consent;
        $trackerConsent = $tracker?->consent;

        $asset = $device->activeAssetLinks->first()?->asset ?? $tracker?->asset;
        $client = null;

        if ($assignment && $assignment->assignable_type === DeviceAssignment::TARGET_CLIENT) {
            $client = Client::query()->find($assignment->assignable_id);
        }

        if (!$client) {
            $client = $asset?->client;
        }

        return [
            'assignment' => $assignment,
            'tracker' => $tracker,
            'asset' => $asset,
            'client' => $client,
            'assignment_consent' => $assignmentConsent,
            'tracker_consent' => $trackerConsent,
            'consent' => $assignmentConsent ?? $trackerConsent,
        ];
    }

    public function mapConsentStatus(?ClientConsent $consent): string
    {
        if (!$consent) {
            return 'pending';
        }

        if ($consent->status === 'withdrawn' || $consent->withdrawn_at) {
            return 'revoked';
        }

        if ($consent->isValid()) {
            return 'consented';
        }

        if ($consent->isExpired()) {
            return 'expired';
        }

        return 'pending';
    }

    private function trackingDevices(string $vendor): Builder
    {
        return Device::query()
            ->where('domain', 'tracking')
            ->where('provider', $vendor);
    }

    private function normalizeIdentifier(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function resolveTenantIdForTracker(AssetTracker $tracker): int
    {
        $siteId = $tracker->asset?->site_id;

        if (!$siteId) {
            return 1;
        }

        return (int) (DB::table('sites')->where('id', $siteId)->value('tenant_id') ?? 1);
    }

    private function mapTrackerStatus(?string $status): string
    {
        return match ($status) {
            'paired' => 'active',
            'suspended' => 'offline',
            'unpaired' => 'in_stock',
            default => 'active',
        };
    }
}
