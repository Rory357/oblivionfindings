<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetDriverSession;
use App\Models\FleetFuelLog;
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

        // Vehicle utilization - trips per vehicle
        $utilization = FleetTrip::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('started_at', '>=', $startDate)
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

        // Compliance - upcoming expirations (requires fleet migrations)
        $expirations = collect();

        // Trip summary stats
        $tripStats = FleetTrip::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('started_at', '>=', $startDate)
            ->selectRaw('
                COUNT(*) as total_trips,
                SUM(distance_km) as total_distance_km,
                SUM(duration_s) as total_duration_s
            ')
            ->first();

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
        $rate = (float) $request->input('rate', 0.95);

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

        $staff = FleetTrip::query()
            ->whereIn('asset_id', $vehicleIds)
            ->whereBetween('started_at', [$startDate, $endDate])
            ->whereNotNull('distance_km')
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
        $trips = FleetTrip::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('started_at', '>=', $since)
            ->with(['asset:id,name,asset_tag', 'driverSession.user:id,name'])
            ->orderByDesc('started_at')
            ->get();

        return response()->streamDownload(function () use ($trips) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Trip ID', 'Vehicle', 'Driver', 'Started At', 'Ended At', 'Distance (km)', 'Duration (min)', 'Status']);
            foreach ($trips as $trip) {
                $durationMin = $trip->duration_s ? round($trip->duration_s / 60, 0) : '';
                fputcsv($handle, [
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
            fputcsv($handle, ['Log ID', 'Vehicle', 'User', 'Date', 'Fuel Type', 'Litres', 'Cost Per Litre', 'Total Cost', 'Odometer (km)']);
            foreach ($logs as $log) {
                fputcsv($handle, [
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
            fputcsv($handle, ['WO ID', 'Vehicle', 'Title', 'Priority', 'Status', 'Reported By', 'Assigned To', 'Due At', 'Estimated Cost', 'Actual Cost']);
            foreach ($workOrders as $wo) {
                fputcsv($handle, [
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
            ->get(['id', 'name', 'asset_tag', 'rego_number', 'wof_expires_at', 'rego_expires_at', 'cof_expires_at']);

        return response()->streamDownload(function () use ($vehicles) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Vehicle', 'Asset Tag', 'Rego Number', 'WOF Expires', 'Rego Expires', 'COF Expires']);
            foreach ($vehicles as $v) {
                fputcsv($handle, [
                    $v->name ?? '',
                    $v->asset_tag ?? '',
                    $v->rego_number ?? '',
                    $v->wof_expires_at?->toDateString() ?? '',
                    $v->rego_expires_at?->toDateString() ?? '',
                    $v->cof_expires_at?->toDateString() ?? '',
                ]);
            }
            fclose($handle);
        }, 'fleet-compliance-' . now()->format('Y-m-d') . '.csv');
    }

    public function byHouse(Request $request)
    {
        $hasHomeSite = Schema::hasColumn('assets', 'home_site_id');

        $houses = Site::query()
            ->where('type', 'house')
            ->orderBy('name')
            ->get(['id', 'name']);

        $selectedHouseId = $request->filled('house_id') ? (int) $request->input('house_id') : null;

        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        // Per-house summary data
        $houseSummaries = [];

        foreach ($houses as $house) {
            $vehicleIds = $hasHomeSite
                ? Asset::vehicles()->where('home_site_id', $house->id)->pluck('id')
                : collect();

            $tripsThisMonth = $vehicleIds->isNotEmpty()
                ? FleetTrip::whereIn('asset_id', $vehicleIds)->whereBetween('started_at', [$monthStart, $monthEnd])->count()
                : 0;

            $distanceThisMonth = $vehicleIds->isNotEmpty()
                ? round((float) FleetTrip::whereIn('asset_id', $vehicleIds)->whereBetween('started_at', [$monthStart, $monthEnd])->sum('distance_km'), 1)
                : 0;

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
            'house_summaries' => $houseSummaries,
            'vehicle_details' => $vehicleDetails,
        ]);
    }
}
