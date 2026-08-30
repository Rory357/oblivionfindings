<?php

namespace App\Http\Controllers\Fleet;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetDriverSession;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
use App\Services\AuditLogger;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FleetTripController extends Controller
{
    /** @var list<string> */
    private const SITE_BYPASS_PERMISSIONS = ['fleet.manage'];

    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function show(Request $request, int $trip)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('fleet.viewAny'), 403);

        $visibleSiteIds = $this->siteAccess->accessibleSiteIds($user, self::SITE_BYPASS_PERMISSIONS);
        $trip = $this->visibleTripsQuery($visibleSiteIds)->findOrFail($trip);
        $trip->load('asset:id,name,asset_tag');

        $visibleDriverSessions = $this->visibleDriverSessionsQuery($trip, $visibleSiteIds);
        $currentDriverSession = $trip->driver_session_id
            ? (clone $visibleDriverSessions)->with('user:id,name')->find($trip->driver_session_id)
            : null;

        AuditLogger::log('fleet.trip.view', $trip, [
            'trip_id' => $trip->id,
        ]);

        $drivers = (clone $visibleDriverSessions)
            ->with('user:id,name')
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();

        return Inertia::render('fleet-assets/trips/playback', [
            'trip' => [
                'id' => $trip->id,
                'asset_id' => $trip->asset_id,
                'asset' => $trip->asset ? [
                    'id' => $trip->asset->id,
                    'name' => $trip->asset->name,
                    'asset_tag' => $trip->asset->asset_tag,
                ] : null,
                'driver_session_id' => $currentDriverSession?->id,
                'driver' => $currentDriverSession?->user ? [
                    'id' => $currentDriverSession->user->id,
                    'name' => $currentDriverSession->user->name,
                ] : null,
                'started_at' => optional($trip->started_at)->toISOString(),
                'ended_at' => optional($trip->ended_at)->toISOString(),
                'start_latitude' => $trip->start_latitude,
                'start_longitude' => $trip->start_longitude,
                'end_latitude' => $trip->end_latitude,
                'end_longitude' => $trip->end_longitude,
                'distance_km' => $trip->distance_km,
                'duration_s' => $trip->duration_s,
                'status' => $trip->status,
                'consent_blocked' => $trip->consent_blocked,
            ],
            'driver_sessions' => $drivers->map(fn ($d) => [
                'id' => $d->id,
                'user' => $d->user ? [
                    'id' => $d->user->id,
                    'name' => $d->user->name,
                ] : null,
                'started_at' => optional($d->started_at)->toISOString(),
                'ended_at' => optional($d->ended_at)->toISOString(),
            ])->values(),
            'can' => [
                'manage' => $user->canDo('fleet.trips.manage'),
            ],
        ]);
    }

    public function playback(Request $request, int $trip)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('fleet.viewAny'), 403);

        $visibleSiteIds = $this->siteAccess->accessibleSiteIds($user, self::SITE_BYPASS_PERMISSIONS);
        $trip = $this->visibleTripsQuery($visibleSiteIds)->findOrFail($trip);

        $query = FleetTelemetryEvent::query()
            ->where('asset_id', $trip->asset_id)
            ->where('consent_blocked', false)
            ->orderBy('occurred_at');

        if ($trip->started_at) {
            $query->where('occurred_at', '>=', $trip->started_at);
        }
        if ($trip->ended_at) {
            $query->where('occurred_at', '<=', $trip->ended_at);
        }

        $points = $query->limit(2000)->get(['occurred_at', 'latitude', 'longitude', 'speed_kph']);

        return response()->json([
            'trip_id' => $trip->id,
            'points' => $points->map(fn ($p) => [
                'occurred_at' => optional($p->occurred_at)->toISOString(),
                'lat' => $p->latitude,
                'lng' => $p->longitude,
                'speed_kph' => $p->speed_kph,
            ])->values(),
        ]);
    }

    public function update(Request $request, FleetTrip $trip)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('fleet.trips.manage'), 403);

        $data = $request->validate([
            'driver_session_id' => ['nullable', 'integer', 'exists:fleet_driver_sessions,id'],
            'status' => ['nullable', 'string', 'in:open,closed,cancelled'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $trip->update($data);

        AuditLogger::log('fleet.trip.update', $trip, [
            'trip_id' => $trip->id,
            'changes' => $data,
        ]);

        return back()->with('success', 'Trip updated.');
    }

    public function close(Request $request, FleetTrip $trip)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('fleet.trips.manage'), 403);

        if ($trip->status === 'closed') {
            return back()->withErrors(['trip' => 'Trip is already closed.']);
        }

        $trip->update([
            'status' => 'closed',
            'ended_at' => $trip->ended_at ?? now(),
        ]);

        AuditLogger::log('fleet.trip.close', $trip, [
            'trip_id' => $trip->id,
        ]);

        return back()->with('success', 'Trip closed.');
    }

    public function destroy(Request $request, FleetTrip $trip)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('fleet.trips.manage'), 403);

        AuditLogger::log('fleet.trip.delete', $trip, [
            'trip_id' => $trip->id,
            'asset_id' => $trip->asset_id,
        ]);

        $trip->delete();

        return redirect()->route('fleet-assets.trips.index')->with('success', 'Trip deleted.');
    }

    /** @param list<int> $siteIds */
    private function visibleTripsQuery(array $siteIds): Builder
    {
        return FleetTrip::query()->whereIn(
            'asset_id',
            $this->applyTripAssetSiteScope(Asset::query()->vehicles(), $siteIds)->select('assets.id'),
        );
    }

    /** @param list<int> $siteIds */
    private function applyTripAssetSiteScope(Builder $query, array $siteIds): Builder
    {
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $siteColumn = $query->qualifyColumn('site_id');
        $homeSiteColumn = $query->qualifyColumn('home_site_id');
        $clientColumn = $query->qualifyColumn('client_id');

        return $query->where(function (Builder $provenance) use (
            $siteIds,
            $siteColumn,
            $homeSiteColumn,
            $clientColumn,
        ): void {
            $provenance->where(function (Builder $directSite) use ($siteIds, $siteColumn, $clientColumn): void {
                $directSite->whereIn($siteColumn, $siteIds)
                    ->whereHas('site', fn (Builder $site) => $this->applyOperationalSiteScope($site))
                    ->where(function (Builder $clientAgreement) use ($siteColumn, $clientColumn): void {
                        $clientAgreement->whereNull($clientColumn)
                            ->orWhereHas('client', fn (Builder $client) => $client
                                ->whereColumn($client->qualifyColumn('site_id'), $siteColumn)
                                ->whereHas('site', fn (Builder $site) => $this->applyOperationalSiteScope($site)));
                    });
            })->orWhere(function (Builder $homeSite) use (
                $siteIds,
                $siteColumn,
                $homeSiteColumn,
                $clientColumn,
            ): void {
                $homeSite->whereNull($siteColumn)
                    ->whereIn($homeSiteColumn, $siteIds)
                    ->whereHas('homeSite', fn (Builder $site) => $this->applyOperationalSiteScope($site))
                    ->where(function (Builder $clientAgreement) use ($homeSiteColumn, $clientColumn): void {
                        $clientAgreement->whereNull($clientColumn)
                            ->orWhereHas('client', fn (Builder $client) => $client
                                ->whereColumn($client->qualifyColumn('site_id'), $homeSiteColumn)
                                ->whereHas('site', fn (Builder $site) => $this->applyOperationalSiteScope($site)));
                    });
            })->orWhere(function (Builder $clientSite) use (
                $siteIds,
                $siteColumn,
                $homeSiteColumn,
                $clientColumn,
            ): void {
                $clientSite->whereNull($siteColumn)
                    ->whereNull($homeSiteColumn)
                    ->whereNotNull($clientColumn)
                    ->whereHas('client', fn (Builder $client) => $client
                        ->whereIn($client->qualifyColumn('site_id'), $siteIds)
                        ->whereHas('site', fn (Builder $site) => $this->applyOperationalSiteScope($site)));
            });
        });
    }

    private function applyOperationalSiteScope(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('is_active'), true)
            ->where($query->qualifyColumn('archived'), false)
            ->whereNull($query->qualifyColumn('archived_at'));
    }

    /** @param list<int> $siteIds */
    private function visibleDriverSessionsQuery(FleetTrip $trip, array $siteIds): Builder
    {
        return FleetDriverSession::query()
            ->where('asset_id', $trip->asset_id)
            ->whereHas('user', function (Builder $userQuery) use ($siteIds): void {
                $this->applyHistoricalTripDriverSiteScope($userQuery, $siteIds);
            });
    }

    /** @param list<int> $siteIds */
    private function applyHistoricalTripDriverSiteScope(Builder $query, array $siteIds): Builder
    {
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $profiles = HrEmployeeProfile::withTrashed()
            ->select('user_id')
            ->where(function (Builder $siteQuery) use ($siteIds): void {
                $siteQuery->whereIn('primary_site_id', $siteIds);

                foreach ($siteIds as $siteId) {
                    $siteQuery->orWhereJsonContains('secondary_site_ids', $siteId);
                }
            });

        return $query->whereIn($query->qualifyColumn('id'), $profiles);
    }
}
