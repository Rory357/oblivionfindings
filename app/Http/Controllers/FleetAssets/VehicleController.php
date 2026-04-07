<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetDriverSession;
use App\Models\FleetFuelLog;
use App\Models\FleetServiceSchedule;
use App\Models\FleetSignal;
use App\Models\FleetTrip;
use App\Models\FleetIncident;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class VehicleController extends Controller
{
    private function hasFleetFields(): bool
    {
        return Schema::hasColumn('assets', 'home_site_id');
    }

    public function index(Request $request)
    {
        $hasFleetFields = $this->hasFleetFields();

        $eagerLoads = [
            'fleetState',
            'trackers' => fn ($q) => $q->where('status', 'paired'),
        ];
        if ($hasFleetFields) {
            $eagerLoads[] = 'homeSite';
        }

        $query = Asset::vehicles()
            ->with($eagerLoads);

        // CSV export
        if ($request->input('export') === 'csv') {
            $allVehicles = (clone $query)->orderBy('name')->limit(5000)->get();
            return response()->streamDownload(function () use ($allVehicles, $hasFleetFields) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Name', 'Asset Tag', 'Status', 'Home Site', 'Online Status', 'Last Seen']);
                foreach ($allVehicles as $v) {
                    fputcsv($handle, [
                        $v->name,
                        $v->asset_tag,
                        $v->status,
                        $hasFleetFields && $v->homeSite ? $v->homeSite->name : '',
                        $v->fleetState?->status ?? 'offline',
                        optional($v->fleetState?->last_seen_at)->format('Y-m-d H:i:s') ?? '',
                    ]);
                }
                fclose($handle);
            }, 'vehicles-export.csv');
        }

        // Status filter (online/offline based on fleet state)
        if ($request->input('status') === 'online') {
            $query->whereHas('fleetState', fn ($q) => $q->where('status', 'online'));
        } elseif ($request->input('status') === 'offline') {
            $query->where(function ($q) {
                $q->whereDoesntHave('fleetState')
                    ->orWhereHas('fleetState', fn ($sub) => $sub->where('status', '!=', 'online'));
            });
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('asset_tag', 'like', "%{$search}%");
            });
        }

        // Site filter
        if ($request->filled('site_id')) {
            $query->where('site_id', (int) $request->input('site_id'));
        }

        // Sorting
        $allowedSorts = ['name', 'status', 'asset_tag'];
        $sort = $request->input('sort', 'name');
        $direction = $request->input('direction', 'asc');
        if (!in_array($sort, $allowedSorts)) $sort = 'name';
        if (!in_array($direction, ['asc', 'desc'])) $direction = 'asc';
        $query->orderBy($sort, $direction);

        $vehicles = $query->paginate(25)->withQueryString();

        $sites = Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return Inertia::render('fleet-assets/vehicles/index', [
            'vehicles' => [
                'data' => $vehicles->getCollection()->map(fn ($v) => [
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
                        'battery_pct' => $v->fleetState->battery_pct,
                    ] : null,
                    'tracker_count' => $v->trackers->count(),
                ])->values(),
                'links' => $vehicles->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $vehicles->currentPage(),
                    'last_page' => $vehicles->lastPage(),
                    'total' => $vehicles->total(),
                ],
            ],
            'sites' => $sites,
            'filters' => $request->only(['status', 'search', 'site_id']),
        ]);
    }

    public function show(Request $request, Asset $asset)
    {
        $hasFleetFields = $this->hasFleetFields();

        $eagerLoads = [
            'fleetState',
            'trackers',
            'geofences',
            'workOrders' => fn ($q) => $q->latest()->limit(10),
            'bookings' => fn ($q) => $q->latest()->limit(10),
        ];
        if ($hasFleetFields) {
            $eagerLoads[] = 'homeSite';
            $eagerLoads[] = 'primaryDriver';
        }

        $asset->load($eagerLoads);

        $trips = FleetTrip::query()
            ->where('asset_id', $asset->id)
            ->latest('started_at')
            ->limit(20)
            ->get()
            ->map(fn ($trip) => [
                'id' => $trip->id,
                'started_at' => optional($trip->started_at)->toISOString(),
                'ended_at' => optional($trip->ended_at)->toISOString(),
                'distance_km' => $trip->distance_km,
                'duration_s' => $trip->duration_s,
                'status' => $trip->status,
                'start_address' => $trip->start_address,
                'end_address' => $trip->end_address,
            ])->values();

        $signals = FleetSignal::query()
            ->where('asset_id', $asset->id)
            ->latest('occurred_at')
            ->limit(20)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'signal_type' => $s->signal_type,
                'severity' => $s->severity_hint,
                'occurred_at' => optional($s->occurred_at)->toISOString(),
                'payload' => $s->payload,
            ])->values();

        $fuelLogs = FleetFuelLog::query()
            ->where('asset_id', $asset->id)
            ->latest('logged_at')
            ->limit(10)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'logged_at' => optional($log->logged_at)->toISOString(),
                'fuel_type' => $log->fuel_type,
                'quantity_litres' => $log->quantity_litres,
                'total_cost' => $log->total_cost,
                'odometer_km' => $log->odometer_km,
            ])->values();

        $driverSessions = FleetDriverSession::query()
            ->where('asset_id', $asset->id)
            ->with('user:id,name')
            ->latest('started_at')
            ->limit(10)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'driver' => $s->user ? ['id' => $s->user->id, 'name' => $s->user->name] : null,
                'started_at' => optional($s->started_at)->toISOString(),
                'ended_at' => optional($s->ended_at)->toISOString(),
                'status' => $s->status,
            ])->values();

        $sites = Site::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        // Eligible drivers: users who have an HR driver eligibility record
        $eligibleDrivers = User::query()
            ->whereHas('hrDriverEligibility')
            ->with('hrDriverEligibility')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'licence_status' => $u->hrDriverEligibility?->status,
                'licence_expires_at' => optional($u->hrDriverEligibility?->licence_expires_at)->toDateString(),
            ])->values();

        return Inertia::render('fleet-assets/vehicles/show', [
            'asset' => [
                'id' => $asset->id,
                'name' => $asset->name,
                'asset_tag' => $asset->asset_tag,
                'category' => $asset->category,
                'status' => $asset->status,
                'registration_number' => $hasFleetFields ? $asset->registration_number : null,
                'registration_expires_at' => $hasFleetFields ? optional($asset->registration_expires_at)->toDateString() : null,
                'wof_expires_at' => $hasFleetFields ? optional($asset->wof_expires_at)->toDateString() : null,
                'cof_expires_at' => $hasFleetFields ? optional($asset->cof_expires_at)->toDateString() : null,
                'fuel_type' => $hasFleetFields ? $asset->fuel_type : null,
                'odometer_km' => $hasFleetFields ? $asset->odometer_km : null,
                'manufacturer' => $asset->manufacturer,
                'model' => $asset->model,
                'serial_number' => $asset->serial_number,
                'home_site' => $hasFleetFields && $asset->homeSite ? [
                    'id' => $asset->homeSite->id,
                    'name' => $asset->homeSite->name,
                ] : null,
                'primary_driver' => $hasFleetFields && $asset->primaryDriver ? [
                    'id' => $asset->primaryDriver->id,
                    'name' => $asset->primaryDriver->name,
                    'email' => $asset->primaryDriver->email,
                ] : null,
                'trackers' => $asset->trackers->map(fn ($t) => [
                    'id' => $t->id,
                    'vendor' => $t->vendor,
                    'device_uid' => $t->device_uid,
                    'status' => $t->status,
                    'last_seen_at' => optional($t->last_seen_at)->toISOString(),
                ])->values(),
                'has_wheelchair_ramp' => (bool) $asset->has_wheelchair_ramp,
                'has_hoist' => (bool) $asset->has_hoist,
                'has_child_seat_anchors' => (bool) $asset->has_child_seat_anchors,
                'has_medical_storage' => (bool) $asset->has_medical_storage,
                'seating_capacity' => $asset->seating_capacity,
                'accessibility_notes' => $asset->accessibility_notes,
                'inspection_due_at' => optional($asset->inspection_due_at)->toDateString(),
            ],
            'state' => $asset->fleetState ? [
                'status' => $asset->fleetState->status,
                'last_seen_at' => optional($asset->fleetState->last_seen_at)->toISOString(),
                'lat' => $asset->fleetState->latitude,
                'lng' => $asset->fleetState->longitude,
                'speed_kph' => $asset->fleetState->speed_kph,
                'heading_deg' => $asset->fleetState->heading_deg,
                'battery_pct' => $asset->fleetState->battery_pct,
                'consent_blocked' => $asset->fleetState->consent_blocked,
            ] : null,
            'trips' => $trips,
            'signals' => $signals,
            'fuel_logs' => $fuelLogs,
            'driver_sessions' => $driverSessions,
            'geofences' => $asset->geofences->map(fn ($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'type' => $g->type,
                'breach_type' => $g->breach_type,
                'is_active' => $g->is_active,
                'shape' => $g->shape,
            ])->values(),
            'work_orders' => $asset->workOrders,
            'bookings' => $asset->bookings,
            'incidents' => Schema::hasTable('fleet_incidents') ? FleetIncident::where('asset_id', $asset->id)
                ->latest('occurred_at')
                ->limit(10)
                ->get()
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'incident_type' => $i->incident_type,
                    'severity' => $i->severity,
                    'occurred_at' => optional($i->occurred_at)->toISOString(),
                    'status' => $i->status,
                    'location' => $i->location,
                ])
                ->values() : collect(),
            'sites' => $sites,
            'eligible_drivers' => $eligibleDrivers,
            'service_prediction' => $this->buildServicePrediction($asset),
            'timeline' => \Inertia\Inertia::optional(fn () =>
                app(\App\Services\Fleet\FleetTimelineService::class)
                    ->forVehicle($asset->id, now()->subDays(14), 40)
                    ->toArray()
            ),
        ]);
    }

    private function buildServicePrediction(Asset $asset): ?array
    {
        if (!Schema::hasTable('fleet_service_schedules') || !Schema::hasTable('fleet_trips')) {
            return null;
        }

        $schedule = FleetServiceSchedule::where('asset_id', $asset->id)
            ->where('is_active', true)
            ->whereNotNull('next_due_km')
            ->first();

        if (!$schedule) return null;

        $currentOdometer = (float) ($asset->odometer_km ?? 0);
        $nextDueKm = (float) $schedule->next_due_km;
        $thirtyDaysAgo = now()->subDays(30);

        $totalTripKm = FleetTrip::where('asset_id', $asset->id)
            ->where('started_at', '>=', $thirtyDaysAgo)
            ->sum('distance_km');
        $avgDailyKm = round((float) $totalTripKm / 30, 1);

        $remainingKm = max(0, $nextDueKm - $currentOdometer);
        $predictedDays = $avgDailyKm > 0 ? (int) round($remainingKm / $avgDailyKm) : null;

        // km sparkline data from recent trips
        $kmTrend = FleetTrip::where('asset_id', $asset->id)
            ->latest('started_at')
            ->limit(14)
            ->pluck('distance_km')
            ->reverse()
            ->values()
            ->map(fn ($v) => round((float) $v, 1))
            ->toArray();

        return [
            'predicted_days' => $predictedDays,
            'avg_daily_km' => $avgDailyKm,
            'current_km' => round($currentOdometer, 0),
            'next_service_km' => round($nextDueKm, 0),
            'schedule_name' => $schedule->name,
            'km_trend' => $kmTrend,
        ];
    }

    public function update(Request $request, Asset $asset)
    {
        $data = $request->validate([
            'home_site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'primary_driver_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'registration_number' => ['nullable', 'string', 'max:50'],
            'registration_expires_at' => ['nullable', 'date'],
            'wof_expires_at' => ['nullable', 'date'],
            'cof_expires_at' => ['nullable', 'date'],
            'fuel_type' => ['nullable', 'string', 'in:petrol,diesel,electric,hybrid,lpg'],
            'odometer_km' => ['nullable', 'numeric', 'min:0'],
            'inspection_due_at' => ['nullable', 'date'],
            'requires_inspection' => ['nullable', 'boolean'],
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $fleetFields = ['home_site_id', 'primary_driver_user_id', 'registration_number', 'registration_expires_at', 'wof_expires_at', 'cof_expires_at', 'fuel_type', 'odometer_km'];
        if ($this->hasFleetFields()) {
            $safeData = $data;
        } else {
            $safeData = collect($data)->except($fleetFields)->toArray();
        }
        $safeData['updated_by_user_id'] = $request->user()->id;

        $asset->update($safeData);

        AuditLogger::log('fleet.vehicle.update', $asset, [
            'asset_id' => $asset->id,
        ]);

        return back()->with('success', 'Vehicle updated successfully.');
    }

    public function markPersonal(Request $request, FleetTrip $trip)
    {
        $trip->update([
            'is_personal' => !$trip->is_personal,
            'marked_personal_by' => $request->user()->id,
            'marked_personal_at' => now(),
        ]);

        return back()->with('success', $trip->is_personal ? 'Trip marked as personal.' : 'Trip marked as business.');
    }

    public function trips(Request $request)
    {
        $query = FleetTrip::query()
            ->with([
                'asset:id,name,asset_tag',
                'driverSession.user:id,name',
                'segments' => fn ($q) => $q->orderBy('seq'),
            ])
            ->latest('started_at');

        if ($request->filled('vehicle_id')) {
            $query->where('asset_id', (int) $request->input('vehicle_id'));
        }

        // Legacy support
        if ($request->filled('asset_id') && !$request->filled('vehicle_id')) {
            $query->where('asset_id', (int) $request->input('asset_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('started_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('started_at', '<=', $request->input('date_to'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('start_address', 'like', "%{$search}%")
                    ->orWhere('end_address', 'like', "%{$search}%")
                    ->orWhereHas('asset', fn ($sub) => $sub->where('name', 'like', "%{$search}%"));
            });
        }

        // CSV export
        if ($request->input('export') === 'csv') {
            $allTrips = (clone $query)->limit(5000)->get();
            $csv = "Vehicle,Driver,Start Time,End Time,Distance (km),Duration (min),Max Speed (km/h),Start Address,End Address,Status\n";
            foreach ($allTrips as $trip) {
                $driver = $trip->driverSession?->user?->name ?? '';
                $csv .= implode(',', [
                    '"' . ($trip->asset?->name ?? '') . '"',
                    '"' . $driver . '"',
                    '"' . optional($trip->started_at)->format('Y-m-d H:i:s') . '"',
                    '"' . optional($trip->ended_at)->format('Y-m-d H:i:s') . '"',
                    $trip->distance_km ?? 0,
                    round(($trip->duration_s ?? 0) / 60),
                    $trip->max_speed_kph ?? '',
                    '"' . str_replace('"', '""', $trip->start_address ?? '') . '"',
                    '"' . str_replace('"', '""', $trip->end_address ?? '') . '"',
                    $trip->status ?? '',
                ]) . "\n";
            }
            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="trips-export.csv"',
            ]);
        }

        // Summary stats (from the same filtered query, without pagination)
        $totalTrips = (clone $query)->count();
        $totalDistanceKm = round((float) (clone $query)->sum('distance_km'), 1);
        $totalDurationS = (int) (clone $query)->sum('duration_s');
        $avgDistanceKm = round((float) (clone $query)->avg('distance_km'), 1);

        // Average max speed (if column exists)
        $avgSpeedKph = 0;
        try {
            if (Schema::hasColumn('fleet_trips', 'max_speed_kph')) {
                $avgSpeedKph = round((float) (clone $query)->avg('max_speed_kph'), 1);
            }
        } catch (\Throwable $e) {
            $avgSpeedKph = 0;
        }

        // Active trips count
        $activeTrips = 0;
        try {
            $activeTrips = (clone $query)->whereIn('status', ['open', 'in_progress'])->count();
        } catch (\Throwable $e) {
            $activeTrips = 0;
        }

        $summary = [
            'total_trips' => $totalTrips,
            'total_distance_km' => $totalDistanceKm,
            'total_duration_s' => $totalDurationS,
            'avg_speed_kph' => $avgSpeedKph,
            'avg_distance_km' => $avgDistanceKm,
            'active_trips' => $activeTrips,
        ];

        // Trips by day of week (MiniBarChart data)
        $tripsByDay = [];
        try {
            $dayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            $dayQuery = (clone $query);
            $dayCounts = $dayQuery
                ->selectRaw('DAYOFWEEK(started_at) as dow, COUNT(*) as cnt')
                ->groupByRaw('DAYOFWEEK(started_at)')
                ->pluck('cnt', 'dow')
                ->toArray();

            // MySQL DAYOFWEEK: 1=Sun, 2=Mon, ... 7=Sat → remap to Mon-Sun
            $dowMap = [2 => 0, 3 => 1, 4 => 2, 5 => 3, 6 => 4, 7 => 5, 1 => 6];
            foreach ($dayLabels as $i => $label) {
                $mysqlDow = array_search($i, $dowMap);
                $tripsByDay[] = [
                    'label' => $label,
                    'value' => (int) ($dayCounts[$mysqlDow] ?? 0),
                ];
            }
        } catch (\Throwable $e) {
            $tripsByDay = array_map(fn ($l) => ['label' => $l, 'value' => 0], ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']);
        }

        // Top 5 vehicles by distance (HorizontalBarChart data)
        $topVehicles = [];
        try {
            $topVehicles = FleetTrip::query()
                ->selectRaw('asset_id, SUM(distance_km) as total_km')
                ->with('asset:id,name')
                ->groupBy('asset_id')
                ->orderByDesc('total_km')
                ->limit(5)
                ->get()
                ->map(fn ($row) => [
                    'label' => $row->asset?->name ?? 'Unknown',
                    'value' => round((float) $row->total_km, 1),
                ])
                ->values()
                ->toArray();
        } catch (\Throwable $e) {
            $topVehicles = [];
        }

        // Distance trend (last 7 trips sparkline)
        $distanceTrend = [];
        try {
            $distanceTrend = FleetTrip::query()
                ->latest('started_at')
                ->limit(7)
                ->pluck('distance_km')
                ->reverse()
                ->values()
                ->map(fn ($v) => round((float) $v, 1))
                ->toArray();
        } catch (\Throwable $e) {
            $distanceTrend = [];
        }

        // Sorting
        $allowedSorts = ['started_at', 'distance_km', 'duration_s', 'status'];
        $sort = $request->input('sort', 'started_at');
        $direction = $request->input('direction', 'desc');
        if (!in_array($sort, $allowedSorts)) $sort = 'started_at';
        if (!in_array($direction, ['asc', 'desc'])) $direction = 'desc';
        $query->reorder()->orderBy($sort, $direction);

        $trips = $query->paginate(25)->withQueryString();

        // Get vehicles list for filter dropdown
        $vehicles = Asset::vehicles()->orderBy('name')->get(['id', 'name'])
            ->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->values();

        return Inertia::render('fleet-assets/trips/index', [
            'trips' => [
                'data' => $trips->getCollection()->map(fn ($trip) => [
                    'id' => $trip->id,
                    'asset' => $trip->asset ? [
                        'id' => $trip->asset->id,
                        'name' => $trip->asset->name,
                        'asset_tag' => $trip->asset->asset_tag,
                    ] : null,
                    'driver' => $trip->driverSession?->user ? [
                        'id' => $trip->driverSession->user->id,
                        'name' => $trip->driverSession->user->name,
                    ] : null,
                    'started_at' => optional($trip->started_at)->toISOString(),
                    'ended_at' => optional($trip->ended_at)->toISOString(),
                    'distance_km' => $trip->distance_km,
                    'duration_s' => $trip->duration_s,
                    'max_speed_kph' => $trip->max_speed_kph ?? null,
                    'status' => $trip->status,
                    'is_personal' => (bool) $trip->is_personal,
                    'start_address' => $trip->start_address,
                    'end_address' => $trip->end_address,
                    'start_latitude' => $trip->start_latitude,
                    'start_longitude' => $trip->start_longitude,
                    'end_latitude' => $trip->end_latitude,
                    'end_longitude' => $trip->end_longitude,
                    'segments' => $trip->segments->map(fn ($seg) => [
                        'id' => $seg->id,
                        'seq' => $seg->seq,
                        'started_at' => optional($seg->started_at)->toISOString(),
                        'ended_at' => optional($seg->ended_at)->toISOString(),
                        'distance_km' => $seg->distance_km,
                        'duration_s' => $seg->duration_s,
                        'polyline' => $seg->polyline ? json_decode($seg->polyline, true) : null,
                    ])->values(),
                ])->values(),
                'links' => $trips->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $trips->currentPage(),
                    'last_page' => $trips->lastPage(),
                    'total' => $trips->total(),
                ],
            ],
            'vehicles' => $vehicles,
            'filters' => $request->only(['date_from', 'date_to', 'vehicle_id', 'status', 'search']),
            'summary' => $summary,
            'trips_by_day' => $tripsByDay,
            'top_vehicles' => $topVehicles,
            'distance_trend' => $distanceTrend,
        ]);
    }

    public function fuel(Request $request)
    {
        $query = FleetFuelLog::query()
            ->with(['asset:id,name,asset_tag', 'user:id,name'])
            ->latest('logged_at');

        if ($request->filled('asset_id')) {
            $query->where('asset_id', (int) $request->input('asset_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('logged_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('logged_at', '<=', $request->input('date_to'));
        }

        // CSV export
        if ($request->input('export') === 'csv') {
            $allLogs = (clone $query)->limit(5000)->get();
            $csv = "Date,Vehicle,Odometer (km),Litres,Cost ($),Cost/Litre,Fuel Type,Station,Notes,Logged By\n";
            foreach ($allLogs as $log) {
                $csv .= implode(',', [
                    '"' . optional($log->logged_at)->format('Y-m-d H:i:s') . '"',
                    '"' . ($log->asset?->name ?? '') . '"',
                    $log->odometer_km ?? '',
                    $log->quantity_litres ?? 0,
                    $log->total_cost ?? 0,
                    $log->cost_per_litre ?? 0,
                    '"' . ($log->fuel_type ?? '') . '"',
                    '"' . str_replace('"', '""', $log->station_name ?? '') . '"',
                    '"' . str_replace('"', '""', $log->notes ?? '') . '"',
                    '"' . ($log->user?->name ?? '') . '"',
                ]) . "\n";
            }
            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="fuel-logs-export.csv"',
            ]);
        }

        // Summary stats (MTD)
        $mtdQuery = FleetFuelLog::query()
            ->whereMonth('logged_at', now()->month)
            ->whereYear('logged_at', now()->year);

        $totalFillUps = (clone $mtdQuery)->count();
        $totalLitres = round((float) (clone $mtdQuery)->sum('quantity_litres'), 1);
        $totalCost = round((float) (clone $mtdQuery)->sum('total_cost'), 2);
        $avgCostPerLitre = $totalLitres > 0 ? round($totalCost / $totalLitres, 3) : 0;

        // Per-vehicle efficiency (batch-query trip distances to avoid N+1)
        $fuelByAsset = FleetFuelLog::query()
            ->selectRaw('asset_id, SUM(quantity_litres) as total_litres')
            ->with('asset:id,name')
            ->groupBy('asset_id')
            ->having('total_litres', '>', 0)
            ->get();

        $distanceByAsset = FleetTrip::query()
            ->whereIn('asset_id', $fuelByAsset->pluck('asset_id'))
            ->where('status', 'completed')
            ->selectRaw('asset_id, SUM(distance_km) as total_distance')
            ->groupBy('asset_id')
            ->pluck('total_distance', 'asset_id');

        $efficiency = $fuelByAsset
            ->map(function ($row) use ($distanceByAsset) {
                $totalDistance = (float) ($distanceByAsset[$row->asset_id] ?? 0);
                $kmPerLitre = $row->total_litres > 0 ? round($totalDistance / (float) $row->total_litres, 2) : 0;
                return [
                    'vehicle' => $row->asset?->name ?? 'Unknown',
                    'asset_id' => $row->asset_id,
                    'km_per_litre' => $kmPerLitre,
                    'total_litres' => round((float) $row->total_litres, 1),
                    'total_distance_km' => round($totalDistance, 1),
                ];
            })
            ->sortByDesc('km_per_litre')
            ->values();

        $bestEfficiency = $efficiency->first();
        $worstEfficiency = $efficiency->filter(fn ($e) => $e['km_per_litre'] > 0)->last();

        // Sorting
        $allowedFuelSorts = ['logged_at', 'quantity_litres', 'total_cost'];
        $fuelSort = $request->input('sort', 'logged_at');
        $fuelDirection = $request->input('direction', 'desc');
        if (!in_array($fuelSort, $allowedFuelSorts)) $fuelSort = 'logged_at';
        if (!in_array($fuelDirection, ['asc', 'desc'])) $fuelDirection = 'desc';
        $query->reorder()->orderBy($fuelSort, $fuelDirection);

        $fuelLogs = $query->paginate(25)->withQueryString();

        // Get vehicles list for filter dropdown
        $vehicles = Asset::vehicles()->orderBy('name')->get(['id', 'name'])
            ->map(fn ($v) => ['id' => $v->id, 'name' => $v->name])->values();

        return Inertia::render('fleet-assets/fuel/index', [
            'fuel_logs' => [
                'data' => $fuelLogs->getCollection()->map(fn ($log) => [
                    'id' => $log->id,
                    'logged_at' => optional($log->logged_at)->toISOString(),
                    'asset' => $log->asset ? [
                        'id' => $log->asset->id,
                        'name' => $log->asset->name,
                        'asset_tag' => $log->asset->asset_tag,
                    ] : null,
                    'odometer_km' => $log->odometer_km,
                    'quantity_litres' => $log->quantity_litres,
                    'total_cost' => $log->total_cost,
                    'cost_per_litre' => $log->cost_per_litre,
                    'fuel_type' => $log->fuel_type,
                    'station_name' => $log->station_name,
                    'notes' => $log->notes,
                    'user' => $log->user ? [
                        'id' => $log->user->id,
                        'name' => $log->user->name,
                    ] : null,
                ])->values(),
                'links' => $fuelLogs->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $fuelLogs->currentPage(),
                    'last_page' => $fuelLogs->lastPage(),
                    'total' => $fuelLogs->total(),
                ],
            ],
            'vehicles' => $vehicles,
            'filters' => $request->only(['date_from', 'date_to', 'asset_id']),
            'summary' => [
                'total_fill_ups' => $totalFillUps,
                'total_litres' => $totalLitres,
                'total_cost' => $totalCost,
                'avg_cost_per_litre' => $avgCostPerLitre,
                'best_efficiency' => $bestEfficiency,
                'worst_efficiency' => $worstEfficiency,
            ],
            'efficiency' => $efficiency,
        ]);
    }

    public function storeFuel(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'logged_at' => ['required', 'date'],
            'odometer_km' => ['nullable', 'numeric', 'min:0'],
            'quantity_litres' => ['required', 'numeric', 'min:0.1'],
            'total_cost' => ['required', 'numeric', 'min:0'],
            'fuel_type' => ['nullable', 'string', 'in:petrol,diesel,electric,hybrid,lpg'],
            'station_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'full_tank' => ['nullable', 'boolean'],
        ]);

        $data['user_id'] = $request->user()->id;
        $data['cost_per_litre'] = $data['quantity_litres'] > 0
            ? round($data['total_cost'] / $data['quantity_litres'], 3)
            : 0;

        FleetFuelLog::create($data);

        return back()->with('success', 'Fuel log recorded successfully.');
    }

    public function bulkAction(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:assign_site,mark_offline'],
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
        ]);

        $assets = Asset::whereIn('id', $data['ids'])->get();

        switch ($data['action']) {
            case 'assign_site':
                if (!empty($data['site_id']) && $this->hasFleetFields()) {
                    Asset::whereIn('id', $data['ids'])->update(['home_site_id' => $data['site_id']]);
                } elseif (!empty($data['site_id'])) {
                    Asset::whereIn('id', $data['ids'])->update(['site_id' => $data['site_id']]);
                }
                break;
            case 'mark_offline':
                FleetVehicleStateSnapshot::whereIn('asset_id', $data['ids'])->update(['status' => 'offline']);
                break;
        }

        AuditLogger::log('fleet.vehicles.bulk_action', null, [
            'action' => $data['action'],
            'count' => count($data['ids']),
        ]);

        return back()->with('success', 'Bulk action applied to ' . count($data['ids']) . ' vehicle(s).');
    }

    public function alertsConfig(Request $request, Asset $asset)
    {
        $config = [];
        if ($this->hasFleetFields() && $asset->alert_config) {
            $config = is_string($asset->alert_config) ? json_decode($asset->alert_config, true) : (array) $asset->alert_config;
        }

        $geofences = $asset->geofences()->get(['id', 'name', 'is_active'])->map(fn ($g) => [
            'id' => $g->id,
            'name' => $g->name,
            'is_active' => $g->is_active,
        ]);

        return Inertia::render('fleet-assets/vehicles/alerts-config', [
            'asset' => [
                'id' => $asset->id,
                'name' => $asset->name,
                'asset_tag' => $asset->asset_tag,
            ],
            'config' => $config,
            'geofences' => $geofences,
        ]);
    }

    public function saveAlertsConfig(Request $request, Asset $asset)
    {
        $data = $request->validate([
            'config' => ['required', 'array'],
        ]);

        if ($this->hasFleetFields()) {
            $asset->update(['alert_config' => json_encode($data['config'])]);
        }

        AuditLogger::log('fleet.vehicle.alerts_config', $asset, [
            'asset_id' => $asset->id,
        ]);

        return back()->with('success', 'Alert configuration saved.');
    }
}
