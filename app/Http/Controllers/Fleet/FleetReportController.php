<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetTrip;
use App\Models\FleetFuelLog;
use App\Models\FleetSignal;
use App\Models\FleetDriverSession;
use App\Models\FleetDrivingMetric;
use App\Models\FleetTelemetryEvent;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class FleetReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('fleet.reports.view'), 403);

        $period = $request->input('period', '30d');
        $startDate = match ($period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
            default => now()->subDays(30),
        };

        // Get vehicle IDs
        $vehicleIds = Asset::query()
            ->where('category', 'vehicle')
            ->orWhereHas('categoryRef', fn($q) => $q->where('slug', 'vehicle'))
            ->pluck('id');

        // Trip statistics
        $tripStats = FleetTrip::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('started_at', '>=', $startDate)
            ->selectRaw('
                COUNT(*) as total_trips,
                SUM(distance_km) as total_distance_km,
                SUM(duration_s) as total_duration_s,
                AVG(distance_km) as avg_distance_km,
                AVG(duration_s) as avg_duration_s
            ')
            ->first();

        // Fuel statistics
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

        // Signal statistics
        $signalStats = FleetSignal::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('occurred_at', '>=', $startDate)
            ->selectRaw('
                COUNT(*) as total_signals,
                signal_type,
                COUNT(*) as count
            ')
            ->groupBy('signal_type')
            ->pluck('count', 'signal_type')
            ->toArray();

        // Trips by vehicle
        $tripsByVehicle = FleetTrip::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('started_at', '>=', $startDate)
            ->with('asset:id,name,asset_tag')
            ->select('asset_id', DB::raw('COUNT(*) as trip_count'), DB::raw('SUM(distance_km) as total_km'))
            ->groupBy('asset_id')
            ->orderByDesc('total_km')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'vehicle' => $row->asset?->name ?? 'Unknown',
                'trips' => $row->trip_count,
                'distance_km' => round((float) $row->total_km, 1),
            ])
            ->toArray();

        // Fuel by vehicle
        $fuelByVehicle = FleetFuelLog::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('logged_at', '>=', $startDate)
            ->with('asset:id,name,asset_tag')
            ->select('asset_id', DB::raw('SUM(quantity_litres) as total_litres'), DB::raw('SUM(total_cost) as total_cost'))
            ->groupBy('asset_id')
            ->orderByDesc('total_cost')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'vehicle' => $row->asset?->name ?? 'Unknown',
                'litres' => round((float) $row->total_litres, 1),
                'cost' => round((float) $row->total_cost, 2),
            ])
            ->toArray();

        // Daily trip trend
        $dailyTrips = FleetTrip::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('started_at', '>=', $startDate)
            ->select(DB::raw('DATE(started_at) as date'), DB::raw('COUNT(*) as count'), DB::raw('SUM(distance_km) as distance'))
            ->groupBy(DB::raw('DATE(started_at)'))
            ->orderBy('date')
            ->get()
            ->map(fn($row) => [
                'date' => $row->date,
                'trips' => $row->count,
                'distance_km' => round((float) $row->distance, 1),
            ])
            ->toArray();

        // Driver sessions
        $driverStats = FleetDriverSession::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('started_at', '>=', $startDate)
            ->with('user:id,name')
            ->select('user_id', DB::raw('COUNT(*) as session_count'), DB::raw('SUM(TIMESTAMPDIFF(MINUTE, started_at, COALESCE(ended_at, NOW()))) as total_minutes'))
            ->groupBy('user_id')
            ->orderByDesc('total_minutes')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'driver' => $row->user?->name ?? 'Unknown',
                'sessions' => $row->session_count,
                'hours' => round((float) $row->total_minutes / 60, 1),
            ])
            ->toArray();

        // Consent enforcement audit (telemetry blocked by consent)
        $consentTotals = FleetTelemetryEvent::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('occurred_at', '>=', $startDate)
            ->selectRaw('COUNT(*) as total_events, SUM(CASE WHEN consent_blocked = 1 THEN 1 ELSE 0 END) as blocked_events')
            ->first();

        $consentByVehicle = FleetTelemetryEvent::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('occurred_at', '>=', $startDate)
            ->where('consent_blocked', true)
            ->with('asset:id,name,asset_tag')
            ->select('asset_id', DB::raw('COUNT(*) as blocked_count'))
            ->groupBy('asset_id')
            ->orderByDesc('blocked_count')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'vehicle' => $row->asset?->name ?? 'Unknown',
                'blocked' => (int) $row->blocked_count,
            ])
            ->toArray();

        // Driving behaviour summary
        $drivingStats = FleetDrivingMetric::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('period_start', '>=', $startDate->toDateString())
            ->selectRaw('
                SUM(harsh_brake_count) as harsh_brake_count,
                SUM(accel_count) as accel_count,
                SUM(speeding_events) as speeding_events,
                SUM(idle_minutes) as idle_minutes,
                AVG(score) as avg_score
            ')
            ->first();

        AuditLogger::log('fleet.reports.view', null, [
            'period' => $period,
        ]);

        return Inertia::render('fleet-management/reports', [
            'period' => $period,
            'trip_stats' => [
                'total_trips' => (int) $tripStats->total_trips,
                'total_distance_km' => round((float) $tripStats->total_distance_km, 1),
                'total_hours' => round((float) $tripStats->total_duration_s / 3600, 1),
                'avg_distance_km' => round((float) $tripStats->avg_distance_km, 1),
                'avg_duration_min' => round((float) $tripStats->avg_duration_s / 60, 0),
            ],
            'fuel_stats' => [
                'total_fill_ups' => (int) $fuelStats->total_fill_ups,
                'total_litres' => round((float) $fuelStats->total_litres, 1),
                'total_cost' => round((float) $fuelStats->total_cost, 2),
                'avg_cost_per_litre' => round((float) $fuelStats->avg_cost_per_litre, 3),
            ],
            'signal_stats' => $signalStats,
            'trips_by_vehicle' => $tripsByVehicle,
            'fuel_by_vehicle' => $fuelByVehicle,
            'daily_trips' => $dailyTrips,
            'driver_stats' => $driverStats,
            'consent_stats' => [
                'total_events' => (int) ($consentTotals->total_events ?? 0),
                'blocked_events' => (int) ($consentTotals->blocked_events ?? 0),
                'blocked_by_vehicle' => $consentByVehicle,
            ],
            'driving_stats' => [
                'harsh_brake_count' => (int) ($drivingStats->harsh_brake_count ?? 0),
                'accel_count' => (int) ($drivingStats->accel_count ?? 0),
                'speeding_events' => (int) ($drivingStats->speeding_events ?? 0),
                'idle_minutes' => (int) ($drivingStats->idle_minutes ?? 0),
                'avg_score' => round((float) ($drivingStats->avg_score ?? 0), 0),
            ],
        ]);
    }

    public function export(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('fleet.reports.view'), 403);

        $period = $request->input('period', '30d');
        $startDate = match ($period) {
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y' => now()->subYear(),
            default => now()->subDays(30),
        };

        $vehicleIds = Asset::query()
            ->where('category', 'vehicle')
            ->orWhereHas('categoryRef', fn($q) => $q->where('slug', 'vehicle'))
            ->pluck('id');

        $trips = FleetTrip::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('started_at', '>=', $startDate)
            ->with(['asset:id,name,asset_tag', 'driverSession.user:id,name'])
            ->orderByDesc('started_at')
            ->get();

        $csv = "Trip ID,Vehicle,Driver,Started At,Ended At,Distance (km),Duration (min),Status\n";

        foreach ($trips as $trip) {
            $durationMin = $trip->duration_s ? round($trip->duration_s / 60, 0) : '';
            $csv .= implode(',', [
                $trip->id,
                '"' . str_replace('"', '""', $trip->asset?->name ?? '') . '"',
                '"' . str_replace('"', '""', $trip->driverSession?->user?->name ?? '') . '"',
                $trip->started_at?->toDateTimeString() ?? '',
                $trip->ended_at?->toDateTimeString() ?? '',
                $trip->distance_km ?? '',
                $durationMin,
                $trip->status ?? '',
            ]) . "\n";
        }

        AuditLogger::log('fleet.reports.export', null, [
            'period' => $period,
            'count' => $trips->count(),
        ]);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="fleet-trips-' . now()->format('Y-m-d') . '.csv"',
        ]);
    }
}
