<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
// AssetTracker import removed — retired. Using canonical Device model.
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\FleetFuelLog;
use App\Models\FleetResidentTransport;
use App\Models\FleetServiceSchedule;
use App\Models\FleetSignal;
use App\Models\FleetTrip;
use App\Models\FleetOuting;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\FleetWorkOrder;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $hasFleetFields = Schema::hasColumn('assets', 'home_site_id');

        // Resolve the user's site once — powers the "Vehicles at Your Site" widget
        // and the hero's ?scope=mine cluster lens.
        $user = $request->user();
        $userSiteId = $user->site_id ?? null;

        // Fallback: first assigned client's site. (The relation is
        // assignedClients(), not clients() — the old check never matched.)
        if (!$userSiteId && method_exists($user, 'assignedClients')) {
            try {
                $firstClient = $user->assignedClients()->first();
                $userSiteId = $firstClient?->site_id ?? null;
            } catch (\Throwable $e) {
                // pivot may be absent in older schemas
            }
        }

        $hasSite = $userSiteId !== null;
        $scope = $request->query('scope') === 'mine' && $hasSite ? 'mine' : 'all';
        $scoped = $scope === 'mine';

        // Vehicles with state.
        // Note: 'trackers' eager-load is legacy (AssetTracker). Dashboard only uses
        // tracker count for stats — actual device data comes from canonical Device model.
        $eagerLoads = ['fleetState'];
        if ($hasFleetFields) {
            $eagerLoads[] = 'homeSite';
        }

        $vehicles = Asset::vehicles()
            ->with($eagerLoads)
            ->get();

        $vehicleIds = $vehicles->pluck('id')->all();

        // Cluster lens — when scoped, the cluster counts (fleet status / today /
        // resident movement) cover the user's site only. The attention strip,
        // compliance badges and month strip stay org-wide by design.
        $clusterVehicles = $scoped
            ? $vehicles->filter(fn ($v) => (int) $v->site_id === (int) $userSiteId
                || ($hasFleetFields && (int) $v->home_site_id === (int) $userSiteId))
            : $vehicles;
        $clusterVehicleIds = $clusterVehicles->pluck('id')->all();
        $applyClusterScope = fn ($query, string $column = 'asset_id') => $scoped
            ? $query->whereIn($column, $clusterVehicleIds)
            : $query;

        $totalVehicles = $vehicles->count();
        $onlineVehicles = $clusterVehicles->filter(fn ($v) => $v->fleetState?->status === 'online')->count();
        $offlineVehicles = $clusterVehicles->count() - $onlineVehicles;

        // Vehicles currently under maintenance (either status convention) — from the
        // already-loaded collection, no extra query.
        $vehiclesInMaintenance = $clusterVehicles->whereIn('status', ['maintenance', 'out_of_service'])->count();

        // Compliance horizon for the hero badges (always org-wide) — same COUNT
        // patterns as VehicleController::index so the two heroes read identically.
        $wofDue30 = Asset::query()
            ->where(fn ($q) => $q->vehicles())
            ->wofExpiring(30)
            ->count();
        $wofExpired = Asset::query()
            ->where(fn ($q) => $q->vehicles())
            ->whereNotNull('wof_expires_at')
            ->where('wof_expires_at', '<', now())
            ->count();
        $regoDue30 = Asset::query()
            ->where(fn ($q) => $q->vehicles())
            ->registrationExpiring(30)
            ->count();
        $cofDue = Asset::query()
            ->where(fn ($q) => $q->vehicles())
            ->whereNotNull('cof_expires_at')
            ->where('cof_expires_at', '<=', now()->addDays(30))
            ->where('cof_expires_at', '>=', now())
            ->count();
        $insuranceExpiring = Schema::hasColumn('assets', 'insurance_expires_at')
            ? Asset::query()
                ->where(fn ($q) => $q->vehicles())
                ->whereNotNull('insurance_expires_at')
                ->where('insurance_expires_at', '<=', now()->addDays(30))
                ->count()
            : null;

        // Vehicle status breakdown from fleet state snapshots
        $vehicleStatusBreakdown = Schema::hasTable('fleet_vehicle_state_snapshots')
            ? FleetVehicleStateSnapshot::query()
                ->whereIn('asset_id', $vehicleIds)
                ->selectRaw("status, COUNT(*) as count")
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray()
            : [];

        // Assets count by status
        $assetStatusCounts = Asset::query()
            ->selectRaw("status, COUNT(*) as count")
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Maintenance stats (work orders by status)
        $maintenanceStats = Schema::hasTable('fleet_work_orders')
            ? FleetWorkOrder::query()
                ->selectRaw("status, COUNT(*) as count")
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray()
            : [];

        // Active alerts
        $activeAlerts = ControlRoomAlert::query()
            ->whereNotIn('status', ['closed', 'resolved'])
            ->count();

        $criticalAlerts = ControlRoomAlert::query()
            ->whereNotIn('status', ['closed', 'resolved'])
            ->where('severity', 'critical')
            ->count();

        // Booking status counts — cluster tiles honour the lens; the org-wide
        // overdue count additionally feeds the attention strip unscoped.
        $hasBookingsTable = Schema::hasTable('fleet_vehicle_bookings');
        $recentBookingsCount = $hasBookingsTable
            ? $applyClusterScope(FleetVehicleBooking::query()->whereIn('status', ['pending', 'approved']))
                ->count()
            : 0;

        $checkedOutCount = $hasBookingsTable
            ? $applyClusterScope(FleetVehicleBooking::query()->where('status', 'checked_out'))
                ->count()
            : 0;

        $overdueCount = $hasBookingsTable
            ? FleetVehicleBooking::query()
                ->where('status', 'checked_out')
                ->where('ends_at', '<', now())
                ->count()
            : 0;

        $overdueCountScoped = ! $scoped ? $overdueCount : ($hasBookingsTable
            ? $applyClusterScope(
                FleetVehicleBooking::query()
                    ->where('status', 'checked_out')
                    ->where('ends_at', '<', now())
            )->count()
            : 0);

        // Today's outings
        $hasOutingsTable = Schema::hasTable('fleet_outings');
        $todayOutings = $hasOutingsTable
            ? FleetOuting::query()
                ->whereIn('status', ['planned', 'active'])
                ->where(function ($q) {
                    $q->whereDate('planned_departure', today())
                      ->orWhere('status', 'active');
                })
                ->with(['asset:id,name', 'driver:id,name'])
                ->limit(10)
                ->get()
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'title' => $o->title,
                    'destination' => $o->destination,
                    'status' => $o->status,
                    'planned_departure' => optional($o->planned_departure)->toISOString(),
                    'asset' => $o->asset ? ['id' => $o->asset->id, 'name' => $o->asset->name] : null,
                    'driver' => $o->driver ? ['id' => $o->driver->id, 'name' => $o->driver->name] : null,
                    'resident_count' => $o->residents()->count(),
                ])
                ->values()
            : collect();

        // Upcoming maintenance (service schedules due within 30 days)
        $upcomingMaintenanceCount = Schema::hasTable('fleet_service_schedules')
            ? FleetServiceSchedule::query()
                ->where('is_active', true)
                ->where('next_due_at', '<=', now()->addDays(30))
                ->where('next_due_at', '>=', now())
                ->count()
            : 0;

        // Trips today (cluster tile — honours the lens)
        $hasTripsTable = Schema::hasTable('fleet_trips');
        $tripsToday = $hasTripsTable
            ? $applyClusterScope(FleetTrip::query()->whereDate('created_at', today()))->count()
            : 0;

        // Fuel MTD
        $hasFuelTable = Schema::hasTable('fleet_fuel_logs');
        $fuelMtd = $hasFuelTable
            ? FleetFuelLog::query()
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('total_cost')
            : 0;

        // Distance MTD
        $distanceMtd = $hasTripsTable
            ? FleetTrip::query()
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('distance_km')
            : 0;

        // Recent signals
        $recentSignals = Schema::hasTable('fleet_signals')
            ? FleetSignal::query()
                ->latest('occurred_at')
                ->limit(10)
                ->with('asset:id,name')
                ->get()
                ->map(fn ($s) => [
                    'id' => $s->id,
                    'signal_type' => $s->signal_type,
                    'severity' => $s->severity_hint,
                    'occurred_at' => optional($s->occurred_at)->toISOString(),
                    'asset' => $s->asset ? [
                        'id' => $s->asset->id,
                        'name' => $s->asset->name,
                    ] : null,
                    'payload' => $s->payload,
                ])
                ->values()
            : collect();

        // Recent alerts for table display
        $recentAlerts = ControlRoomAlert::query()
            ->whereNotIn('status', ['closed', 'resolved'])
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'severity' => $a->severity,
                'status' => $a->status,
                'created_at' => optional($a->created_at)->toISOString(),
            ])
            ->values();

        // Fleet by Site
        $sites = Site::query()
            ->whereNotNull('name')
            ->get(['id', 'name', 'type']);

        // Batch-load all vehicles grouped by site_id (avoids N+1 per site)
        $allSiteVehicles = Asset::vehicles()
            ->whereIn('site_id', $sites->pluck('id'))
            ->with('fleetState')
            ->get()
            ->groupBy('site_id');

        $allSiteVehicleIds = $allSiteVehicles->flatMap->pluck('id')->all();

        $alertCountsBySite = ControlRoomAlert::query()
            ->whereIn('control_room_alerts.asset_id', $allSiteVehicleIds)
            ->whereNotIn('control_room_alerts.status', ['closed', 'resolved'])
            ->join('assets', 'assets.id', '=', 'control_room_alerts.asset_id')
            ->selectRaw('assets.site_id, COUNT(*) as cnt')
            ->groupBy('assets.site_id')
            ->pluck('cnt', 'site_id');

        $fuelCostsBySite = $hasFuelTable
            ? FleetFuelLog::query()
                ->whereIn('fleet_fuel_logs.asset_id', $allSiteVehicleIds)
                ->whereMonth('fleet_fuel_logs.created_at', now()->month)
                ->join('assets', 'assets.id', '=', 'fleet_fuel_logs.asset_id')
                ->selectRaw('assets.site_id, SUM(fleet_fuel_logs.total_cost) as total')
                ->groupBy('assets.site_id')
                ->pluck('total', 'site_id')
            : collect();

        $fleetBySite = $sites->map(function ($site) use ($allSiteVehicles, $alertCountsBySite, $fuelCostsBySite) {
            $siteVehicles = $allSiteVehicles->get($site->id, collect());

            return [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'vehicle_count' => $siteVehicles->count(),
                'online_count' => $siteVehicles->filter(fn ($v) => $v->fleetState?->status === 'online')->count(),
                'active_alerts' => (int) ($alertCountsBySite[$site->id] ?? 0),
                'fuel_cost_mtd' => round((float) ($fuelCostsBySite[$site->id] ?? 0), 2),
            ];
        })->filter(fn ($s) => $s['vehicle_count'] > 0)->values();

        // After-hours trips (before 8am or after 6pm worker-timezone, last 7 days)
        $afterHoursTrips = $hasTripsTable
            ? FleetTrip::query()
                ->where('started_at', '>=', now()->subDays(7))
                ->afterHours()
                ->with('asset:id,name', 'driverSession.user:id,name')
                ->latest('started_at')
                ->limit(10)->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'vehicle' => $t->asset?->name ?? 'Unknown',
                    'driver' => $t->driverSession?->user?->name ?? 'Unknown',
                    'started_at' => $t->started_at?->toISOString(),
                    'time' => $t->started_at?->format('H:i'),
                    'date' => $t->started_at?->format('d M Y'),
                    'distance_km' => $t->distance_km,
                ])
            : collect();

        // House locations
        $houses = Site::query()
            ->where('type', 'house')
            ->whereNotNull('latitude')
            ->get(['id', 'name', 'address_line_1', 'latitude', 'longitude'])
            ->map(fn ($h) => [
                'id' => $h->id,
                'name' => $h->name,
                'address' => $h->address_line_1,
                'latitude' => $h->latitude,
                'longitude' => $h->longitude,
            ])->values();

        // Device health — canonical tracking devices.
        $totalDevices = Device::where('domain', 'tracking')
            ->whereIn('status', ['active', 'degraded'])
            ->count();
        $onlineDevices = Device::where('domain', 'tracking')
            ->where('status', 'active')
            ->where('last_seen_at', '>', now()->subHours(2))
            ->count();

        // Resident movement cluster — all three tiles honour the lens. Site
        // attribution for residents goes through the client's site.
        $siteClientIds = $scoped
            ? Client::query()->where('site_id', $userSiteId)->pluck('id')->all()
            : null;

        // Tracked residents — canonical device assignments (client-assigned tracking devices).
        $trackedResidentsQuery = DeviceAssignment::query()
            ->active()
            ->where('assignable_type', 'client')
            ->whereHas('device', fn ($q) => $q->where('domain', 'tracking'));
        if ($siteClientIds !== null) {
            $trackedResidentsQuery->whereIn('assignable_id', $siteClientIds);
        }
        $trackedResidents = $trackedResidentsQuery->count();

        // Open wandering alerts — same base filter as the resident-tracking
        // wandering tab (tracker-sourced, client-linked Control Room alerts).
        $openWanderingQuery = ControlRoomAlert::query()
            ->whereIn('source', ['tracker', 'geofence', 'resident_tracker'])
            ->whereNotNull('client_id')
            ->whereIn('alert_type', ['geofence_breach', 'wandering'])
            ->whereNotIn('status', ['closed', 'resolved']);
        if ($siteClientIds !== null) {
            $openWanderingQuery->whereIn('client_id', $siteClientIds);
        }
        $openWanderingAlerts = $openWanderingQuery->count();

        // Today's resident transports — count pattern from ResidentTransportController.
        $transportsToday = Schema::hasTable('fleet_resident_transports')
            ? $applyClusterScope(FleetResidentTransport::query()->whereDate('departed_at', today()))->count()
            : 0;

        // Active outings (cluster tile — honours the lens)
        $activeOutings = $hasOutingsTable
            ? $applyClusterScope(FleetOuting::query()
                ->where(function ($q) {
                    $q->where('status', 'active')
                      ->orWhere(function ($q2) {
                          $q2->where('status', 'planned')
                             ->whereDate('planned_departure', today());
                      });
                }))
                ->count()
            : 0;

        // Outings past their planned return — org-wide for the attention strip,
        // scoped variant for the Outings tile caption.
        $outingsPastReturn = $hasOutingsTable
            ? FleetOuting::query()
                ->where('status', 'active')
                ->where('planned_return', '<', now())
                ->count()
            : 0;
        $outingsPastReturnScoped = ! $scoped ? $outingsPastReturn : ($hasOutingsTable
            ? $applyClusterScope(
                FleetOuting::query()
                    ->where('status', 'active')
                    ->where('planned_return', '<', now())
            )->count()
            : 0);

        // My site vehicles (for "Vehicles at Your Site" widget)
        $mySiteVehicles = collect();
        if ($userSiteId && $hasFleetFields) {
            $mySiteVehicles = Asset::vehicles()
                ->with('fleetState')
                ->where(function ($q) use ($userSiteId) {
                    $q->where('home_site_id', $userSiteId)
                      ->orWhere('site_id', $userSiteId);
                })
                ->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'status' => $v->fleetState?->status ?? $v->status ?? 'unknown',
                ])
                ->values();
        }

        return Inertia::render('fleet-assets/dashboard', [
            'vehicles' => $vehicles->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'asset_tag' => $v->asset_tag,
                'status' => $v->status,
                'home_site' => $hasFleetFields && $v->homeSite ? [
                    'id' => $v->homeSite->id,
                    'name' => $v->homeSite->name,
                ] : null,
                'state' => $v->fleetState ? [
                    'status' => $v->fleetState->status,
                    'last_seen_at' => optional($v->fleetState->last_seen_at)->toISOString(),
                    'lat' => $v->fleetState->latitude,
                    'lng' => $v->fleetState->longitude,
                    'speed_kph' => $v->fleetState->speed_kph,
                    'heading_deg' => $v->fleetState->heading_deg,
                    'battery_pct' => $v->fleetState->battery_pct,
                ] : null,
                'trackers' => \App\Domain\SecurityDevices\Models\DeviceAssetLink::query()
                    ->active()
                    ->forAsset($v->id)
                    ->with('device:id,device_uid,name,provider,status')
                    ->get()
                    ->map(fn ($link) => [
                        'id' => $link->device?->id,
                        'vendor' => $link->device?->provider,
                        'device_uid' => $link->device?->device_uid,
                    ])
                    ->filter(fn ($t) => $t['id'] !== null)
                    ->values(),
            ])->values(),
            'stats' => [
                'total_vehicles' => $totalVehicles,
                'online_count' => $onlineVehicles,
                'offline_count' => $offlineVehicles,
                'total_assets' => array_sum($assetStatusCounts),
                'active_alerts' => $activeAlerts,
                'critical_alerts' => $criticalAlerts,
                'fuel_cost_mtd' => round((float) $fuelMtd, 2),
                'distance_mtd' => round((float) $distanceMtd, 1),
                'total_devices' => $totalDevices,
                'online_devices' => $onlineDevices,
                'recent_bookings_count' => $recentBookingsCount,
                'checked_out_count' => $checkedOutCount,
                'overdue_count' => $overdueCount,
                'overdue_count_scoped' => $overdueCountScoped,
                'outings_past_return' => $outingsPastReturn,
                'outings_past_return_scoped' => $outingsPastReturnScoped,
                'upcoming_maintenance_count' => $upcomingMaintenanceCount,
                'trips_today' => $tripsToday,
                'vehicles_in_maintenance' => $vehiclesInMaintenance,
                'wof_due_30' => $wofDue30,
                'wof_expired' => $wofExpired,
                'rego_due_30' => $regoDue30,
                'cof_due' => $cofDue,
                'insurance_expiring' => $insuranceExpiring,
                'transports_today' => $transportsToday,
                'open_wandering_alerts' => $openWanderingAlerts,
                'tracked_residents' => $trackedResidents,
                'active_outings' => $activeOutings,
            ],
            'scope' => $scope,
            'has_site' => $hasSite,
            'vehicle_status_breakdown' => $vehicleStatusBreakdown,
            'asset_status_breakdown' => $assetStatusCounts,
            'maintenance_stats' => $maintenanceStats,
            'recent_signals' => $recentSignals->map(function ($s) {
                $s['severity_hint'] = $s['severity'] ?? 'low';
                unset($s['severity']);
                return $s;
            })->values(),
            'recent_alerts' => $recentAlerts,
            'houses' => $houses,
            'fleet_by_site' => $fleetBySite,
            'after_hours_trips' => $afterHoursTrips,
            'my_site_vehicles' => $mySiteVehicles,
            'today_outings' => $todayOutings,
        ]);
    }
}
