<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Client;
use App\Models\FleetDriverSession;
use App\Models\FleetDrivingMetric;
use App\Models\FleetFuelLog;
use App\Models\FleetIncident;
use App\Models\FleetResidentTransport;
use App\Models\FleetTrip;
use App\Models\FleetWorkOrder;
use App\Models\Site;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $period = $request->input('period', '30d');
        $startDate = match ($period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
            default => now()->subDays(30),
        };

        $vehicleIds = Asset::vehicles()->pluck('id');
        $hasTripsTable = Schema::hasTable('fleet_trips');
        $filterPersonal = Schema::hasColumn('fleet_trips', 'is_personal');

        // Vehicle utilization - trips per vehicle
        $utilizationQuery = FleetTrip::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('started_at', '>=', $startDate);
        if ($filterPersonal) {
            $utilizationQuery->where('is_personal', false);
        }
        $utilization = $utilizationQuery
            ->with('asset:id,name,asset_tag')
            ->select('asset_id', DB::raw('COUNT(*) as trip_count'), DB::raw('SUM(distance_km) as total_km'), DB::raw('SUM(duration_s) as total_duration_s'))
            ->groupBy('asset_id')
            ->orderByDesc('total_km')
            ->limit(15)
            ->get()
            ->map(fn ($row) => [
                'vehicle' => $row->asset?->name ?? 'Unknown',
                'asset_tag' => $row->asset?->asset_tag ?? '',
                'trips' => (int) $row->trip_count,
                'distance_km' => round((float) $row->total_km, 1),
                'hours' => round((float) $row->total_duration_s / 3600, 1),
            ])
            ->toArray();

        // Fuel costs
        $fuelStats = FleetFuelLog::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('logged_at', '>=', $startDate)
            ->selectRaw('
                COUNT(*) as total_fill_ups,
                SUM(quantity_litres) as total_litres,
                SUM(total_cost) as total_cost,
                AVG(cost_per_litre) as avg_cost_per_litre
            ')
            ->first();

        $fuelByVehicle = FleetFuelLog::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('logged_at', '>=', $startDate)
            ->with('asset:id,name')
            ->select('asset_id', DB::raw('SUM(total_cost) as total_cost'), DB::raw('SUM(quantity_litres) as total_litres'))
            ->groupBy('asset_id')
            ->orderByDesc('total_cost')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'vehicle' => $row->asset?->name ?? 'Unknown',
                'cost' => round((float) $row->total_cost, 2),
                'litres' => round((float) $row->total_litres, 1),
            ])
            ->toArray();

        // Maintenance costs
        $maintenanceCosts = FleetWorkOrder::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('
                COUNT(*) as total_work_orders,
                SUM(COALESCE(actual_cost, estimated_cost, 0)) as total_cost,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN status = "open" THEN 1 ELSE 0 END) as open_count
            ')
            ->first();

        // Compliance - upcoming expirations
        $expirations = Asset::vehicles()
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where('wof_expires_at', '<=', now()->addDays(90))
                  ->orWhere('registration_expires_at', '<=', now()->addDays(90))
                  ->orWhere('cof_expires_at', '<=', now()->addDays(90));
            })
            ->get(['id', 'name', 'asset_tag', 'wof_expires_at', 'registration_expires_at', 'cof_expires_at'])
            ->flatMap(function ($vehicle) {
                $items = [];
                foreach (['wof_expires_at', 'registration_expires_at', 'cof_expires_at'] as $field) {
                    if ($vehicle->$field && $vehicle->$field->lte(now()->addDays(90))) {
                        $days = now()->diffInDays($vehicle->$field, false);
                        $items[] = [
                            'vehicle_id' => $vehicle->id,
                            'vehicle_name' => $vehicle->name,
                            'asset_tag' => $vehicle->asset_tag,
                            'type' => str_replace('_expires_at', '', $field),
                            'expires_at' => $vehicle->$field->toDateString(),
                            'days_remaining' => $days,
                            'status' => $days < 0 ? 'expired' : ($days <= 30 ? 'critical' : 'warning'),
                        ];
                    }
                }
                return $items;
            })
            ->sortBy('days_remaining')
            ->values();

        // Trip summary stats
        $tripStatsQuery = FleetTrip::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('started_at', '>=', $startDate);
        if ($filterPersonal) {
            $tripStatsQuery->where('is_personal', false);
        }
        $tripStats = $tripStatsQuery
            ->selectRaw('
                COUNT(*) as total_trips,
                SUM(distance_km) as total_distance_km,
                SUM(duration_s) as total_duration_s
            ')
            ->first();

        // Trip distribution by day of week (real data)
        $tripDistribution = $hasTripsTable
            ? FleetTrip::query()
                ->where('status', 'closed')
                ->when($filterPersonal, fn($q) => $q->where('is_personal', false))
                ->whereMonth('started_at', now()->month)
                ->whereYear('started_at', now()->year)
                ->selectRaw("DAYOFWEEK(started_at) as dow, COUNT(*) as count")
                ->groupBy('dow')
                ->pluck('count', 'dow')
                ->toArray()
            : [];

        // Incident stats
        $incidentStats = Schema::hasTable('fleet_incidents')
            ? [
                'total' => FleetIncident::whereMonth('occurred_at', now()->month)->whereYear('occurred_at', now()->year)->count(),
                'by_severity' => FleetIncident::whereMonth('occurred_at', now()->month)->whereYear('occurred_at', now()->year)
                    ->selectRaw("severity, COUNT(*) as count")->groupBy('severity')->pluck('count', 'severity')->toArray(),
                'by_type' => FleetIncident::whereMonth('occurred_at', now()->month)->whereYear('occurred_at', now()->year)
                    ->selectRaw("incident_type, COUNT(*) as count")->groupBy('incident_type')->pluck('count', 'incident_type')->toArray(),
                'open' => FleetIncident::whereIn('status', ['reported', 'investigating'])->count(),
            ]
            : ['total' => 0, 'by_severity' => [], 'by_type' => [], 'open' => 0];

        // ── Decision Report Data ──────────────────────────────────────

        // Vehicle utilisation with flags
        $periodDays = max(1, now()->diffInDays($startDate));
        $periodWeeks = max(1, $periodDays / 7);

        $vehicleUtilisation = $hasTripsTable
            ? FleetTrip::query()
                ->whereIn('asset_id', $vehicleIds)
                ->where('started_at', '>=', $startDate)
                ->when($filterPersonal, fn ($q) => $q->where('is_personal', false))
                ->selectRaw('asset_id, COUNT(*) as trips, SUM(distance_km) as km, MAX(started_at) as last_trip_at')
                ->groupBy('asset_id')
                ->get()
                ->keyBy('asset_id')
            : collect();

        $vehicleFuelCosts = FleetFuelLog::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('logged_at', '>=', $startDate)
            ->selectRaw('asset_id, SUM(total_cost) as fuel_cost')
            ->groupBy('asset_id')
            ->pluck('fuel_cost', 'asset_id');

        $vehicleUtilData = Asset::vehicles()->get(['id', 'name', 'asset_tag'])->map(function ($v) use ($vehicleUtilisation, $vehicleFuelCosts, $periodWeeks) {
            $data = $vehicleUtilisation->get($v->id);
            $trips = (int) ($data->trips ?? 0);
            $km = round((float) ($data->km ?? 0), 1);
            $tripsPerWeek = round($trips / $periodWeeks, 1);
            $kmPerWeek = round($km / $periodWeeks, 1);
            $lastTripAt = $data->last_trip_at ?? null;
            $idleDays = $lastTripAt ? max(0, now()->diffInDays($lastTripAt)) : null;
            $fuelCost = round((float) ($vehicleFuelCosts[$v->id] ?? 0), 2);
            $costPerKm = $km > 0 ? round($fuelCost / $km, 2) : null;

            $flag = 'normal';
            if ($tripsPerWeek < 1 && $idleDays !== null && $idleDays >= 7) $flag = 'underused';
            if ($tripsPerWeek > 8) $flag = 'overused';

            return [
                'id' => $v->id,
                'name' => $v->name,
                'asset_tag' => $v->asset_tag,
                'trips' => $trips,
                'km' => $km,
                'trips_per_week' => $tripsPerWeek,
                'km_per_week' => $kmPerWeek,
                'idle_days' => $idleDays,
                'cost_per_km' => $costPerKm,
                'flag' => $flag,
            ];
        })->sortByDesc('trips')->values()->toArray();

        // Staff risk: incident rate per driver
        $hasIncidents = Schema::hasTable('fleet_incidents');
        $hasSessions = Schema::hasTable('fleet_driver_sessions');
        $hasMetrics = Schema::hasTable('fleet_driving_metrics');

        $staffRisk = [];
        if ($hasIncidents && $hasSessions) {
            $driverIncidents = FleetIncident::query()
                ->whereNotNull('driver_user_id')
                ->where('occurred_at', '>=', $startDate)
                ->selectRaw('driver_user_id, COUNT(*) as incident_count')
                ->groupBy('driver_user_id')
                ->pluck('incident_count', 'driver_user_id');

            $driverTrips = FleetDriverSession::query()
                ->where('started_at', '>=', $startDate)
                ->selectRaw('user_id, COUNT(*) as session_count')
                ->groupBy('user_id')
                ->pluck('session_count', 'user_id');

            $driverScores = $hasMetrics
                ? FleetDrivingMetric::query()
                    ->where('period_start', '>=', $startDate)
                    ->selectRaw('asset_id, AVG(score) as avg_score')
                    ->groupBy('asset_id')
                    ->pluck('avg_score', 'asset_id')
                : collect();

            // Build per-driver data from sessions
            $driverIds = $driverTrips->keys()->merge($driverIncidents->keys())->unique();
            $driverNames = \App\Models\User::whereIn('id', $driverIds)->pluck('name', 'id');

            // Map driver to recent vehicles for score lookup
            $driverAssets = $hasSessions
                ? FleetDriverSession::query()
                    ->where('started_at', '>=', $startDate)
                    ->selectRaw('user_id, asset_id')
                    ->distinct()
                    ->get()
                    ->groupBy('user_id')
                    ->map(fn ($rows) => $rows->pluck('asset_id')->unique()->all())
                : collect();

            $staffRisk = $driverIds->map(function ($driverId) use ($driverNames, $driverIncidents, $driverTrips, $driverScores, $driverAssets) {
                $sessions = (int) ($driverTrips[$driverId] ?? 0);
                $incidents = (int) ($driverIncidents[$driverId] ?? 0);
                $assetIds = $driverAssets[$driverId] ?? [];
                $scores = collect($assetIds)->map(fn ($id) => $driverScores[$id] ?? null)->filter()->values();
                $avgScore = $scores->isNotEmpty() ? round($scores->avg()) : null;

                return [
                    'id' => $driverId,
                    'name' => $driverNames[$driverId] ?? 'Unknown',
                    'sessions' => $sessions,
                    'incidents' => $incidents,
                    'safety_score' => $avgScore,
                    'risk_flag' => $incidents >= 3 ? 'high' : ($incidents >= 1 ? 'medium' : 'low'),
                ];
            })->sortByDesc('incidents')->take(15)->values()->toArray();
        }

        // Resident transport demand
        $hasTransports = Schema::hasTable('fleet_resident_transports');
        $residentDemand = [];
        if ($hasTransports) {
            $transportsByResident = FleetResidentTransport::query()
                ->whereNotNull('resident_id')
                ->where('departed_at', '>=', $startDate)
                ->selectRaw('resident_id, COUNT(*) as transport_count, MAX(departed_at) as last_transport')
                ->groupBy('resident_id')
                ->orderByDesc('transport_count')
                ->limit(20)
                ->get();

            $residentIds = $transportsByResident->pluck('resident_id')->all();
            $residentNames = Client::whereIn('id', $residentIds)->get(['id', 'first_name', 'last_name'])->keyBy('id');

            $purposeBreakdown = FleetResidentTransport::query()
                ->whereNotNull('resident_id')
                ->where('departed_at', '>=', $startDate)
                ->selectRaw('transport_type, COUNT(*) as count')
                ->groupBy('transport_type')
                ->pluck('count', 'transport_type')
                ->toArray();

            $residentDemand = [
                'residents' => $transportsByResident->map(function ($row) use ($residentNames, $periodWeeks) {
                    $client = $residentNames[$row->resident_id] ?? null;
                    return [
                        'id' => $row->resident_id,
                        'name' => $client ? trim($client->first_name . ' ' . $client->last_name) : 'Unknown',
                        'transport_count' => (int) $row->transport_count,
                        'per_week' => round($row->transport_count / $periodWeeks, 1),
                        'last_transport' => $row->last_transport ? \Carbon\Carbon::parse($row->last_transport)->toISOString() : null,
                    ];
                })->values()->toArray(),
                'purpose_breakdown' => $purposeBreakdown,
            ];
        }

        // Monthly trends (last 6 months)
        $trends = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = now()->subMonths($i)->startOfMonth();
            $monthEnd = now()->subMonths($i)->endOfMonth();
            $label = $monthStart->format('M Y');

            $monthTrips = $hasTripsTable
                ? FleetTrip::whereIn('asset_id', $vehicleIds)
                    ->whereBetween('started_at', [$monthStart, $monthEnd])
                    ->when($filterPersonal, fn ($q) => $q->where('is_personal', false))
                    ->count()
                : 0;

            $monthKm = $hasTripsTable
                ? round((float) FleetTrip::whereIn('asset_id', $vehicleIds)
                    ->whereBetween('started_at', [$monthStart, $monthEnd])
                    ->when($filterPersonal, fn ($q) => $q->where('is_personal', false))
                    ->sum('distance_km'), 1)
                : 0;

            $monthFuel = round((float) FleetFuelLog::whereIn('asset_id', $vehicleIds)
                ->whereBetween('logged_at', [$monthStart, $monthEnd])
                ->sum('total_cost'), 2);

            $monthIncidents = $hasIncidents
                ? FleetIncident::whereIn('asset_id', $vehicleIds)
                    ->whereBetween('occurred_at', [$monthStart, $monthEnd])
                    ->count()
                : 0;

            $trends[] = [
                'month' => $label,
                'trips' => $monthTrips,
                'distance_km' => $monthKm,
                'fuel_cost' => $monthFuel,
                'incidents' => $monthIncidents,
            ];
        }

        AuditLogger::log('fleet-assets.reports.view', null, ['period' => $period]);

        return Inertia::render('fleet-assets/reports/index', [
            'period' => $period,
            'utilization' => $utilization,
            'trip_stats' => [
                'total_trips' => (int) ($tripStats->total_trips ?? 0),
                'total_distance_km' => round((float) ($tripStats->total_distance_km ?? 0), 1),
                'total_hours' => round((float) ($tripStats->total_duration_s ?? 0) / 3600, 1),
            ],
            'fuel_stats' => [
                'total_fill_ups' => (int) ($fuelStats->total_fill_ups ?? 0),
                'total_litres' => round((float) ($fuelStats->total_litres ?? 0), 1),
                'total_cost' => round((float) ($fuelStats->total_cost ?? 0), 2),
                'avg_cost_per_litre' => round((float) ($fuelStats->avg_cost_per_litre ?? 0), 3),
            ],
            'fuel_by_vehicle' => $fuelByVehicle,
            'maintenance_stats' => [
                'total_work_orders' => (int) ($maintenanceCosts->total_work_orders ?? 0),
                'total_cost' => round((float) ($maintenanceCosts->total_cost ?? 0), 2),
                'completed_count' => (int) ($maintenanceCosts->completed_count ?? 0),
                'open_count' => (int) ($maintenanceCosts->open_count ?? 0),
            ],
            'compliance' => [
                'expiring_items' => $expirations,
            ],
            'trip_distribution' => $tripDistribution,
            'incident_stats' => $incidentStats,
            'vehicle_utilisation' => $vehicleUtilData,
            'staff_risk' => $staffRisk,
            'resident_demand' => $residentDemand,
            'trends' => $trends,
        ]);
    }

    public function export(Request $request)
    {
        $type = $request->input('type', 'trips');
        $period = (int) $request->input('period', 30);
        $since = now()->subDays($period);

        AuditLogger::log('fleet-assets.reports.export', null, [
            'period' => $period,
            'type' => $type,
        ]);

        return match ($type) {
            'trips' => $this->exportTrips($since),
            'fuel' => $this->exportFuel($since),
            'maintenance' => $this->exportMaintenance($since),
            'compliance' => $this->exportCompliance(),
            default => back()->with('error', 'Unknown export type'),
        };
    }

    public function reimbursement(Request $request)
    {
        return Inertia::render('fleet-assets/reports/reimbursement');
    }

    public function reimbursementData(Request $request)
    {
        $period = $request->input('period', 'this_month');
        $rate = (float) $request->input('rate', config('fleet.reimbursement_rate_per_km'));

        $startDate = match ($period) {
            'this_month' => now()->startOfMonth(),
            'last_month' => now()->subMonth()->startOfMonth(),
            'custom' => $request->filled('from') ? \Carbon\Carbon::parse($request->input('from'))->startOfDay() : now()->startOfMonth(),
            default => now()->startOfMonth(),
        };

        $endDate = match ($period) {
            'last_month' => now()->subMonth()->endOfMonth(),
            'custom' => $request->filled('to') ? \Carbon\Carbon::parse($request->input('to'))->endOfDay() : now()->endOfDay(),
            default => now()->endOfDay(),
        };

        $vehicleIds = Asset::vehicles()->pluck('id');

        $reimbursementTripQuery = FleetTrip::query()
            ->whereIn('asset_id', $vehicleIds)
            ->whereBetween('started_at', [$startDate, $endDate])
            ->whereNotNull('distance_km');
        if (Schema::hasColumn('fleet_trips', 'is_personal')) {
            $reimbursementTripQuery->where('is_personal', false);
        }
        $staff = $reimbursementTripQuery
            ->with('driverSession.user:id,name')
            ->get()
            ->groupBy(fn ($t) => $t->driverSession?->user_id ?? 0)
            ->map(function ($trips, $userId) use ($rate) {
                $user = $trips->first()->driverSession?->user;

                return [
                    'name' => $user?->name ?? 'Unknown',
                    'trips' => $trips->count(),
                    'distance_km' => round((float) $trips->sum('distance_km'), 1),
                    'amount' => round((float) $trips->sum('distance_km') * $rate, 2),
                ];
            })
            ->filter(fn ($r) => $r['distance_km'] > 0)
            ->sortByDesc('distance_km')
            ->values();

        return response()->json(['staff' => $staff]);
    }

    private function exportTrips($since)
    {
        $vehicleIds = Asset::vehicles()->pluck('id');
        $exportTripQuery = FleetTrip::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('started_at', '>=', $since);
        if (Schema::hasColumn('fleet_trips', 'is_personal')) {
            $exportTripQuery->where('is_personal', false);
        }
        $trips = $exportTripQuery
            ->with(['asset:id,name,asset_tag', 'driverSession.user:id,name'])
            ->orderByDesc('started_at')
            ->get();

        return response()->streamDownload(function () use ($trips) {
            $handle = fopen('php://output', 'w');
            $this->putCsv($handle, ['Trip ID', 'Vehicle', 'Driver', 'Started At', 'Ended At', 'Distance (km)', 'Duration (min)', 'Status']);
            foreach ($trips as $trip) {
                $durationMin = $trip->duration_s ? round($trip->duration_s / 60, 0) : '';
                $this->putCsv($handle, [
                    $trip->id,
                    $trip->asset?->name ?? '',
                    $trip->driverSession?->user?->name ?? '',
                    $trip->started_at?->toDateTimeString() ?? '',
                    $trip->ended_at?->toDateTimeString() ?? '',
                    $trip->distance_km ?? '',
                    $durationMin,
                    $trip->status ?? '',
                ]);
            }
            fclose($handle);
        }, 'fleet-trips-' . now()->format('Y-m-d') . '.csv');
    }

    private function exportFuel($since)
    {
        $vehicleIds = Asset::vehicles()->pluck('id');
        $logs = FleetFuelLog::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('logged_at', '>=', $since)
            ->with(['asset:id,name,asset_tag', 'user:id,name'])
            ->orderByDesc('logged_at')
            ->get();

        return response()->streamDownload(function () use ($logs) {
            $handle = fopen('php://output', 'w');
            $this->putCsv($handle, ['Log ID', 'Vehicle', 'User', 'Date', 'Fuel Type', 'Litres', 'Cost Per Litre', 'Total Cost', 'Odometer (km)']);
            foreach ($logs as $log) {
                $this->putCsv($handle, [
                    $log->id,
                    $log->asset?->name ?? '',
                    $log->user?->name ?? '',
                    $log->logged_at?->toDateTimeString() ?? '',
                    $log->fuel_type ?? '',
                    $log->quantity_litres ?? '',
                    $log->cost_per_litre ?? '',
                    $log->total_cost ?? '',
                    $log->odometer_km ?? '',
                ]);
            }
            fclose($handle);
        }, 'fleet-fuel-' . now()->format('Y-m-d') . '.csv');
    }

    private function exportMaintenance($since)
    {
        $vehicleIds = Asset::vehicles()->pluck('id');
        $workOrders = FleetWorkOrder::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('created_at', '>=', $since)
            ->with(['asset:id,name,asset_tag', 'reportedBy:id,name', 'assignedTo:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return response()->streamDownload(function () use ($workOrders) {
            $handle = fopen('php://output', 'w');
            $this->putCsv($handle, ['WO ID', 'Vehicle', 'Title', 'Priority', 'Status', 'Reported By', 'Assigned To', 'Due At', 'Estimated Cost', 'Actual Cost']);
            foreach ($workOrders as $wo) {
                $this->putCsv($handle, [
                    $wo->id,
                    $wo->asset?->name ?? '',
                    $wo->title ?? '',
                    $wo->priority ?? '',
                    $wo->status ?? '',
                    $wo->reportedBy?->name ?? '',
                    $wo->assignedTo?->name ?? '',
                    $wo->due_at?->toDateTimeString() ?? '',
                    $wo->estimated_cost ?? '',
                    $wo->actual_cost ?? '',
                ]);
            }
            fclose($handle);
        }, 'fleet-maintenance-' . now()->format('Y-m-d') . '.csv');
    }

    private function exportCompliance()
    {
        $vehicles = Asset::vehicles()
            ->get(['id', 'name', 'asset_tag', 'registration_number', 'wof_expires_at', 'registration_expires_at', 'cof_expires_at']);

        return response()->streamDownload(function () use ($vehicles) {
            $handle = fopen('php://output', 'w');
            $this->putCsv($handle, ['Vehicle', 'Asset Tag', 'Registration Number', 'WOF Expires', 'Registration Expires', 'COF Expires']);
            foreach ($vehicles as $v) {
                $this->putCsv($handle, [
                    $v->name ?? '',
                    $v->asset_tag ?? '',
                    $v->registration_number ?? '',
                    $v->wof_expires_at?->toDateString() ?? '',
                    $v->registration_expires_at?->toDateString() ?? '',
                    $v->cof_expires_at?->toDateString() ?? '',
                ]);
            }
            fclose($handle);
        }, 'fleet-compliance-' . now()->format('Y-m-d') . '.csv');
    }

    public function byHouse(Request $request)
    {
        $hasHomeSite = Schema::hasColumn('assets', 'home_site_id');
        $filterPersonal = Schema::hasColumn('fleet_trips', 'is_personal');

        $houses = Site::query()
            ->where('type', 'house')
            ->orderBy('name')
            ->get(['id', 'name']);

        $selectedHouseId = $request->filled('house_id') ? (int) $request->input('house_id') : null;

        $monthParam = $request->input('month', now()->format('Y-m'));
        $monthStart = \Carbon\Carbon::createFromFormat('Y-m', $monthParam)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        // Generate last 12 months for the selector
        $availableMonths = [];
        for ($i = 0; $i < 12; $i++) {
            $m = now()->subMonths($i);
            $availableMonths[] = [
                'value' => $m->format('Y-m'),
                'label' => $m->format('F Y'),
            ];
        }

        // Per-house summary data
        $houseSummaries = [];

        foreach ($houses as $house) {
            $vehicleIds = $hasHomeSite
                ? Asset::vehicles()->where('home_site_id', $house->id)->pluck('id')
                : collect();

            $tripsThisMonth = 0;
            $distanceThisMonth = 0;
            if ($vehicleIds->isNotEmpty()) {
                $houseTripQuery = FleetTrip::whereIn('asset_id', $vehicleIds)->whereBetween('started_at', [$monthStart, $monthEnd]);
                if ($filterPersonal) {
                    $houseTripQuery->where('is_personal', false);
                }
                $tripsThisMonth = (clone $houseTripQuery)->count();
                $distanceThisMonth = round((float) (clone $houseTripQuery)->sum('distance_km'), 1);
            }

            $fuelCostThisMonth = $vehicleIds->isNotEmpty()
                ? round((float) FleetFuelLog::whereIn('asset_id', $vehicleIds)->whereBetween('logged_at', [$monthStart, $monthEnd])->sum('total_cost'), 2)
                : 0;

            $transportLogs = $vehicleIds->isNotEmpty()
                ? FleetResidentTransport::whereIn('asset_id', $vehicleIds)->whereBetween('departed_at', [$monthStart, $monthEnd])->count()
                : 0;

            $houseSummaries[] = [
                'id' => $house->id,
                'name' => $house->name,
                'vehicles_count' => $vehicleIds->count(),
                'trips_this_month' => $tripsThisMonth,
                'distance_this_month' => $distanceThisMonth,
                'fuel_cost_this_month' => $fuelCostThisMonth,
                'transport_logs' => $transportLogs,
            ];
        }

        // Detailed vehicle data for selected house
        $vehicleDetails = [];
        if ($selectedHouseId && $hasHomeSite) {
            $vehicles = Asset::vehicles()
                ->where('home_site_id', $selectedHouseId)
                ->get(['id', 'name', 'asset_tag']);

            foreach ($vehicles as $vehicle) {
                $trips = FleetTrip::where('asset_id', $vehicle->id)
                    ->whereBetween('started_at', [$monthStart, $monthEnd]);
                if ($filterPersonal) {
                    $trips->where('is_personal', false);
                }

                $tripCount = (clone $trips)->count();
                $distance = round((float) (clone $trips)->sum('distance_km'), 1);
                $totalDurationS = (clone $trips)->sum('duration_s');
                $avgDuration = $tripCount > 0 ? round($totalDurationS / $tripCount / 60, 1) : 0;

                $fuelCost = round((float) FleetFuelLog::where('asset_id', $vehicle->id)
                    ->whereBetween('logged_at', [$monthStart, $monthEnd])
                    ->sum('total_cost'), 2);

                $lastTrip = FleetTrip::where('asset_id', $vehicle->id)
                    ->latest('started_at')
                    ->first();

                $vehicleDetails[] = [
                    'id' => $vehicle->id,
                    'name' => $vehicle->name,
                    'asset_tag' => $vehicle->asset_tag,
                    'trips' => $tripCount,
                    'distance_km' => $distance,
                    'fuel_cost' => $fuelCost,
                    'avg_trip_duration_minutes' => $avgDuration,
                    'last_used' => $lastTrip?->started_at?->toISOString(),
                ];
            }
        }

        AuditLogger::log('fleet-assets.reports.by-house', null, [
            'house_id' => $selectedHouseId,
        ]);

        return Inertia::render('fleet-assets/reports/by-house', [
            'houses' => $houses->map(fn ($h) => ['id' => $h->id, 'name' => $h->name]),
            'selected_house_id' => $selectedHouseId,
            'selected_month' => $monthParam,
            'available_months' => $availableMonths,
            'house_summaries' => $houseSummaries,
            'vehicle_details' => $vehicleDetails,
        ]);
    }
}
