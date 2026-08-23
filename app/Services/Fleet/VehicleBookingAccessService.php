<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Asset;
use App\Models\Client;
use App\Models\FleetVehicleBooking;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Canonical Fleet booking read/direct-object boundary.
 *
 * The Security & Devices access owner supplies operational Sites, Client
 * policy decisions and canonical Asset provenance. The booking layer only
 * intersects those decisions with booking-owned Site references. The explicit
 * all-Site permission broadens that dependency's scope; it never grants a
 * Fleet read, approval or management action.
 */
class VehicleBookingAccessService
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /** @return list<int> */
    public function accessibleSiteIds(User $actor): array
    {
        return $this->access->accessibleSiteIds($actor);
    }

    /** @return list<int> */
    public function authorizedVehicleIds(User $actor): array
    {
        return $this->access->accessibleVehiclesForFleet($actor)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function accessibleBookings(User $actor): Builder
    {
        $siteIds = $this->accessibleSiteIds($actor);
        $vehicleIds = $this->authorizedVehicleIds($actor);

        return FleetVehicleBooking::query()
            ->when($vehicleIds === [], fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->when($vehicleIds !== [], fn (Builder $query): Builder => $query->whereIn('asset_id', $vehicleIds))
            ->where(function (Builder $pickup) use ($siteIds): void {
                $pickup->whereNull('pickup_site_id');
                if ($siteIds !== []) {
                    $pickup->orWhereIn('pickup_site_id', $siteIds);
                }
            })
            ->where(function (Builder $return) use ($siteIds): void {
                $return->whereNull('return_site_id');
                if ($siteIds !== []) {
                    $return->orWhereIn('return_site_id', $siteIds);
                }
            });
    }

    public function booking(User $actor, int $id, bool $lockForUpdate = false): ?FleetVehicleBooking
    {
        $query = $this->accessibleBookings($actor)->whereKey($id);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function vehicle(User $actor, int $id, bool $lockForUpdate = false): ?Asset
    {
        $query = $this->access->accessibleVehiclesForFleet($actor)->whereKey($id);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @return Collection<int, Asset> */
    public function activeVehicles(User $actor): Collection
    {
        $vehicleIds = $this->authorizedVehicleIds($actor);

        return Asset::vehicles()
            ->where('status', 'active')
            ->when($vehicleIds === [], fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->when($vehicleIds !== [], fn (Builder $query): Builder => $query->whereKey($vehicleIds))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Client> */
    public function clients(User $actor): Collection
    {
        return $this->access->assignableClients($actor)->values();
    }

    public function client(User $actor, int $id, bool $lockForUpdate = false): ?Client
    {
        return $this->access->assignableClient($actor, $id, $lockForUpdate);
    }

    /** @return Collection<int, Site> */
    public function sites(User $actor): Collection
    {
        return $this->access->accessibleSites($actor)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);
    }

    /**
     * Lock submitted canonical Sites in ascending order. Missing, archived and
     * foreign IDs all fail the same scoped lookup before booking side effects.
     *
     * @param  list<int>  $siteIds
     */
    public function lockSites(User $actor, array $siteIds): bool
    {
        $ids = collect($siteIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        if ($ids->isEmpty()) {
            return true;
        }

        return $this->access->accessibleSites($actor)
            ->whereKey($ids->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id'])
            ->count() === $ids->count();
    }
}
