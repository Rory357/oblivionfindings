<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetDriverSession;
use App\Models\FleetSignal;
use App\Models\FleetTrip;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DriverController extends Controller
{
    private const SITE_BYPASS_PERMISSIONS = ['fleet.manage'];

    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function index(Request $request)
    {
        $viewer = $request->user();
        abort_unless($viewer, 403);

        $query = $this->visibleDriversQuery($viewer)
            ->with([
                'hrDriverEligibility',
                'hrEmployeeProfile:id,user_id,is_active',
            ]);
        $filters = $this->registerFilters($request);

        if ($filters['search'] !== null) {
            $search = $filters['search'];
            $query->where(function (Builder $driverQuery) use ($search): void {
                $driverQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($filters['status'] !== null) {
            $status = $filters['status'];
            if ($status === 'expiring_soon') {
                $query->whereHas('hrDriverEligibility', fn (Builder $eligibility) => $eligibility
                    ->where('licence_expires_at', '>=', now())
                    ->where('licence_expires_at', '<=', now()->addDays(60))
                );
            } elseif ($status === 'expiring_30') {
                $query->whereHas('hrDriverEligibility', fn (Builder $eligibility) => $eligibility
                    ->where('licence_expires_at', '>=', now())
                    ->where('licence_expires_at', '<=', now()->addDays(30))
                );
            } elseif ($status === 'at_risk') {
                $query->whereHas('hrDriverEligibility', fn (Builder $eligibility) => $eligibility->where(
                    fn (Builder $risk) => $risk
                        ->whereIn('status', ['suspended', 'expired'])
                        ->orWhere('licence_expires_at', '<', now()),
                ));
            } else {
                $eligibilityStatuses = $status === 'pending'
                    ? ['pending', 'pending_review', 'review_required']
                    : [$status];
                $query->whereHas(
                    'hrDriverEligibility',
                    fn (Builder $eligibility) => $eligibility->whereIn('status', $eligibilityStatuses),
                );
            }
        }

        $query->orderBy($filters['sort'], $filters['direction']);

        // Export the same validated register query the user is viewing.
        if ($request->input('export') === 'csv') {
            $exportQuery = clone $query;

            return response()->streamDownload(function () use ($exportQuery): void {
                $handle = fopen('php://output', 'w');
                $this->putCsv($handle, ['Name', 'Email', 'Licence Class', 'Licence Expires', 'Status', 'Can Drive Clients']);
                foreach ($exportQuery->lazy(200) as $driver) {
                    $eligibility = $driver->hrDriverEligibility;
                    $this->putCsv($handle, [
                        $driver->name,
                        $driver->email,
                        $eligibility?->licence_class ?? '',
                        optional($eligibility?->licence_expires_at)->format('Y-m-d') ?? '',
                        $eligibility?->status ?? '',
                        $eligibility?->can_drive_clients ? 'Yes' : 'No',
                    ]);
                }
                fclose($handle);
            }, 'drivers-export.csv');
        }

        $drivers = $query->paginate(25)->withQueryString();
        $driverIds = $drivers->getCollection()->pluck('id')->all();
        $visibleVehicles = $this->visibleVehiclesQuery($viewer);

        $assignedVehicles = (clone $visibleVehicles)
            ->whereIn('primary_driver_user_id', $driverIds)
            ->get(['id', 'name', 'asset_tag', 'primary_driver_user_id'])
            ->groupBy('primary_driver_user_id');

        $sessionCounts = FleetDriverSession::query()
            ->whereIn('user_id', $driverIds)
            ->whereIn('asset_id', (clone $visibleVehicles)->select('assets.id'))
            ->selectRaw('user_id, COUNT(*) as session_count')
            ->groupBy('user_id')
            ->pluck('session_count', 'user_id');

        $now = now()->toDateTimeString();
        $in30 = now()->addDays(30)->toDateTimeString();
        $visibleDriverIds = $this->visibleDriversQuery($viewer)->select('users.id');
        $heroRow = HrDriverEligibility::query()
            ->whereIn('user_id', $visibleDriverIds)
            ->selectRaw(
                'COUNT(*) as total, '.
                "SUM(CASE WHEN status = 'eligible' AND (licence_expires_at IS NULL OR licence_expires_at >= ?) THEN 1 ELSE 0 END) as active, ".
                'SUM(CASE WHEN licence_expires_at >= ? AND licence_expires_at <= ? THEN 1 ELSE 0 END) as expiring_30, '.
                "SUM(CASE WHEN status IN ('suspended', 'expired') OR licence_expires_at < ? THEN 1 ELSE 0 END) as at_risk, ".
                'SUM(CASE WHEN licence_expires_at IS NOT NULL AND licence_expires_at < ? THEN 1 ELSE 0 END) as licence_expired',
                [$now, $now, $in30, $now, $now],
            )
            ->first();

        $sessionsToday = FleetDriverSession::query()
            ->whereIn('user_id', $this->visibleDriversQuery($viewer)->select('users.id'))
            ->whereIn('asset_id', (clone $visibleVehicles)->select('assets.id'))
            ->whereBetween('started_at', [now()->startOfDay(), now()->endOfDay()])
            ->count();

        return Inertia::render('fleet-assets/drivers/index', [
            'hero' => [
                'total' => (int) ($heroRow->total ?? 0),
                'active' => (int) ($heroRow->active ?? 0),
                'expiring_30' => (int) ($heroRow->expiring_30 ?? 0),
                'at_risk' => (int) ($heroRow->at_risk ?? 0),
                'licence_expired' => (int) ($heroRow->licence_expired ?? 0),
                'sessions_today' => $sessionsToday,
            ],
            'drivers' => [
                'data' => $drivers->getCollection()->map(fn (User $driver) => [
                    'id' => $driver->id,
                    'hr_profile_id' => $driver->hrEmployeeProfile?->id,
                    'name' => $driver->name,
                    'email' => $driver->email,
                    'eligibility' => $driver->hrDriverEligibility ? [
                        'licence_class' => $driver->hrDriverEligibility->licence_class,
                        'licence_expires_at' => optional($driver->hrDriverEligibility->licence_expires_at)->toDateString(),
                        'status' => $driver->hrDriverEligibility->status,
                        'can_drive_clients' => $driver->hrDriverEligibility->can_drive_clients,
                    ] : null,
                    'assigned_vehicles' => ($assignedVehicles->get($driver->id) ?? collect())->map(fn (Asset $vehicle) => [
                        'id' => $vehicle->id,
                        'name' => $vehicle->name,
                        'asset_tag' => $vehicle->asset_tag,
                    ])->values(),
                    'session_count' => (int) $sessionCounts->get($driver->id, 0),
                ])->values(),
                'links' => $drivers->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $drivers->currentPage(),
                    'last_page' => $drivers->lastPage(),
                    'total' => $drivers->total(),
                ],
            ],
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, User $user)
    {
        $viewer = $request->user();
        abort_unless($viewer, 403);

        $user = $this->visibleDriver($viewer, (int) $user->getKey());
        $visibleVehicles = $this->visibleVehiclesQuery($viewer);

        $assignedVehicles = (clone $visibleVehicles)
            ->where('primary_driver_user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'asset_tag', 'status'])
            ->map(fn (Asset $vehicle) => [
                'id' => $vehicle->id,
                'name' => $vehicle->name,
                'asset_tag' => $vehicle->asset_tag,
                'status' => $vehicle->status,
            ])
            ->values();

        $sessions = FleetDriverSession::query()
            ->where('user_id', $user->id)
            ->whereIn('asset_id', (clone $visibleVehicles)->select('assets.id'))
            ->with('asset:id,name,asset_tag')
            ->latest('started_at')
            ->limit(20)
            ->get()
            ->map(fn (FleetDriverSession $session) => [
                'id' => $session->id,
                'asset' => $session->asset ? ['id' => $session->asset->id, 'name' => $session->asset->name] : null,
                'started_at' => optional($session->started_at)->toISOString(),
                'ended_at' => optional($session->ended_at)->toISOString(),
                'status' => $session->status,
            ])->values();

        $recentTrips = FleetTrip::query()
            ->whereIn('asset_id', (clone $visibleVehicles)->select('assets.id'))
            ->whereHas('driverSession', fn (Builder $session) => $session
                ->where('user_id', $user->id)
                ->whereColumn('fleet_driver_sessions.asset_id', 'fleet_trips.asset_id'))
            ->with('asset:id,name')
            ->latest('started_at')
            ->limit(20)
            ->get()
            ->map(fn (FleetTrip $trip) => [
                'id' => $trip->id,
                'asset' => $trip->asset ? ['id' => $trip->asset->id, 'name' => $trip->asset->name] : null,
                'started_at' => optional($trip->started_at)->toISOString(),
                'ended_at' => optional($trip->ended_at)->toISOString(),
                'distance_km' => $trip->distance_km,
                'status' => $trip->status,
            ])->values();

        return Inertia::render('fleet-assets/drivers/show', [
            'driver' => [
                'id' => $user->id,
                'hr_profile_id' => $user->hrEmployeeProfile->id,
                'hr_profile_href' => $viewer->canDo('hr.employees.viewAny')
                    ? '/hr/people/'.$user->hrEmployeeProfile->id
                    : null,
                'name' => $user->name,
                'email' => $user->email,
                'eligibility' => $user->hrDriverEligibility,
                'hr_status' => $user->hrEmployeeProfile->is_active ? 'active' : 'inactive',
            ],
            'assigned_vehicles' => $assignedVehicles,
            'sessions' => $sessions,
            // FleetDrivingMetric is an asset/day roll-up with no driver or
            // session provenance. Never present it as a driver's score.
            'driving_metrics' => [],
            'recent_trips' => $recentTrips,
            'scorecard' => $request->input('tab') === 'scorecard'
                ? $this->scorecardData($user, $viewer, $request)
                : Inertia::optional(fn () => $this->scorecardData($user, $viewer, $request)),
        ]);
    }

    /**
     * Legacy GET /drivers/{user}/scorecard shim. Direct-object scope is checked
     * before redirecting to the canonical profile tab.
     */
    public function scorecard(Request $request, User $user)
    {
        $viewer = $request->user();
        abort_unless($viewer, 403);
        $user = $this->visibleDriver($viewer, (int) $user->getKey());

        $params = ['tab' => 'scorecard'];
        if ($request->filled('period')) {
            $params['period'] = (string) (int) $request->input('period');
        }

        return redirect('/fleet-assets/drivers/'.$user->id.'?'.http_build_query($params));
    }

    /** @return array<string, mixed> */
    private function scorecardData(User $driver, User $viewer, Request $request): array
    {
        $requestedPeriod = (int) $request->input('period', 30);
        $days = in_array($requestedPeriod, [7, 30, 90], true) ? $requestedPeriod : 30;
        $period = (string) $days;
        $periodStart = now()->subDays($days)->startOfDay();
        $visibleVehicles = $this->visibleVehiclesQuery($viewer);

        $totalDistance = FleetTrip::query()
            ->whereIn('asset_id', (clone $visibleVehicles)->select('assets.id'))
            ->whereHas('driverSession', fn (Builder $session) => $session
                ->where('user_id', $driver->id)
                ->whereColumn('fleet_driver_sessions.asset_id', 'fleet_trips.asset_id'))
            ->where('started_at', '>=', $periodStart)
            ->sum('distance_km');

        $recentEvents = FleetSignal::query()
            ->whereIn('asset_id', (clone $visibleVehicles)->select('assets.id'))
            ->whereHas('driverSession', fn (Builder $session) => $session
                ->where('user_id', $driver->id)
                ->whereColumn('fleet_driver_sessions.asset_id', 'fleet_signals.asset_id'))
            ->where('occurred_at', '>=', $periodStart)
            ->whereIn('signal_type', ['harsh_brake', 'harsh_accel', 'speeding', 'idle'])
            ->latest('occurred_at')
            ->limit(20)
            ->get()
            ->map(fn (FleetSignal $signal) => [
                'id' => $signal->id,
                'type' => $signal->signal_type,
                'severity' => $signal->severity_hint,
                'occurred_at' => optional($signal->occurred_at)->toISOString(),
                'asset_id' => $signal->asset_id,
            ])->values();

        return [
            'period' => $period,
            'score' => null,
            'previous_score' => null,
            'fleet_avg_score' => null,
            'evidence_note' => 'Retained safety scores are vehicle-level and cannot be attributed to one driver. Driver-session trips and recent signals remain available below.',
            'metrics' => [
                'harsh_brakes' => null,
                'hard_accels' => null,
                'speeding_events' => null,
                'idle_minutes' => null,
                'total_distance_km' => round((float) $totalDistance, 1),
            ],
            'recent_events' => $recentEvents,
        ];
    }

    /** @return array{search: ?string, status: ?string, sort: string, direction: string} */
    private function registerFilters(Request $request): array
    {
        $search = $request->input('search');
        $search = is_string($search) ? trim($search) : '';
        $status = $request->input('status');
        $status = is_string($status) ? trim($status) : '';
        $allowedStatuses = [
            'eligible',
            'pending',
            'pending_review',
            'review_required',
            'suspended',
            'expired',
            'expiring_soon',
            'expiring_30',
            'at_risk',
        ];
        $sort = $request->input('sort');
        $sort = is_string($sort) && in_array($sort, ['name', 'email'], true)
            ? $sort
            : 'name';
        $direction = $request->input('direction');
        $direction = is_string($direction) && in_array($direction, ['asc', 'desc'], true)
            ? $direction
            : 'asc';

        return [
            'search' => $search !== '' ? mb_substr($search, 0, 200) : null,
            'status' => in_array($status, $allowedStatuses, true) ? $status : null,
            'sort' => $sort,
            'direction' => $direction,
        ];
    }

    private function visibleDriver(User $viewer, int $driverId): User
    {
        $driver = $this->visibleDriversQuery($viewer)
            ->with([
                'hrDriverEligibility',
                'hrEmployeeProfile:id,user_id,is_active',
            ])
            ->whereKey($driverId)
            ->first();
        abort_unless($driver, 404);

        return $driver;
    }

    /** @return Builder<User> */
    private function visibleDriversQuery(User $viewer): Builder
    {
        $query = User::query()->whereHas('hrDriverEligibility');
        $this->siteAccess->applyStaffScope($query, $viewer, self::SITE_BYPASS_PERMISSIONS);

        return $query;
    }

    /** @return Builder<Asset> */
    private function visibleVehiclesQuery(User $viewer): Builder
    {
        $siteIds = $this->siteAccess->accessibleSiteIds($viewer, self::SITE_BYPASS_PERMISSIONS);

        return $this->applyAssetSiteScope(Asset::query()->vehicles(), $siteIds);
    }

    /** @param list<int> $siteIds */
    private function applyAssetSiteScope(Builder $query, array $siteIds): Builder
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
                    ->where(function (Builder $clientAgreement) use ($siteColumn, $clientColumn): void {
                        $clientAgreement->whereNull($clientColumn)
                            ->orWhereHas('client', fn (Builder $client) => $client->whereColumn(
                                $client->qualifyColumn('site_id'),
                                $siteColumn,
                            ));
                    });
            })->orWhere(function (Builder $homeSite) use (
                $siteIds,
                $siteColumn,
                $homeSiteColumn,
                $clientColumn,
            ): void {
                $homeSite->whereNull($siteColumn)
                    ->whereIn($homeSiteColumn, $siteIds)
                    ->where(function (Builder $clientAgreement) use ($homeSiteColumn, $clientColumn): void {
                        $clientAgreement->whereNull($clientColumn)
                            ->orWhereHas('client', fn (Builder $client) => $client->whereColumn(
                                $client->qualifyColumn('site_id'),
                                $homeSiteColumn,
                            ));
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
                    ->whereHas('client', fn (Builder $client) => $client->whereIn(
                        $client->qualifyColumn('site_id'),
                        $siteIds,
                    ));
            });
        });
    }
}
