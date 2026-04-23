<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetServiceSchedule;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class ServiceScheduleController extends Controller
{
    public function index(Request $request)
    {
        $canManage = $this->canManageMaintenance($request);

        // Sorting
        $allowedSorts = ['name', 'next_due_at', 'created_at'];
        $sort = $request->input('sort', 'next_due_at');
        $direction = $request->input('direction', 'asc');
        if (!in_array($sort, $allowedSorts)) $sort = 'next_due_at';
        if (!in_array($direction, ['asc', 'desc'])) $direction = 'asc';

        $schedules = FleetServiceSchedule::query()
            ->with('asset:id,name,asset_tag,category')
            ->orderBy($sort, $direction)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'asset' => $s->asset ? [
                    'id' => $s->asset->id,
                    'name' => $s->asset->name,
                    'asset_tag' => $s->asset->asset_tag,
                ] : null,
                'name' => $s->name,
                'interval_km' => $s->interval_km,
                'interval_days' => $s->interval_days,
                'last_completed_at' => optional($s->last_completed_at)->toDateString(),
                'last_completed_km' => $s->last_completed_km,
                'next_due_at' => optional($s->next_due_at)->toDateString(),
                'next_due_km' => $s->next_due_km,
                'is_overdue' => $s->next_due_at && $s->next_due_at->isPast(),
                'created_at' => optional($s->created_at)->toISOString(),
            ])->values();

        // KPI calculations
        $totalCount = $schedules->count();
        $overdueCount = $schedules->where('is_overdue', true)->count();
        $now = Carbon::now();
        $dueSoonCount = $schedules->filter(function ($s) use ($now) {
            if (!$s['next_due_at'] || $s['is_overdue']) return false;
            $dueDate = Carbon::parse($s['next_due_at']);
            return $dueDate->diffInDays($now, false) <= 14 && $dueDate->isFuture();
        })->count();
        $onTrackCount = $totalCount - $overdueCount - $dueSoonCount;

        // Fleet health percentage
        $fleetHealthPct = round(($onTrackCount / max($totalCount, 1)) * 100);

        // Schedules per vehicle
        $schedulesPerVehicle = [];
        try {
            $schedulesPerVehicle = FleetServiceSchedule::query()
                ->with('asset:id,name')
                ->get()
                ->filter(fn ($s) => $s->asset !== null)
                ->groupBy(fn ($s) => $s->asset->name)
                ->map(fn ($group, $name) => ['label' => $name, 'value' => $group->count()])
                ->values()
                ->sortByDesc('value')
                ->values()
                ->take(10)
                ->toArray();
        } catch (\Throwable $e) {
            $schedulesPerVehicle = [];
        }

        // Monthly completions (last 6 months)
        $monthlyCompletions = [];
        try {
            $sixMonthsAgo = Carbon::now()->subMonths(6)->startOfMonth();
            $completedSchedules = FleetServiceSchedule::query()
                ->whereNotNull('last_completed_at')
                ->where('last_completed_at', '>=', $sixMonthsAgo)
                ->get();

            $months = collect();
            for ($i = 5; $i >= 0; $i--) {
                $month = Carbon::now()->subMonths($i);
                $months->push($month);
            }

            $monthlyCompletions = $months->map(function ($month) use ($completedSchedules) {
                $count = $completedSchedules->filter(function ($s) use ($month) {
                    $completedAt = Carbon::parse($s->last_completed_at);
                    return $completedAt->month === $month->month && $completedAt->year === $month->year;
                })->count();
                return ['label' => $month->format('M'), 'value' => $count];
            })->toArray();
        } catch (\Throwable $e) {
            $monthlyCompletions = [];
        }

        // Upcoming timeline (next 30 days)
        $upcomingTimeline = [];
        try {
            $upcomingTimeline = FleetServiceSchedule::query()
                ->with('asset:id,name')
                ->whereNotNull('next_due_at')
                ->where('next_due_at', '<=', Carbon::now()->addDays(30))
                ->orderBy('next_due_at', 'asc')
                ->get()
                ->map(function ($s) use ($now) {
                    $dueDate = Carbon::parse($s->next_due_at);
                    $daysUntil = (int) $now->diffInDays($dueDate, false);
                    $type = 'normal';
                    if ($dueDate->isPast()) {
                        $type = 'overdue';
                        $daysUntil = -1 * abs($daysUntil);
                    } elseif ($daysUntil <= 14) {
                        $type = 'soon';
                    }
                    return [
                        'id' => $s->id,
                        'name' => $s->name,
                        'vehicle' => $s->asset?->name ?? 'Unassigned',
                        'due_at' => $dueDate->toDateString(),
                        'days_until' => $daysUntil,
                        'type' => $type,
                    ];
                })
                ->values()
                ->toArray();
        } catch (\Throwable $e) {
            $upcomingTimeline = [];
        }

        // Assets list for create dialog
        $assets = [];
        try {
            if (Schema::hasTable('assets')) {
                $assets = Asset::query()
                    ->select('id', 'name')
                    ->orderBy('name')
                    ->get()
                    ->toArray();
            }
        } catch (\Throwable $e) {
            $assets = [];
        }

        return Inertia::render('fleet-assets/maintenance/schedules/index', [
            'schedules' => $schedules,
            'assets' => $assets,
            'fleet_health_pct' => $fleetHealthPct,
            'schedules_per_vehicle' => $schedulesPerVehicle,
            'monthly_completions' => $monthlyCompletions,
            'upcoming_timeline' => $upcomingTimeline,
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    public function markComplete(Request $request, FleetServiceSchedule $schedule)
    {
        $schedule->update([
            'last_completed_at' => Carbon::now(),
            'last_completed_km' => $request->input('last_completed_km', $schedule->next_due_km),
            'next_due_at' => $schedule->interval_days
                ? Carbon::now()->addDays($schedule->interval_days)
                : null,
            'next_due_km' => $schedule->interval_km && $schedule->next_due_km
                ? $schedule->next_due_km + $schedule->interval_km
                : null,
        ]);

        AuditLogger::log('fleet.service_schedule.mark_complete', $schedule, [
            'schedule_id' => $schedule->id,
        ]);

        return back()->with('success', 'Schedule marked as completed.');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'name' => ['required', 'string', 'max:255'],
            'interval_km' => ['nullable', 'integer', 'min:1'],
            'interval_days' => ['nullable', 'integer', 'min:1'],
            'last_completed_at' => ['nullable', 'date'],
            'last_completed_km' => ['nullable', 'numeric', 'min:0'],
            'next_due_at' => ['nullable', 'date'],
            'next_due_km' => ['nullable', 'numeric', 'min:0'],
        ]);

        $schedule = FleetServiceSchedule::create($data);

        AuditLogger::log('fleet.service_schedule.create', $schedule, [
            'asset_id' => $data['asset_id'],
            'name' => $data['name'],
        ]);

        return back()->with('success', 'Service schedule created.');
    }

    public function update(Request $request, FleetServiceSchedule $schedule)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'interval_km' => ['nullable', 'integer', 'min:1'],
            'interval_days' => ['nullable', 'integer', 'min:1'],
            'last_completed_at' => ['nullable', 'date'],
            'last_completed_km' => ['nullable', 'numeric', 'min:0'],
            'next_due_at' => ['nullable', 'date'],
            'next_due_km' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $schedule->update($data);

        AuditLogger::log('fleet.service_schedule.update', $schedule, [
            'schedule_id' => $schedule->id,
        ]);

        return back()->with('success', 'Service schedule updated.');
    }

    private function canManageMaintenance(Request $request): bool
    {
        $user = $request->user();

        return (bool) ($user?->canDo('fleet.manage') || $user?->canDo('fleet.maintenance.manage'));
    }
}
