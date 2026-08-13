<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Models\Asset;
use App\Models\AssetTelemetrySnapshot;
use App\Models\AssetTracker;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\Site;
use App\Services\ConsentValidationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class FleetDeviceRuntimeService
{
    public function __construct(
        private readonly PersonalTrackingPrivacyService $trackingPrivacy,
    ) {}

    public function recentSnapshotsForDevice(
        Device $device,
        int $limit = 20,
        ?Carbon $notBefore = null,
    ): Collection {
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
            ->when($notBefore, fn (Builder $query, Carbon $cutoff) => $query
                ->where('occurred_at', '>=', $cutoff))
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
        return DB::transaction(function () use ($tracker): Device {
            $lockedTracker = AssetTracker::query()
                ->lockForUpdate()
                ->findOrFail($tracker->id);
            $device = $this->resolveCanonicalDevice($lockedTracker->vendor, [
                'device_uid' => $lockedTracker->device_uid,
                'imei' => $lockedTracker->imei,
                'serial_number' => $lockedTracker->serial_number,
            ], $lockedTracker);

            if ($device) {
                $device = Device::query()->lockForUpdate()->findOrFail($device->id);
                $device->fill([
                    'legacy_asset_tracker_id' => $device->legacy_asset_tracker_id ?: $lockedTracker->id,
                    'provider' => $device->provider ?: $lockedTracker->vendor,
                    'manufacturer' => $device->manufacturer ?: $lockedTracker->vendor,
                    'imei' => $device->imei ?: $lockedTracker->imei,
                    'serial_number' => $device->serial_number ?: $lockedTracker->serial_number,
                    'last_seen_at' => $lockedTracker->last_seen_at ?: $device->last_seen_at,
                    'external_ref' => $device->external_ref ?: $lockedTracker->vendor_metadata,
                ]);
                $device->save();
            } else {
                $device = Device::create([
                    'device_uid' => $lockedTracker->device_uid,
                    'name' => "Tracker {$lockedTracker->device_uid}",
                    'domain' => 'tracking',
                    'category' => 'vehicle_tracker',
                    'subcategory' => 'hardwired_gps',
                    'manufacturer' => $lockedTracker->vendor,
                    'imei' => $lockedTracker->imei,
                    'serial_number' => $lockedTracker->serial_number,
                    'status' => $this->mapTrackerStatus($lockedTracker->status),
                    'last_seen_at' => $lockedTracker->last_seen_at,
                    'provider' => $lockedTracker->vendor,
                    'external_ref' => $lockedTracker->vendor_metadata,
                    'legacy_asset_tracker_id' => $lockedTracker->id,
                ]);
            }

            $this->ensureCanonicalAssetLink($device, $lockedTracker);

            return $device->fresh();
        }, 3);
    }

    public function resolveConsentContext(Device $device): array
    {
        $device->loadMissing([
            'activeAssetLinks.asset.client:id,first_name,last_name',
            'legacyAssetTracker.asset.client:id,first_name,last_name',
            'legacyAssetTracker.consent.consentType',
            'legacyAssetTracker.consent.givenBy:id,name',
        ]);

        $assignment = DeviceAssignment::query()
            ->with(['consent.consentType', 'consent.givenBy:id,name'])
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

        if (! $client) {
            $client = $asset?->client;
        }

        $clientConsent = $client
            ? ConsentValidationService::latestValidTrackingConsentForClient($client)
            : null;
        $usableConsent = match (true) {
            $assignment !== null => $client
                && $this->trackingPrivacy->assignmentAuthorisesClient($assignment, $client)
                ? $assignmentConsent
                : null,
            $tracker?->consent_id !== null => $this->usableTrackingConsent($trackerConsent, $client),
            default => $this->usableTrackingConsent($clientConsent, $client),
        };

        return [
            'assignment' => $assignment,
            'tracker' => $tracker,
            'asset' => $asset,
            'client' => $client,
            'assignment_consent' => $assignmentConsent,
            'tracker_consent' => $trackerConsent,
            'client_consent' => $clientConsent,
            'consent' => $usableConsent,
        ];
    }

    public function mapConsentStatus(?ClientConsent $consent): string
    {
        if (! $consent) {
            return 'pending';
        }

        if ($consent->status === 'withdrawn' || $consent->withdrawn_at) {
            return 'revoked';
        }

        if (ConsentValidationService::isValidTrackingConsent($consent, $consent->client_id)) {
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

    private function ensureCanonicalAssetLink(Device $device, AssetTracker $tracker): void
    {
        if (! is_numeric($tracker->asset_id)) {
            return;
        }

        $asset = Asset::query()->lockForUpdate()->findOrFail((int) $tracker->asset_id);
        $siteIds = collect([$asset->site_id, $asset->home_site_id]);
        if (is_numeric($asset->client_id)) {
            $siteIds->push(Client::query()->whereKey($asset->client_id)->value('site_id'));
        }
        $siteIds = $siteIds
            ->filter(fn (mixed $siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
            ->map(fn (mixed $siteId): int => (int) $siteId)
            ->unique()
            ->values();
        if ($siteIds->count() !== 1 || ! Site::query()
            ->whereKey($siteIds->first())
            ->where('is_active', true)
            ->where(fn (Builder $query): Builder => $query->whereNull('archived')->orWhere('archived', false))
            ->exists()) {
            throw new UnexpectedValueException('Tracker Asset must resolve to one canonical active Site.');
        }

        $activeLinks = DeviceAssetLink::query()
            ->active()
            ->where('device_id', $device->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($activeLinks->count() > 1) {
            throw new UnexpectedValueException('Canonical Device has conflicting active Asset links.');
        }
        $activeLink = $activeLinks->first();
        if ($activeLink && (int) $activeLink->asset_id !== (int) $asset->id) {
            throw new UnexpectedValueException('Canonical Device is already linked to a different Asset.');
        }
        if ($activeLink) {
            return;
        }

        DeviceAssetLink::query()->create([
            'device_id' => $device->id,
            'asset_id' => $asset->id,
            'link_type' => 'installed_in',
            'linked_at' => $tracker->paired_at ?? now(),
            'linked_by_user_id' => null,
            'notes' => 'Canonical ownership recovered from retained tracker lineage.',
        ]);
    }

    private function normalizeIdentifier(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function usableTrackingConsent(?ClientConsent $consent, ?Client $client): ?ClientConsent
    {
        if (! $consent || ! $client || (int) $consent->client_id !== (int) $client->id) {
            return null;
        }

        return ConsentValidationService::isValidTrackingConsent($consent, $client)
            ? $consent
            : null;
    }

    private function mapTrackerStatus(?string $status): string
    {
        return match ($status) {
            'paired' => 'active',
            'suspended' => 'offline',
            'unpaired' => 'in_stock',
            default => 'in_stock',
        };
    }
}
