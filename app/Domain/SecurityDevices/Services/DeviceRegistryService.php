<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class DeviceRegistryService
{
    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /** Base query for the single application registry. */
    public function query(): Builder
    {
        return Device::query();
    }

    /**
     * Register a reviewed discovery result in the one canonical Device registry.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function registerDiscoveredDevice(array $attributes, int $siteId, int $actorId): Device
    {
        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->first();
        $actor = User::query()->whereKey($actorId)->whereNotNull('approved_at')->first();
        $name = trim((string) ($attributes['name'] ?? ''));
        $domain = DeviceDomain::tryFrom((string) ($attributes['domain'] ?? ''));
        $category = trim((string) ($attributes['category'] ?? ''));
        if ($site === null || $actor === null || $name === '' || $domain === null || $category === '') {
            throw new UnexpectedValueException('Reviewed discovery target is unavailable.');
        }

        $allowed = array_intersect_key($attributes, array_flip([
            'name',
            'domain',
            'category',
            'subcategory',
            'manufacturer',
            'model',
            'serial_number',
            'mac_address',
            'firmware_version',
            'ip_address',
            'provider',
            'external_ref',
        ]));
        $allowed['name'] = $name;
        $allowed['domain'] = $domain->value;
        $allowed['category'] = $category;
        $allowed['status'] = DeviceStatus::Active->value;
        $allowed['created_by_user_id'] = $actor->id;

        return DB::transaction(function () use ($allowed, $site, $actor): Device {
            $device = Device::query()->create($allowed);
            DeviceAssignment::query()->create([
                'device_id' => $device->id,
                'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $site->id,
                'assignment_type' => AssignmentType::Permanent,
                'assigned_at' => now(),
                'assigned_by_user_id' => $actor->id,
            ]);

            return $device;
        }, 3);
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
            ->whereHas('activeAssetLinks', function (Builder $q) use ($assetId) {
                $q->forAsset($assetId);
            });
    }

    /**
     * Assets linked to a specific device.
     */
    public function assetsForDevice(int $deviceId): Builder
    {
        return Asset::query()
            ->whereHas('activeDeviceLinks', function (Builder $q) use ($deviceId) {
                $q->forDevice($deviceId);
            });
    }
}
