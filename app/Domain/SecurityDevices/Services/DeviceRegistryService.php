<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use Illuminate\Database\Eloquent\Builder;

class DeviceRegistryService
{
    /**
     * Base query scoped to a tenant.
     */
    public function query(int $tenantId): Builder
    {
        return Device::query()->forTenant($tenantId);
    }

    /**
     * Devices with an active assignment to a given site (including room-level assignments
     * within that site).
     */
    public function forSite(int $tenantId, int $siteId): Builder
    {
        $roomIds = \App\Models\SiteRoom::where('site_id', $siteId)->pluck('id');

        return $this->query($tenantId)
            ->whereHas('assignments', function (Builder $q) use ($siteId, $roomIds) {
                $q->active()->where(function (Builder $q) use ($siteId, $roomIds) {
                    $q->where(function ($q) use ($siteId) {
                        $q->where('assignable_type', DeviceAssignment::TARGET_SITE)
                            ->where('assignable_id', $siteId);
                    });

                    if ($roomIds->isNotEmpty()) {
                        $q->orWhere(function ($q) use ($roomIds) {
                            $q->where('assignable_type', DeviceAssignment::TARGET_ROOM)
                                ->whereIn('assignable_id', $roomIds);
                        });
                    }
                });
            });
    }

    /**
     * Devices with an active assignment to a given client.
     */
    public function forClient(int $tenantId, int $clientId): Builder
    {
        return $this->query($tenantId)
            ->whereHas('assignments', function (Builder $q) use ($clientId) {
                $q->active()
                    ->forTarget(DeviceAssignment::TARGET_CLIENT, $clientId);
            });
    }

    /**
     * Devices with an active asset link to a given vehicle/asset (e.g. trackers installed in a vehicle).
     */
    public function forVehicle(int $tenantId, int $assetId): Builder
    {
        return $this->query($tenantId)
            ->whereHas('assetLinks', function (Builder $q) use ($assetId) {
                $q->active()->forAsset($assetId);
            });
    }

    /**
     * Devices with an active assignment to a given staff member.
     */
    public function forStaff(int $tenantId, int $userId): Builder
    {
        return $this->query($tenantId)
            ->whereHas('assignments', function (Builder $q) use ($userId) {
                $q->active()
                    ->forTarget(DeviceAssignment::TARGET_STAFF, $userId);
            });
    }

    /**
     * Devices with no active assignment (pooled stock / available for checkout).
     */
    public function unassigned(int $tenantId): Builder
    {
        return $this->query($tenantId)
            ->whereDoesntHave('assignments', function (Builder $q) {
                $q->active();
            });
    }

    /**
     * Devices filtered by domain.
     */
    public function byDomain(int $tenantId, string|DeviceDomain $domain): Builder
    {
        return $this->query($tenantId)->byDomain($domain);
    }

    /**
     * Devices filtered by category.
     */
    public function byCategory(int $tenantId, string $category): Builder
    {
        return $this->query($tenantId)->byCategory($category);
    }

    /**
     * Devices that belong to a specific group.
     */
    public function forGroup(int $tenantId, int $groupId): Builder
    {
        return $this->query($tenantId)
            ->whereHas('groups', function (Builder $q) use ($groupId) {
                $q->where('device_groups.id', $groupId);
            });
    }

    /**
     * Devices linked to a specific asset (via device_asset_links).
     */
    public function linkedToAsset(int $assetId): Builder
    {
        return Device::query()
            ->whereHas('assetLinks', function (Builder $q) use ($assetId) {
                $q->active()->forAsset($assetId);
            });
    }

    /**
     * Assets linked to a specific device.
     */
    public function assetsForDevice(int $deviceId): Builder
    {
        return \App\Models\Asset::query()
            ->whereHas('deviceLinks', function (Builder $q) use ($deviceId) {
                // Note: requires Asset model to have a deviceLinks() relationship added later.
                // For now, query directly.
                $q->active()->forDevice($deviceId);
            });
    }
}
