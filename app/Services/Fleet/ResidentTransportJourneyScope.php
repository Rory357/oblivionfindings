<?php

namespace App\Services\Fleet;

use App\Models\Asset;
use App\Models\Client;
use App\Models\FleetMedicationTransitLog;
use App\Models\FleetResidentTransport;
use App\Models\Shift;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ResidentTransportJourneyScope
{
    public const SITE_BYPASS_PERMISSIONS = ['fleet.manage'];

    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    /** @return array<int, int> */
    public function accessibleSiteIds(?User $user): array
    {
        return $this->siteAccess->accessibleSiteIds($user, self::SITE_BYPASS_PERMISSIONS);
    }

    public function applyClientScope(Builder $query, ?User $user): Builder
    {
        return $this->siteAccess->applyClientScope($query, $user, self::SITE_BYPASS_PERMISSIONS);
    }

    public function applyShiftScope(Builder $query, ?User $user): Builder
    {
        return $this->siteAccess->applyShiftScope($query, $user, self::SITE_BYPASS_PERMISSIONS);
    }

    public function applyStaffScope(Builder $query, ?User $user): Builder
    {
        return $this->siteAccess->applyStaffScope($query, $user, self::SITE_BYPASS_PERMISSIONS);
    }

    public function applyVehicleScope(Builder $query, ?User $user): Builder
    {
        $siteIds = $this->accessibleSiteIds($user);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $vehicles) use ($siteIds): void {
            $vehicles->whereIn('site_id', $siteIds)
                ->orWhere(function (Builder $homeSite) use ($siteIds): void {
                    $homeSite->whereNull('site_id')->whereIn('home_site_id', $siteIds);
                });
        });
    }

    public function applyTransportScope(Builder $query, ?User $user): Builder
    {
        $siteIds = $this->accessibleSiteIds($user);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $transportTable = $query->getModel()->getTable();
        $siteColumn = $query->qualifyColumn('site_id');
        $residentColumn = $query->qualifyColumn('resident_id');
        $assetColumn = $query->qualifyColumn('asset_id');
        $shiftColumn = $query->qualifyColumn('shift_id');
        $bookingColumn = $query->qualifyColumn('booking_id');
        $driverColumn = $query->qualifyColumn('driver_user_id');

        return $query
            ->whereNotNull($residentColumn)
            ->whereIn($siteColumn, $siteIds)
            ->whereHas('resident', function (Builder $resident) use ($transportTable): void {
                $resident->whereColumn(
                    $resident->qualifyColumn('site_id'),
                    "{$transportTable}.site_id",
                );
            })
            ->whereHas('asset', function (Builder $asset) use ($transportTable): void {
                $asset->where('category', 'vehicle')
                    ->where(function (Builder $owner) use ($transportTable): void {
                        $owner->whereColumn($owner->qualifyColumn('site_id'), "{$transportTable}.site_id")
                            ->orWhere(function (Builder $homeSite) use ($transportTable): void {
                                $homeSite->whereNull('site_id')
                                    ->whereColumn($homeSite->qualifyColumn('home_site_id'), "{$transportTable}.site_id");
                            });
                    })
                    ->where(function (Builder $residentBinding) use ($transportTable): void {
                        $residentBinding->whereNull($residentBinding->qualifyColumn('client_id'))
                            ->orWhereColumn(
                                $residentBinding->qualifyColumn('client_id'),
                                "{$transportTable}.resident_id",
                            );
                    });
            })
            ->where(function (Builder $shiftBinding) use ($shiftColumn, $transportTable): void {
                $shiftBinding->whereNull($shiftColumn)
                    ->orWhereHas('shift', function (Builder $shift) use ($transportTable): void {
                        $shift->whereColumn(
                            $shift->qualifyColumn('client_id'),
                            "{$transportTable}.resident_id",
                        )->whereColumn(
                            $shift->qualifyColumn('user_id'),
                            "{$transportTable}.driver_user_id",
                        )->where(function (Builder $owner) use ($transportTable): void {
                            $owner->whereColumn($owner->qualifyColumn('site_id'), "{$transportTable}.site_id")
                                ->orWhere(function (Builder $clientSite) use ($transportTable): void {
                                    $clientSite->whereNull('site_id')
                                        ->whereHas('client', fn (Builder $client): Builder => $client->whereColumn(
                                            $client->qualifyColumn('site_id'),
                                            "{$transportTable}.site_id",
                                        ));
                                });
                        });
                    });
            })
            ->where(function (Builder $bookingBinding) use ($bookingColumn, $driverColumn, $siteColumn, $transportTable): void {
                $bookingBinding->whereNull($bookingColumn)
                    ->orWhereHas('booking', fn (Builder $booking): Builder => $booking
                        ->whereColumn(
                            $booking->qualifyColumn('asset_id'),
                            "{$transportTable}.asset_id",
                        )
                        ->whereColumn($booking->qualifyColumn('user_id'), $driverColumn)
                        ->where(function (Builder $pickup) use ($siteColumn): void {
                            $pickup->whereNull($pickup->qualifyColumn('pickup_site_id'))
                                ->orWhereColumn($pickup->qualifyColumn('pickup_site_id'), $siteColumn);
                        })
                        ->where(function (Builder $return) use ($siteColumn): void {
                            $return->whereNull($return->qualifyColumn('return_site_id'))
                                ->orWhereColumn($return->qualifyColumn('return_site_id'), $siteColumn);
                        }));
            })
            ->whereNotNull($assetColumn);
    }

    public function applyMedicationTransitScope(Builder $query, ?User $user): Builder
    {
        $logTable = $query->getModel()->getTable();
        $transportColumn = $query->qualifyColumn('transport_id');
        $clientColumn = $query->qualifyColumn('client_id');
        $siteColumn = $query->qualifyColumn('site_id');
        $shiftColumn = $query->qualifyColumn('shift_id');
        $medicationColumn = $query->qualifyColumn('medication_id');

        return $query
            ->whereNotNull($transportColumn)
            ->whereNotNull($medicationColumn)
            ->whereNotNull($siteColumn)
            ->whereHas('transport', function (Builder $transport) use ($user, $logTable): void {
                $this->applyTransportScope($transport, $user)
                    ->whereColumn($transport->qualifyColumn('resident_id'), "{$logTable}.client_id")
                    ->whereColumn($transport->qualifyColumn('site_id'), "{$logTable}.site_id");
            })
            ->whereHas('medication', fn (Builder $medication): Builder => $medication->whereColumn(
                $medication->qualifyColumn('client_id'),
                $clientColumn,
            ))
            ->where(function (Builder $shiftBinding) use ($shiftColumn, $logTable): void {
                $shiftBinding->where(function (Builder $withoutShift) use ($shiftColumn): void {
                    $withoutShift->whereNull($shiftColumn)
                        ->whereHas('transport', fn (Builder $transport): Builder => $transport->whereNull('shift_id'));
                })->orWhere(function (Builder $withShift) use ($shiftColumn, $logTable): void {
                    $withShift->whereNotNull($shiftColumn)
                        ->whereHas('transport', fn (Builder $transport): Builder => $transport->whereColumn(
                            $transport->qualifyColumn('shift_id'),
                            "{$logTable}.shift_id",
                        ));
                });
            });
    }

    public function transportFor(User $user, int $transportId, bool $lockForUpdate = false): FleetResidentTransport
    {
        $query = FleetResidentTransport::query()->whereKey($transportId);
        $this->applyTransportScope($query, $user);

        return $query->when($lockForUpdate, fn (Builder $builder): Builder => $builder->lockForUpdate())
            ->firstOrFail();
    }

    public function mutableTransportFor(User $user, int $transportId, bool $lockForUpdate = false): FleetResidentTransport
    {
        $query = FleetResidentTransport::query()->whereKey($transportId);
        $this->applyTransportScope($query, $user);
        if (! $user->canDo('fleet.manage')) {
            $query->where('driver_user_id', $user->id);
        }

        return $query->when($lockForUpdate, fn (Builder $builder): Builder => $builder->lockForUpdate())
            ->firstOrFail();
    }

    public function medicationTransitLogFor(User $user, int $logId, bool $lockForUpdate = false): FleetMedicationTransitLog
    {
        $query = FleetMedicationTransitLog::query()->whereKey($logId);
        $this->applyMedicationTransitScope($query, $user);

        return $query->when($lockForUpdate, fn (Builder $builder): Builder => $builder->lockForUpdate())
            ->firstOrFail();
    }

    public function clientFor(User $user, int $clientId, bool $lockForUpdate = false): Client
    {
        $query = Client::query()->whereKey($clientId);
        $this->applyClientScope($query, $user);

        return $query->when($lockForUpdate, fn (Builder $builder): Builder => $builder->lockForUpdate())
            ->firstOrFail();
    }

    public function shiftFor(User $user, int $shiftId, bool $lockForUpdate = false): Shift
    {
        $query = Shift::query()->whereKey($shiftId);
        $this->applyShiftScope($query, $user);

        return $query->when($lockForUpdate, fn (Builder $builder): Builder => $builder->lockForUpdate())
            ->firstOrFail();
    }

    public function vehicleForSite(int $assetId, int $siteId, int $residentId, bool $lockForUpdate = false): Asset
    {
        return Asset::query()
            ->whereKey($assetId)
            ->where('category', 'vehicle')
            ->where(function (Builder $vehicle) use ($siteId): void {
                $vehicle->where('site_id', $siteId)
                    ->orWhere(fn (Builder $homeSite): Builder => $homeSite
                        ->whereNull('site_id')
                        ->where('home_site_id', $siteId));
            })
            ->where(fn (Builder $residentBinding): Builder => $residentBinding
                ->whereNull('client_id')
                ->orWhere('client_id', $residentId))
            ->when($lockForUpdate, fn (Builder $builder): Builder => $builder->lockForUpdate())
            ->firstOrFail();
    }

    /** @return Collection<int, User> */
    public function medicationWitnessesForSite(int $siteId, ?int $excludeUserId = null): Collection
    {
        $query = User::query()->staff()->whereNotNull('approved_at')->orderBy('name');
        $this->siteAccess->applyFleetRecipientEligibility($query, $siteId);

        return $query->get(['id', 'name'])
            ->reject(fn (User $user): bool => $excludeUserId !== null && (int) $user->id === $excludeUserId)
            ->filter(fn (User $user): bool => $user->canDo('medications.controlled.witness'))
            ->values();
    }

    /** @return Collection<int, User> */
    public function medicationWitnessesFor(User $viewer, ?int $excludeUserId = null): Collection
    {
        $query = User::query()->staff()->whereNotNull('approved_at')->orderBy('name');
        $this->applyStaffScope($query, $viewer);

        return $query->get(['id', 'name'])
            ->reject(fn (User $user): bool => $excludeUserId !== null && (int) $user->id === $excludeUserId)
            ->filter(fn (User $user): bool => $user->canDo('medications.controlled.witness'))
            ->values();
    }

    public function canViewResidentCareContext(?User $user): bool
    {
        return (bool) $user && collect([
            'clients.viewAny',
            'clients.viewAssigned',
            'clients.update',
        ])->contains(fn (string $permission): bool => $user->canDo($permission));
    }

    public function canViewMedicationTransit(?User $user): bool
    {
        return (bool) $user && (
            $user->canDo('medications.view')
            || $this->canManageMedicationTransit($user)
        );
    }

    public function canManageMedicationTransit(?User $user): bool
    {
        return (bool) $user && collect([
            'fleet.medication.manage',
            'medications.administer.record',
            'medications.stock.update',
            'clients.update',
        ])->contains(fn (string $permission): bool => $user->canDo($permission));
    }
}
