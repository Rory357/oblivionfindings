<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\ControlRoomAlert;
use App\Models\FleetFuelLog;
use App\Models\FleetServiceSchedule;
use App\Models\FleetSignal;
use App\Models\FleetTrip;
use App\Models\FleetOuting;
use App\Models\FleetVehicleBooking;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\FleetWorkOrder;
use App\Models\LocationHardware;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $hasFleetFields = Schema::hasColumn('assets', 'home_site_id');

        // Vehicles with state
        $eagerLoads = ['trackers' => fn ($q) => $q->where('status', 'paired'), 'fleetState'];
        if ($hasFleetFields) {
            $eagerLoads[] = 'homeSite';
        }

        $vehicles = Asset::vehicles()
            ->with($eagerLoads)
            ->get();

        $vehicleIds = $vehicles->pluck('id')->all();

        $totalVehicles = $vehicles->count();
        $onlineVehicles = $vehicles->filter(fn ($v) => $v->fleetState?->status === 'online')->count();
        $offlineVehicles = $totalVehicles - $onlineVehicles;

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

        // Active bookings count (pending or approved)
        $recentBookingsCount = Schema::hasTable('fleet_vehicle_bookings')
            ? FleetVehicleBooking::query()
                ->whereIn('status', ['pending', 'approved'])
                ->count()
            : 0;

        // Upcoming maintenance (service schedules due within 30 days)
        $upcomingMaintenanceCount = Schema::hasTable('fleet_service_schedules')
            ? FleetServiceSchedule::query()
                ->where('is_active', true)
                ->where('next_due_at', '<=', now()->addDays(30))
                ->where('next_due_at', '>=', now())
                ->count()
            : 0;

        // Trips today
        $hasTripsTable = Schema::hasTable('fleet_trips');
        $tripsToday = $hasTripsTable
            ? FleetTrip::query()->whereDate('created_at', today())->count()
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

        $fleetBySite = $sites->map(function ($site) use ($vehicleIds, $hasFuelTable) {
            $siteVehicles = Asset::vehicles()->where('site_id', $site->id)->get();
            $siteVehicleIds = $siteVehicles->pluck('id')->all();

            return [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'vehicle_count' => $siteVehicles->count(),
                'online_count' => $siteVehicles->filter(fn ($v) => $v->fleetState?->status === 'online')->count(),
                'active_alerts' => ControlRoomAlert::whereIn('asset_id', $siteVehicleIds)
                    ->whereNotIn('status', ['closed', 'resolved'])->count(),
                'fuel_cost_mtd' => $hasFuelTable
                    ? FleetFuelLog::whereIn('asset_id', $siteVehicleIds)
                        ->whereMonth('created_at', now()->month)->sum('total_cost')
                    : 0,
            ];
        })->filter(fn ($s) => $s['vehicle_count'] > 0)->values();

        // After-hours trips (before 8am or after 6pm, last 7 days)
        $afterHoursTrips = $hasTripsTable
            ? FleetTrip::query()
                ->where('started_at', '>=', now()->subDays(7))
                ->where(function ($q) {
                    $q->whereRaw('HOUR(started_at) < 8')
                      ->orWhereRaw('HOUR(started_at) >= 18');
                })
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

        // Device health
        $totalDevices = AssetTracker::where('status', 'paired')->count();
        $onlineDevices = AssetTracker::where('status', 'paired')
            ->where('last_seen_at', '>', now()->subHours(2))
            ->count();

        // Tracked residents (LocationHardware trackers linked to clients)
        $trackedResidents = Schema::hasTable('location_hardware')
            ? LocationHardware::where('category', 'tracker')
                ->where('linked_person_type', 'client')
                ->count()
            : 0;

        // Active outings
        $activeOutings = Schema::hasTable('fleet_outings')
            ? FleetOuting::query()
                ->where(function ($q) {
                    $q->where('status', 'active')
                      ->orWhere(function ($q2) {
                          $q2->where('status', 'planned')
                             ->whereDate('planned_departure', today());
                      });
                })
                ->count()
            : 0;

        // My site vehicles (for "Vehicles at Your Site" widget)
        $mySiteVehicles = collect();
        $user = $request->user();
        $userSiteId = $user->site_id ?? null;

        // Fallback: try to get site from first assigned client
        if (!$userSiteId && method_exists($user, 'clients')) {
            try {
                $firstClient = $user->clients()->first();
                $userSiteId = $firstClient?->site_id ?? null;
            } catch (\Throwable $e) {
                // clients relationship may not exist
            }
        }

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
                'trackers' => $v->trackers->map(fn ($t) => [
                    'id' => $t->id,
                    'vendor' => $t->vendor,
                    'device_uid' => $t->device_uid,
                ])->values(),
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
                'upcoming_maintenance_count' => $upcomingMaintenanceCount,
                'trips_today' => $tripsToday,
                'tracked_residents' => $trackedResidents,
                'active_outings' => $activeOutings,
            ],
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
        ]);
    }
}
