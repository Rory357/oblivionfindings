<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetServiceSchedule;
use App\Models\FleetTrip;
use App\Models\FleetWorkOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class MaintenanceDashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        if (!Schema::hasTable('fleet_work_orders') || !Schema::hasTable('fleet_service_schedules')) {
            return Inertia::render('fleet-assets/maintenance/dashboard', [
                'period' => (int) $request->input('period', 90),
                'stats' => [
                    'total_work_orders' => 0,
                    'open_work_orders' => 0,
                    'total_spend' => 0,
                    'avg_cost' => 0,
                    'overdue_schedules' => 0,
                ],
                'hero' => [
                    'wo_open' => 0,
                    'wo_overdue' => 0,
                    'wo_in_progress' => 0,
                    'service_due_7d' => 0,
                    'service_due_30d' => 0,
                    'service_overdue' => 0,
                    'month_cost' => 0,
                ],
                'cost_by_vehicle' => [],
                'cost_by_month' => [],
                'cost_by_priority' => [],
                'recent_work_orders' => [],
                'overdue_services' => [],
                'predictions' => [],
                'fleet_health_pct' => 100,
            ]);
        }

        $period = (int) $request->input('period', 90);
        $since = now()->subDays($period);

        // Summary stats
        $totalWorkOrders = FleetWorkOrder::where('created_at', '>=', $since)->count();
        $openWorkOrders = FleetWorkOrder::whereIn('status', ['open', 'in_progress', 'on_hold'])->count();
        $totalSpend = FleetWorkOrder::where('created_at', '>=', $since)->sum('actual_cost') ?? 0;
        $avgCost = $totalWorkOrders > 0 ? round((float) $totalSpend / $totalWorkOrders, 2) : 0;
        $overdueSchedules = FleetServiceSchedule::where('is_active', true)
            ->whereNotNull('next_due_at')
            ->where('next_due_at', '<', now())
            ->count();

        // Command-centre hero counts (state-of-the-world, not period-scoped)
        $woOpen = FleetWorkOrder::where('status', 'open')->count();
        $woInProgress = FleetWorkOrder::where('status', 'in_progress')->count();
        $woOverdue = FleetWorkOrder::whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();
        $serviceDue7d = FleetServiceSchedule::where('is_active', true)
            ->whereNotNull('next_due_at')
            ->whereBetween('next_due_at', [now(), now()->addDays(7)])
            ->count();
        $serviceDue30d = FleetServiceSchedule::where('is_active', true)
            ->whereNotNull('next_due_at')
            ->whereBetween('next_due_at', [now(), now()->addDays(30)])
            ->count();
        $monthCost = (float) FleetWorkOrder::where('created_at', '>=', now()->startOfMonth())
            ->whereNotNull('actual_cost')
            ->sum('actual_cost');

        // Cost by vehicle (top 10)
        $costByVehicle = FleetWorkOrder::query()
            ->select('asset_id', DB::raw('SUM(actual_cost) as total_cost'))
            ->where('created_at', '>=', $since)
            ->whereNotNull('actual_cost')
            ->groupBy('asset_id')
            ->orderByDesc('total_cost')
            ->limit(10)
            ->with('asset:id,name,asset_tag')
            ->get()
            ->map(fn ($row) => [
                'asset_id' => $row->asset_id,
                'asset_name' => $row->asset?->name ?? 'Unknown',
                'asset_tag' => $row->asset?->asset_tag,
                'total_cost' => round((float) $row->total_cost, 2),
            ])
            ->values();

        // Cost by month (last 6 months always)
        $monthsSince = now()->subMonths(6)->startOfMonth();
        $costByMonth = FleetWorkOrder::query()
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                DB::raw('SUM(actual_cost) as total_cost'),
                DB::raw('COUNT(*) as count')
            )
            ->where('created_at', '>=', $monthsSince)
            ->whereNotNull('actual_cost')
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'total_cost' => round((float) $row->total_cost, 2),
                'count' => $row->count,
            ])
            ->values();

        // Cost by priority
        $costByPriority = FleetWorkOrder::query()
            ->select('priority', DB::raw('SUM(actual_cost) as total_cost'), DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', $since)
            ->whereNotNull('actual_cost')
            ->groupBy('priority')
            ->get()
            ->map(fn ($row) => [
                'priority' => $row->priority,
                'total_cost' => round((float) $row->total_cost, 2),
                'count' => $row->count,
            ])
            ->values();

        // Recent work orders (last 10)
        $recentWorkOrders = FleetWorkOrder::query()
            ->with(['asset:id,name,asset_tag'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn ($wo) => [
                'id' => $wo->id,
                'reference_number' => $wo->reference_number,
                'title' => $wo->title,
                'status' => $wo->status,
                'priority' => $wo->priority,
                'asset' => $wo->asset ? ['id' => $wo->asset->id, 'name' => $wo->asset->name] : null,
                'actual_cost' => $wo->actual_cost ? round((float) $wo->actual_cost, 2) : null,
                'created_at' => optional($wo->created_at)->toISOString(),
            ])
            ->values();

        // Overdue service schedules
        $overdueServices = FleetServiceSchedule::query()
            ->where('is_active', true)
            ->whereNotNull('next_due_at')
            ->where('next_due_at', '<', now())
            ->with('asset:id,name,asset_tag')
            ->orderBy('next_due_at')
            ->limit(20)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'asset_name' => $s->asset?->name ?? 'Unknown',
                'asset_tag' => $s->asset?->asset_tag,
                'next_due_at' => optional($s->next_due_at)->toISOString(),
                'days_overdue' => $s->next_due_at ? (int) now()->diffInDays($s->next_due_at) : 0,
            ])
            ->values();

        // Predictive maintenance: estimate days until next service for each vehicle
        $predictions = collect();
        $hasTripsTable = Schema::hasTable('fleet_trips');
        if ($hasTripsTable) {
            $schedules = FleetServiceSchedule::where('is_active', true)
                ->whereNotNull('next_due_km')
                ->with('asset:id,name,asset_tag,odometer_km')
                ->get();

            $thirtyDaysAgo = now()->subDays(30);

            foreach ($schedules as $schedule) {
                $asset = $schedule->asset;
                if (!$asset) continue;

                $currentOdometer = (float) ($asset->odometer_km ?? 0);
                $nextDueKm = (float) $schedule->next_due_km;

                // Average daily km from trips in last 30 days
                $totalTripKm = FleetTrip::where('asset_id', $asset->id)
                    ->where('started_at', '>=', $thirtyDaysAgo)
                    ->sum('distance_km');
                $avgDailyKm = round((float) $totalTripKm / 30, 1);

                $remainingKm = max(0, $nextDueKm - $currentOdometer);
                $predictedDays = $avgDailyKm > 0 ? (int) round($remainingKm / $avgDailyKm) : null;
                $confidence = $avgDailyKm > 5 ? 'high' : ($avgDailyKm > 0 ? 'medium' : 'low');

                $predictions->push([
                    'asset_id' => $asset->id,
                    'asset_name' => $asset->name ?? 'Unknown',
                    'asset_tag' => $asset->asset_tag,
                    'current_km' => round($currentOdometer, 0),
                    'next_service_km' => round($nextDueKm, 0),
                    'avg_daily_km' => $avgDailyKm,
                    'predicted_days' => $predictedDays,
                    'confidence' => $confidence,
                    'schedule_name' => $schedule->name,
                ]);
            }
        }

        // Fleet health: % of vehicles not overdue
        $totalScheduled = FleetServiceSchedule::where('is_active', true)->count();
        $fleetHealthPct = $totalScheduled > 0
            ? round((($totalScheduled - $overdueSchedules) / $totalScheduled) * 100)
            : 100;

        return Inertia::render('fleet-assets/maintenance/dashboard', [
            'period' => $period,
            'stats' => [
                'total_work_orders' => $totalWorkOrders,
                'open_work_orders' => $openWorkOrders,
                'total_spend' => round((float) $totalSpend, 2),
                'avg_cost' => $avgCost,
                'overdue_schedules' => $overdueSchedules,
            ],
            'hero' => [
                'wo_open' => $woOpen,
                'wo_overdue' => $woOverdue,
                'wo_in_progress' => $woInProgress,
                'service_due_7d' => $serviceDue7d,
                'service_due_30d' => $serviceDue30d,
                'service_overdue' => $overdueSchedules,
                'month_cost' => round($monthCost, 2),
            ],
            'cost_by_vehicle' => $costByVehicle,
            'cost_by_month' => $costByMonth,
            'cost_by_priority' => $costByPriority,
            'recent_work_orders' => $recentWorkOrders,
            'overdue_services' => $overdueServices,
            'predictions' => $predictions->values(),
            'fleet_health_pct' => $fleetHealthPct,
        ]);
    }
}
