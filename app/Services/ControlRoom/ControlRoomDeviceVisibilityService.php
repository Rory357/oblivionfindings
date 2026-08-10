<?php

namespace App\Services\ControlRoom;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ControlRoom\Device;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Site and source-module boundary for the legacy Control Room signal projection.
 *
 * Control Room devices remain a signal-pipeline projection. Canonical identity
 * is visible only through SecurityDevicesAccessService and is never used to
 * broaden the operator's Control Room Site access.
 */
class ControlRoomDeviceVisibilityService
{
    /** @var list<string> */
    private const SITE_BYPASS_PERMISSIONS = ['reports.viewAny'];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly SecurityDevicesAccessService $deviceAccess,
    ) {}

    /** @return list<int> */
    public function accessibleSiteIds(User $user): array
    {
        return $this->siteAccess->accessibleSiteIds($user, self::SITE_BYPASS_PERMISSIONS);
    }

    public function canViewCanonicalDevices(User $user): bool
    {
        return $user->canDo('securityDevices.devices.view');
    }

    public function applyCanonicalDeviceScope(Builder|Relation $query, User $user): Builder|Relation
    {
        if (! $this->canViewCanonicalDevices($user)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            'devices.id',
            $this->deviceAccess->visibleDevices($user)->select('devices.id'),
        );
    }

    public function applyScope(Builder $query, User $user, ?array $requestedSiteIds = null): Builder
    {
        $accessibleSiteIds = $this->accessibleSiteIds($user);
        $siteIds = $requestedSiteIds === null
            ? $accessibleSiteIds
            : array_values(array_intersect(
                $accessibleSiteIds,
                collect($requestedSiteIds)
                    ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all(),
            ));

        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $clientIds = Client::query()->select('id')->whereIn('site_id', $siteIds);
        $assetIds = Asset::query()
            ->select('id')
            ->where(function (Builder $assets) use ($siteIds): void {
                $assets->whereIn('site_id', $siteIds)
                    ->orWhereIn('home_site_id', $siteIds);
            });
        $canBypass = $this->siteAccess->canBypass($user, self::SITE_BYPASS_PERMISSIONS);
        $visibleCanonicalIds = $this->canViewCanonicalDevices($user)
            ? $this->deviceAccess->visibleDevices($user)->select('devices.id')
            : null;

        $query->where(function (Builder $visibility) use (
            $siteIds,
            $clientIds,
            $assetIds,
            $visibleCanonicalIds,
            $canBypass,
        ): void {
            $visibility->whereIn('control_room_devices.site_id', $siteIds)
                ->orWhere(function (Builder $clientFallback) use ($clientIds): void {
                    $clientFallback->whereNull('control_room_devices.site_id')
                        ->whereIn('control_room_devices.client_id', clone $clientIds);
                })
                ->orWhere(function (Builder $assetFallback) use ($assetIds): void {
                    $assetFallback->whereNull('control_room_devices.site_id')
                        ->whereNull('control_room_devices.client_id')
                        ->whereIn('control_room_devices.asset_id', clone $assetIds);
                });

            if ($visibleCanonicalIds !== null) {
                $visibility->orWhere(function (Builder $canonicalFallback) use ($visibleCanonicalIds): void {
                    $canonicalFallback->whereNull('control_room_devices.site_id')
                        ->whereNull('control_room_devices.client_id')
                        ->whereNull('control_room_devices.asset_id')
                        ->whereIn('control_room_devices.canonical_device_id', clone $visibleCanonicalIds);
                });
            }

            if ($canBypass) {
                $visibility->orWhere(function (Builder $unassigned): void {
                    $unassigned->whereNull('control_room_devices.site_id')
                        ->whereNull('control_room_devices.client_id')
                        ->whereNull('control_room_devices.asset_id')
                        ->whereNull('control_room_devices.canonical_device_id');
                });
            }
        });

        // Mixed provenance fails closed. One visible field must not mask an
        // explicitly inaccessible Client, Asset, or canonical Device link.
        $query->where(function (Builder $clients) use ($clientIds): void {
            $clients->whereNull('control_room_devices.client_id')
                ->orWhereIn('control_room_devices.client_id', clone $clientIds);
        });
        $query->where(function (Builder $assets) use ($assetIds): void {
            $assets->whereNull('control_room_devices.asset_id')
                ->orWhereIn('control_room_devices.asset_id', clone $assetIds);
        });
        if ($visibleCanonicalIds !== null) {
            $query->where(function (Builder $canonical) use ($visibleCanonicalIds): void {
                $canonical->whereNull('control_room_devices.canonical_device_id')
                    ->orWhereIn('control_room_devices.canonical_device_id', clone $visibleCanonicalIds);
            });
        }

        return $query;
    }

    public function assertCanView(User $user, Device $device): void
    {
        $query = Device::query()->whereKey($device->getKey());
        $this->applyScope($query, $user);

        abort_unless($query->exists(), 403, UserSiteAccessService::DEFAULT_MESSAGE);
    }

    public function assertCanFilterSite(User $user, int $siteId): void
    {
        abort_unless(
            in_array($siteId, $this->accessibleSiteIds($user), true),
            403,
            UserSiteAccessService::DEFAULT_MESSAGE,
        );
    }

    public function visibleSites(User $user): Builder
    {
        $ids = $this->accessibleSiteIds($user);

        return Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->when($ids === [], fn (Builder $sites): Builder => $sites->whereRaw('1 = 0'))
            ->when($ids !== [], fn (Builder $sites): Builder => $sites->whereKey($ids));
    }

    public function visibleClient(User $user, ?int $clientId): ?Client
    {
        if (! $clientId) {
            return null;
        }

        return Client::query()
            ->whereKey($clientId)
            ->whereIn('site_id', $this->accessibleSiteIds($user))
            ->first(['id', 'first_name', 'last_name']);
    }

    public function visibleAsset(User $user, ?int $assetId): ?Asset
    {
        if (! $assetId) {
            return null;
        }

        $siteIds = $this->accessibleSiteIds($user);

        return Asset::query()
            ->whereKey($assetId)
            ->where(function (Builder $assets) use ($siteIds): void {
                $assets->whereIn('site_id', $siteIds)
                    ->orWhereIn('home_site_id', $siteIds);
            })
            ->first(['id', 'name', 'asset_tag']);
    }
}
