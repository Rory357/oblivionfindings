<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetDriverSession;
use App\Models\FleetDrivingMetric;
use App\Models\FleetTrip;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DriverController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()
            ->whereHas('hrDriverEligibility')
            ->with('hrDriverEligibility');

        // CSV export
        if ($request->input('export') === 'csv') {
            $allDrivers = (clone $query)->orderBy('name')->limit(5000)->get();
            return response()->streamDownload(function () use ($allDrivers) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Name', 'Email', 'Licence Class', 'Licence Expires', 'Status', 'Can Drive Clients']);
                foreach ($allDrivers as $u) {
                    $e = $u->hrDriverEligibility;
                    fputcsv($handle, [
                        $u->name, $u->email,
                        $e?->licence_class ?? '', optional($e?->licence_expires_at)->format('Y-m-d') ?? '',
                        $e?->status ?? '', $e?->can_drive_clients ? 'Yes' : 'No',
                    ]);
                }
                fclose($handle);
            }, 'drivers-export.csv');
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status === 'expiring_soon') {
                $query->whereHas('hrDriverEligibility', fn ($q) => $q
                    ->where('licence_expires_at', '>=', now())
                    ->where('licence_expires_at', '<=', now()->addDays(60))
                );
            } else {
                $query->whereHas('hrDriverEligibility', fn ($q) => $q->where('status', $status));
            }
        }

        // Sorting
        $allowedSorts = ['name', 'email'];
        $sort = $request->input('sort', 'name');
        $direction = $request->input('direction', 'asc');
        if (!in_array($sort, $allowedSorts)) $sort = 'name';
        if (!in_array($direction, ['asc', 'desc'])) $direction = 'asc';
        $query->orderBy($sort, $direction);

        $drivers = $query->paginate(25)->withQueryString();

        $driverIds = $drivers->getCollection()->pluck('id')->all();
        $assignedVehicles = Asset::query()
            ->whereIn('primary_driver_user_id', $driverIds)
            ->get(['id', 'name', 'asset_tag', 'primary_driver_user_id'])
            ->groupBy('primary_driver_user_id');

        // Get trip counts per driver
        $tripCounts = FleetDriverSession::query()
            ->whereIn('user_id', $driverIds)
            ->selectRaw('user_id, COUNT(*) as session_count')
            ->groupBy('user_id')
            ->pluck('session_count', 'user_id');

        return Inertia::render('fleet-assets/drivers/index', [
            'drivers' => [
                'data' => $drivers->getCollection()->map(fn ($user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'eligibility' => $user->hrDriverEligibility ? [
                        'licence_class' => $user->hrDriverEligibility->licence_class,
                        'licence_expires_at' => optional($user->hrDriverEligibility->licence_expires_at)->toDateString(),
                        'status' => $user->hrDriverEligibility->status,
                        'can_drive_clients' => $user->hrDriverEligibility->can_drive_clients,
                    ] : null,
                    'assigned_vehicles' => ($assignedVehicles->get($user->id) ?? collect())->map(fn ($v) => [
                        'id' => $v->id,
                        'name' => $v->name,
                        'asset_tag' => $v->asset_tag,
                    ])->values(),
                    'session_count' => $tripCounts->get($user->id, 0),
                ])->values(),
                'links' => $drivers->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $drivers->currentPage(),
                    'last_page' => $drivers->lastPage(),
                    'total' => $drivers->total(),
                ],
            ],
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function show(Request $request, User $user)
    {
        $user->load('hrDriverEligibility');

        // primary_driver_user_id column requires fleet migrations
        $assignedVehicles = collect();

        $sessions = FleetDriverSession::query()
            ->where('user_id', $user->id)
            ->with('asset:id,name,asset_tag')
            ->latest('started_at')
            ->limit(20)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'asset' => $s->asset ? ['id' => $s->asset->id, 'name' => $s->asset->name] : null,
                'started_at' => optional($s->started_at)->toISOString(),
                'ended_at' => optional($s->ended_at)->toISOString(),
                'status' => $s->status,
            ])->values();

        $drivingMetrics = FleetDrivingMetric::query()
            ->whereIn('asset_id', $assignedVehicles->pluck('id'))
            ->latest('period_start')
            ->limit(10)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'period_start' => optional($m->period_start)->toDateString(),
                'period_end' => optional($m->period_end)->toDateString(),
                'harsh_brake_count' => $m->harsh_brake_count,
                'accel_count' => $m->accel_count,
                'speeding_events' => $m->speeding_events,
                'idle_minutes' => $m->idle_minutes,
                'score' => $m->score,
            ])->values();

        $recentTrips = FleetTrip::query()
            ->whereHas('driverSession', fn ($q) => $q->where('user_id', $user->id))
            ->with('asset:id,name')
            ->latest('started_at')
            ->limit(20)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'asset' => $t->asset ? ['id' => $t->asset->id, 'name' => $t->asset->name] : null,
                'started_at' => optional($t->started_at)->toISOString(),
                'ended_at' => optional($t->ended_at)->toISOString(),
                'distance_km' => $t->distance_km,
                'status' => $t->status,
            ])->values();

        return Inertia::render('fleet-assets/drivers/show', [
            'driver' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'eligibility' => $user->hrDriverEligibility,
                'hr_status' => $user->status ?? null,
            ],
            'assigned_vehicles' => $assignedVehicles,
            'sessions' => $sessions,
            'driving_metrics' => $drivingMetrics,
            'recent_trips' => $recentTrips,
        ]);
    }

    public function scorecard(Request $request, User $user)
    {
        $user->load('hrDriverEligibility');

        $period = $request->input('period', '30');
        $days = (int) $period;
        $periodStart = now()->subDays($days)->startOfDay();
        $previousPeriodStart = now()->subDays($days * 2)->startOfDay();

        // Get driving metrics for this driver's sessions
        $sessionAssetIds = FleetDriverSession::query()
            ->where('user_id', $user->id)
            ->pluck('asset_id')
            ->unique();

        $currentMetrics = FleetDrivingMetric::query()
            ->whereIn('asset_id', $sessionAssetIds)
            ->where('period_start', '>=', $periodStart)
            ->get();

        $previousMetrics = FleetDrivingMetric::query()
            ->whereIn('asset_id', $sessionAssetIds)
            ->where('period_start', '>=', $previousPeriodStart)
            ->where('period_start', '<', $periodStart)
            ->get();

        $currentScore = $currentMetrics->avg('score') ?? 0;
        $previousScore = $previousMetrics->avg('score') ?? 0;

        $totalDistance = FleetTrip::query()
            ->whereHas('driverSession', fn ($q) => $q->where('user_id', $user->id))
            ->where('started_at', '>=', $periodStart)
            ->sum('distance_km');

        // Fleet average for comparison
        $fleetAvgScore = FleetDrivingMetric::query()
            ->where('period_start', '>=', $periodStart)
            ->avg('score') ?? 0;

        // Recent driving events (signals)
        $recentEvents = \App\Models\FleetSignal::query()
            ->whereIn('asset_id', $sessionAssetIds)
            ->where('occurred_at', '>=', $periodStart)
            ->whereIn('signal_type', ['harsh_brake', 'harsh_accel', 'speeding', 'idle'])
            ->latest('occurred_at')
            ->limit(20)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'type' => $s->signal_type,
                'severity' => $s->severity_hint,
                'occurred_at' => optional($s->occurred_at)->toISOString(),
                'asset_id' => $s->asset_id,
            ])->values();

        return Inertia::render('fleet-assets/drivers/scorecard', [
            'driver' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'period' => $period,
            'score' => round($currentScore),
            'previous_score' => round($previousScore),
            'fleet_avg_score' => round($fleetAvgScore),
            'metrics' => [
                'harsh_brakes' => $currentMetrics->sum('harsh_brake_count'),
                'hard_accels' => $currentMetrics->sum('accel_count'),
                'speeding_events' => $currentMetrics->sum('speeding_events'),
                'idle_minutes' => $currentMetrics->sum('idle_minutes'),
                'total_distance_km' => round((float) $totalDistance, 1),
            ],
            'recent_events' => $recentEvents,
        ]);
    }
}
