<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteRoom;
use Illuminate\Support\Collection;
use UnexpectedValueException;

final class CanonicalDeviceSiteResolver
{
    /** @var array<int, bool> */
    private array $activeSiteCache = [];

    public function resolve(int $deviceId): int
    {
        return $this->resolveDeviceSite($deviceId, true);
    }

    /**
     * Resolve canonical Site context for human workflows that may involve a
     * Device in maintenance, stock, lost or decommissioned state. Monitoring
     * execution continues to use resolve(), which accepts operational states.
     */
    public function resolveForContext(int $deviceId): int
    {
        return $this->resolveDeviceSite($deviceId, false);
    }

    /** Resolve a Device already loaded by a permission-scoped picker query. */
    public function resolveLoadedForContext(Device $device): int
    {
        $device->loadMissing(['assignments' => fn ($query) => $query
            ->active()
            ->where('assigned_at', '<=', now())
            ->orderBy('id'), 'activeAssetLinks' => fn ($query) => $query->orderBy('id')]);

        return $this->resolveLoadedDeviceSite($device);
    }

    private function resolveDeviceSite(int $deviceId, bool $operationalOnly): int
    {
        if ($deviceId < 1) {
            throw new UnexpectedValueException('Canonical Device reference is invalid.');
        }

        $device = Device::query()
            ->whereKey($deviceId)
            ->when($operationalOnly, fn ($query) => $query->whereIn('status', [
                DeviceStatus::Active->value,
                DeviceStatus::Degraded->value,
                DeviceStatus::Offline->value,
            ]))
            ->with(['assignments' => fn ($query) => $query
                ->active()
                ->where('assigned_at', '<=', now())
                ->orderBy('id'), 'activeAssetLinks' => fn ($query) => $query->orderBy('id')])
            ->first();

        if ($device === null) {
            throw new UnexpectedValueException('Canonical Device is unavailable.');
        }

        return $this->resolveLoadedDeviceSite($device);
    }

    private function resolveLoadedDeviceSite(Device $device): int
    {
        $provenanceSiteIds = $device->assignments
            ->map(fn (DeviceAssignment $assignment): Collection => Collection::make($this->siteIdsFor($assignment))
                ->filter(fn (mixed $candidate): bool => is_int($candidate) && $candidate > 0)
                ->unique()
                ->values())
            ->concat($device->activeAssetLinks
                ->map(fn (DeviceAssetLink $link): Collection => Collection::make($this->assetSiteIds((int) $link->asset_id))
                    ->filter(fn (mixed $candidate): bool => is_int($candidate) && $candidate > 0)
                    ->unique()
                    ->values()));

        if ($provenanceSiteIds->isEmpty()
            || $provenanceSiteIds->contains(fn (Collection $siteIds): bool => $siteIds->count() !== 1)) {
            throw new UnexpectedValueException('Device must resolve to one canonical active Site.');
        }

        $siteIds = $provenanceSiteIds->flatten()->unique()->values();
        if ($siteIds->count() !== 1) {
            throw new UnexpectedValueException('Device must resolve to one canonical active Site.');
        }

        $siteId = $siteIds->first();
        $siteIsActive = $this->activeSiteCache[$siteId] ??= Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('archived')->orWhere('archived', false))
            ->exists();

        if (! $siteIsActive) {
            throw new UnexpectedValueException('Canonical Site is unavailable.');
        }

        return $siteId;
    }

    /** @return list<int> */
    private function siteIdsFor(DeviceAssignment $assignment): array
    {
        return match ($assignment->assignable_type) {
            DeviceAssignment::TARGET_SITE => [(int) $assignment->assignable_id],
            DeviceAssignment::TARGET_ROOM => $this->oneSiteId(
                SiteRoom::query()->whereKey($assignment->assignable_id)->value('site_id'),
            ),
            DeviceAssignment::TARGET_CLIENT => $this->oneSiteId(
                Client::query()
                    ->whereKey($assignment->assignable_id)
                    ->where('status', 'active')
                    ->value('site_id'),
            ),
            DeviceAssignment::TARGET_STAFF => $this->staffSiteIds((int) $assignment->assignable_id),
            DeviceAssignment::TARGET_VEHICLE => $this->vehicleSiteIds((int) $assignment->assignable_id),
            default => [],
        };
    }

    /** @return list<int> */
    private function staffSiteIds(int $userId): array
    {
        return HrEmployeeProfile::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->whereNotNull('primary_site_id')
            ->pluck('primary_site_id')
            ->filter(fn (mixed $candidate): bool => is_numeric($candidate) && (int) $candidate > 0)
            ->map(fn (mixed $candidate): int => (int) $candidate)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function vehicleSiteIds(int $assetId): array
    {
        $asset = Asset::query()
            ->whereKey($assetId)
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(category) = ?', ['vehicle'])
                ->orWhereHas('categoryRef', fn ($category) => $category->whereRaw('LOWER(slug) = ?', ['vehicle'])))
            ->where('status', 'active')
            ->first(['site_id', 'home_site_id', 'client_id']);

        if ($asset === null) {
            return [];
        }

        $siteIds = Collection::make([$asset->site_id, $asset->home_site_id]);

        if ($asset->client_id !== null) {
            $client = Client::query()
                ->whereKey($asset->client_id)
                ->where('status', 'active')
                ->first(['site_id']);

            if ($client === null) {
                return [];
            }

            $siteIds->push($client->site_id);
        }

        return $siteIds
            ->filter(fn (mixed $candidate): bool => is_numeric($candidate) && (int) $candidate > 0)
            ->map(fn (mixed $candidate): int => (int) $candidate)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function assetSiteIds(int $assetId): array
    {
        $asset = Asset::query()
            ->whereKey($assetId)
            ->where('status', 'active')
            ->first(['site_id', 'home_site_id', 'client_id']);

        if ($asset === null) {
            return [];
        }

        $siteIds = Collection::make([$asset->site_id, $asset->home_site_id]);
        if ($asset->client_id !== null) {
            $client = Client::query()
                ->whereKey($asset->client_id)
                ->where('status', 'active')
                ->first(['site_id']);
            if ($client === null) {
                return [];
            }
            $siteIds->push($client->site_id);
        }

        return $siteIds
            ->filter(fn (mixed $candidate): bool => is_numeric($candidate) && (int) $candidate > 0)
            ->map(fn (mixed $candidate): int => (int) $candidate)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function oneSiteId(mixed $siteId): array
    {
        return is_numeric($siteId) && (int) $siteId > 0 ? [(int) $siteId] : [];
    }
}
