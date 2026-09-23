<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\FleetServiceSchedule;
use App\Services\Fleet\VehicleServiceScheduleService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ServiceScheduleController extends Controller
{
    public function index(Request $request)
    {
        $canManage = $this->canManageMaintenance($request);
        // Only schedules for vehicles this user can see (the list was unscoped).
        $user = $request->user() ?? abort(403);
        $scoped = fn () => FleetServiceSchedule::query()->whereIn('asset_id',
            app(SecurityDevicesAccessService::class)->accessibleVehiclesForFleet($user)->select('assets.id'));

        // Sorting
        $allowedSorts = ['name', 'next_due_at', 'created_at'];
        $sort = $request->input('sort', 'next_due_at');
        $direction = $request->input('direction', 'asc');
        if (!in_array($sort, $allowedSorts)) $sort = 'next_due_at';
        if (!in_array($direction, ['asc', 'desc'])) $direction = 'asc';

        $schedules = $scoped()
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
            $schedulesPerVehicle = $scoped()
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
            $completedSchedules = $scoped()
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
            $upcomingTimeline = $scoped()
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

        // Vehicles for the create dialog: only those this user can see.
        $assets = app(SecurityDevicesAccessService::class)->accessibleVehiclesForFleet($user)
            ->orderBy('name')->get(['assets.id', 'assets.name'])->toArray();

        // Hero band stats — efficient COUNTs (active schedules only)
        $stats = [
            'due_7d' => $scoped()->where('is_active', true)
                ->whereNotNull('next_due_at')
                ->whereBetween('next_due_at', [Carbon::now(), Carbon::now()->addDays(7)])
                ->count(),
            'overdue' => $scoped()->where('is_active', true)
                ->whereNotNull('next_due_at')
                ->where('next_due_at', '<', Carbon::now())
                ->count(),
            'active' => $scoped()->where('is_active', true)->count(),
        ];

        return Inertia::render('fleet-assets/maintenance/schedules/index', [
            'schedules' => $schedules,
            'stats' => $stats,
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

    /**
     * Legacy list action. Records a service today through the same scoped
     * service as the vehicle workspace. The completion reading is the one
     * supplied (never the old due milestone); without one, the next distance
     * trigger is left for the vehicle's owner to record.
     */
    public function markComplete(Request $request, FleetServiceSchedule $schedule)
    {
        $actor = $request->user() ?? abort(403);
        $current = FleetServiceSchedule::query()->whereKey($schedule->getKey())->first() ?? abort(404);
        app(VehicleServiceScheduleService::class)->recordCompletion($actor, (int) $current->asset_id, (int) $current->id, [
            'completed_on' => Carbon::now((string) config('app.worker_timezone', 'Pacific/Auckland'))->toDateString(),
            'odometer_km' => $request->input('last_completed_km'),
            'notes' => 'Marked complete from the service schedules list.',
        ], (int) ($current->lock_version ?? 1), 'legacy-mark-complete-'.$current->id.'-'.Str::uuid());

        return back()->with('success', 'Service recorded. The next due date moved on from today.');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'interval_km' => ['nullable', 'integer', 'min:1'],
            'interval_days' => ['nullable', 'integer', 'min:1'],
            'next_due_at' => ['nullable', 'date'],
            'next_due_km' => ['nullable', 'numeric', 'min:0'],
        ]);
        $actor = $request->user() ?? abort(403);
        app(VehicleServiceScheduleService::class)->save($actor, (int) $data['asset_id'], null, [
            ...$data,
            'next_due_at' => empty($data['next_due_at']) ? null : Carbon::parse($data['next_due_at'])->toDateString(),
        ], null);

        return back()->with('success', 'Service schedule created.');
    }

    public function update(Request $request, FleetServiceSchedule $schedule)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'interval_km' => ['nullable', 'integer', 'min:1'],
            'interval_days' => ['nullable', 'integer', 'min:1'],
            'next_due_at' => ['nullable', 'date'],
            'next_due_km' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]);
        $actor = $request->user() ?? abort(403);
        $current = FleetServiceSchedule::query()->whereKey($schedule->getKey())->first() ?? abort(404);
        app(VehicleServiceScheduleService::class)->save($actor, (int) $current->asset_id, (int) $current->id, [
            'name' => $data['name'] ?? $current->name,
            'interval_km' => array_key_exists('interval_km', $data) ? $data['interval_km'] : $current->interval_km,
            'interval_days' => array_key_exists('interval_days', $data) ? $data['interval_days'] : $current->interval_days,
            'interval_months' => $current->interval_months,
            'next_due_at' => array_key_exists('next_due_at', $data)
                ? ($data['next_due_at'] ? Carbon::parse($data['next_due_at'])->toDateString() : null)
                : $current->next_due_at?->toDateString(),
            'next_due_km' => array_key_exists('next_due_km', $data) ? $data['next_due_km'] : $current->next_due_km,
            'owner_user_id' => $current->owner_user_id,
            'reminder_days_before' => $current->reminder_days_before,
            'reminder_km_before' => $current->reminder_km_before,
            'is_active' => $data['is_active'] ?? $current->is_active,
        ], (int) ($current->lock_version ?? 1));

        return back()->with('success', 'Service schedule updated.');
    }

    private function canManageMaintenance(Request $request): bool
    {
        $user = $request->user();

        return (bool) ($user?->canDo('fleet.manage') || $user?->canDo('fleet.maintenance.manage'));
    }
}
