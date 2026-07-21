<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DeviceRegistryService
{
    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /** Base query for the single application registry. */
    public function query(): Builder
    {
        return Device::query();
    }

    /**
     * Devices with an active assignment to a given site (including room-level assignments
     * within that site).
     */
    public function forSite(int $siteId): Builder
    {
        $roomIds = SiteRoom::where('site_id', $siteId)->pluck('id');

        return $this->applySiteScope($this->query(), $siteId, $roomIds);
    }

    public function visibleForSite(User $user, int $siteId): Builder
    {
        $roomIds = SiteRoom::where('site_id', $siteId)->pluck('id');

        return $this->applySiteScope($this->access->visibleDevices($user), $siteId, $roomIds);
    }

    private function applySiteScope(Builder $query, int $siteId, $roomIds): Builder
    {
        return $query
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
            })
            ->whereDoesntHave('assignments', function (Builder $q) use ($siteId, $roomIds): void {
                $q->active()->where(function (Builder $outside) use ($siteId, $roomIds): void {
                    $outside->where(function (Builder $site) use ($siteId): void {
                        $site->where('assignable_type', DeviceAssignment::TARGET_SITE)
                            ->where('assignable_id', '!=', $siteId);
                    })->orWhere(function (Builder $room) use ($roomIds): void {
                        $room->where('assignable_type', DeviceAssignment::TARGET_ROOM);
                        if ($roomIds->isEmpty()) {
                            return;
                        }
                        $room->whereNotIn('assignable_id', $roomIds);
                    });
                });
            });
    }

    /**
     * Devices with an active assignment to a given client.
     */
    public function forClient(int $clientId): Builder
    {
        return $this->query()
            ->whereHas('assignments', function (Builder $q) use ($clientId) {
                $q->active()
                    ->forTarget(DeviceAssignment::TARGET_CLIENT, $clientId);
            });
    }

    /**
     * Devices with an active asset link to a given vehicle/asset (e.g. trackers installed in a vehicle).
     */
    public function forVehicle(int $assetId): Builder
    {
        return $this->query()
            ->whereHas('assetLinks', function (Builder $q) use ($assetId) {
                $q->active()->forAsset($assetId);
            });
    }

    /**
     * Devices with an active assignment to a given staff member.
     */
    public function forStaff(int $userId): Builder
    {
        return $this->query()
            ->whereHas('assignments', function (Builder $q) use ($userId) {
                $q->active()
                    ->forTarget(DeviceAssignment::TARGET_STAFF, $userId);
            });
    }

    /**
     * Devices with no active assignment (pooled stock / available for checkout).
     */
    public function unassigned(): Builder
    {
        return $this->query()
            ->whereDoesntHave('assignments', function (Builder $q) {
                $q->active();
            });
    }

    /**
     * Devices filtered by domain.
     */
    public function byDomain(string|DeviceDomain $domain): Builder
    {
        return $this->query()->byDomain($domain);
    }

    /**
     * Devices filtered by category.
     */
    public function byCategory(string $category): Builder
    {
        return $this->query()->byCategory($category);
    }

    /**
     * Devices that belong to a specific group.
     */
    public function forGroup(int $groupId): Builder
    {
        return $this->query()
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
        return Asset::query()
            ->whereHas('deviceLinks', function (Builder $q) use ($deviceId) {
                // Note: requires Asset model to have a deviceLinks() relationship added later.
                // For now, query directly.
                $q->active()->forDevice($deviceId);
            });
    }
}
