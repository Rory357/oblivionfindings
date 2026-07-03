<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Client;
use App\Models\FleetOuting;
use App\Models\FleetOutingResident;
use App\Models\FleetVehicleBooking;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class OutingController extends Controller
{
    public function index(Request $request)
    {
        if (!Schema::hasTable('fleet_outings')) {
            return Inertia::render('fleet-assets/outings/index', [
                'outings' => [
                    'data' => [],
                    'links' => [],
                    'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0],
                ],
                'filters' => $request->only(['status', 'date_from', 'date_to', 'search']),
                'stats' => [
                    'outings_this_week' => 0,
                    'residents_this_week' => 0,
                    'avg_duration_minutes' => 0,
                    'upcoming' => 0,
                ],
                'hero' => [
                    'planned_today' => 0,
                    'active_now' => 0,
                    'residents_out_now' => 0,
                    'completed_7d' => 0,
                ],
                'chart_data' => [],
            ]);
        }

        $query = FleetOuting::query()
            ->with(['asset:id,name,asset_tag', 'driver:id,name', 'residents']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->where('planned_departure', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('planned_departure', '<=', $request->input('date_to') . ' 23:59:59');
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('destination', 'like', "%{$search}%");
            });
        }

        $outings = $query->latest('planned_departure')->paginate(25)->withQueryString();

        // Stats
        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();

        $weekQuery = FleetOuting::query()
            ->whereBetween('planned_departure', [$weekStart, $weekEnd]);

        $outingsThisWeek = (clone $weekQuery)->count();

        $residentsThisWeek = FleetOutingResident::query()
            ->whereHas('outing', fn ($q) => $q->whereBetween('planned_departure', [$weekStart, $weekEnd]))
            ->count();

        $avgDuration = FleetOuting::query()
            ->whereNotNull('actual_departure')
            ->whereNotNull('actual_return')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, actual_departure, actual_return)) as avg_min')
            ->value('avg_min');

        $upcoming = FleetOuting::query()
            ->where('status', 'planned')
            ->where('planned_departure', '>=', now())
            ->count();

        // Chart: outings per day of week (last 4 weeks)
        $chartData = FleetOuting::query()
            ->where('planned_departure', '>=', now()->subWeeks(4))
            ->selectRaw('DAYOFWEEK(planned_departure) as dow, COUNT(*) as count')
            ->groupBy('dow')
            ->pluck('count', 'dow')
            ->toArray();

        $dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $chartFormatted = [];
        for ($i = 1; $i <= 7; $i++) {
            $chartFormatted[] = [
                'label' => $dayLabels[$i - 1],
                'value' => $chartData[$i] ?? 0,
            ];
        }

        return Inertia::render('fleet-assets/outings/index', [
            'outings' => [
                'data' => $outings->getCollection()->map(fn ($o) => [
                    'id' => $o->id,
                    'title' => $o->title,
                    'destination' => $o->destination,
                    'purpose' => $o->purpose,
                    'planned_departure' => optional($o->planned_departure)->toISOString(),
                    'planned_return' => optional($o->planned_return)->toISOString(),
                    'actual_departure' => optional($o->actual_departure)->toISOString(),
                    'actual_return' => optional($o->actual_return)->toISOString(),
                    'asset' => $o->asset ? ['id' => $o->asset->id, 'name' => $o->asset->name, 'asset_tag' => $o->asset->asset_tag] : null,
                    'driver' => $o->driver ? ['id' => $o->driver->id, 'name' => $o->driver->name] : null,
                    'resident_count' => $o->residents->count(),
                    'status' => $o->status,
                    'created_at' => optional($o->created_at)->toISOString(),
                ])->values(),
                'links' => $outings->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $outings->currentPage(),
                    'last_page' => $outings->lastPage(),
                    'total' => $outings->total(),
                ],
            ],
            'filters' => $request->only(['status', 'date_from', 'date_to', 'search']),
            'stats' => [
                'outings_this_week' => $outingsThisWeek,
                'residents_this_week' => $residentsThisWeek,
                'avg_duration_minutes' => round((float) ($avgDuration ?? 0), 1),
                'upcoming' => $upcoming,
            ],
            'hero' => [
                'planned_today' => FleetOuting::query()
                    ->where('status', 'planned')
                    ->whereDate('planned_departure', today())
                    ->count(),
                'active_now' => FleetOuting::query()->where('status', 'active')->count(),
                'residents_out_now' => FleetOutingResident::query()
                    ->whereHas('outing', fn ($q) => $q->where('status', 'active'))
                    ->whereNull('returned_at')
                    ->count(),
                'completed_7d' => FleetOuting::query()
                    ->where('status', 'completed')
                    ->where('planned_departure', '>=', now()->subDays(7))
                    ->count(),
            ],
            'chart_data' => $chartFormatted,
            'can' => [
                'manage' => (bool) $request->user()?->canDo('fleet.manage')
                    || (bool) $request->user()?->canDo('fleet.outings.manage'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $hasTransportNeeds = Schema::hasColumn('clients', 'transport_needs');
        $selectCols = ['id', 'first_name', 'last_name', 'site_id'];
        if ($hasTransportNeeds) {
            $selectCols[] = 'transport_needs';
            $selectCols[] = 'transport_notes';
        }
        $clients = Client::query()
            ->where('status', 'active')
            ->orderBy('first_name')
            ->with('site:id,name')
            ->get($selectCols)
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                'transport_needs' => $hasTransportNeeds ? $c->transport_needs : null,
                'transport_notes' => $hasTransportNeeds ? $c->transport_notes : null,
                'site' => $c->site?->name,
            ])->values();

        $hasAccessibility = Schema::hasColumn('assets', 'has_wheelchair_ramp');

        $vehicles = Asset::vehicles()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(array_merge(
                ['id', 'name', 'asset_tag'],
                $hasAccessibility ? ['has_wheelchair_ramp', 'has_hoist', 'has_child_seat_anchors', 'has_medical_storage', 'seating_capacity'] : []
            ))
            ->map(fn ($v) => array_merge([
                'id' => $v->id,
                'name' => $v->name,
                'asset_tag' => $v->asset_tag,
            ], $hasAccessibility ? [
                'has_wheelchair_ramp' => (bool) $v->has_wheelchair_ramp,
                'has_hoist' => (bool) $v->has_hoist,
                'has_child_seat_anchors' => (bool) $v->has_child_seat_anchors,
                'has_medical_storage' => (bool) $v->has_medical_storage,
                'seating_capacity' => $v->seating_capacity,
            ] : []))->values();

        $drivers = User::query()
            ->whereHas('hrDriverEligibility')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
            ])->values();

        return Inertia::render('fleet-assets/outings/create', [
            'clients' => $clients,
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'auth_user' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
            ],
            'can' => [
                'manage' => (bool) $request->user()?->canDo('fleet.manage')
                    || (bool) $request->user()?->canDo('fleet.outings.manage'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'destination' => ['required', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'in:community,medical,social,recreational,shopping'],
            'planned_departure' => ['required', 'date'],
            'planned_return' => ['required', 'date', 'after:planned_departure'],
            'asset_id' => ['nullable', 'integer', 'exists:assets,id'],
            'driver_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'risk_assessment' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'resident_ids' => ['nullable', 'array'],
            'resident_ids.*' => ['integer', 'exists:clients,id'],
        ]);

        // Verify assigned driver has valid eligibility
        if (!empty($data['driver_user_id'])) {
            $driverEligible = \App\Domain\Hr\Models\HrDriverEligibility::query()
                ->where('user_id', $data['driver_user_id'])
                ->where('status', 'eligible')
                ->where('licence_expires_at', '>', now())
                ->exists();

            if (!$driverEligible) {
                return back()->withErrors([
                    'driver_user_id' => 'The selected driver does not have valid eligibility or their licence has expired.',
                ]);
            }
        }

        $outing = DB::transaction(function () use ($data, $request) {
            $outing = FleetOuting::create([
                'title' => $data['title'],
                'destination' => $data['destination'],
                'purpose' => $data['purpose'] ?? null,
                'planned_departure' => $data['planned_departure'],
                'planned_return' => $data['planned_return'],
                'asset_id' => $data['asset_id'] ?? null,
                'driver_user_id' => $data['driver_user_id'] ?? null,
                'risk_assessment' => $data['risk_assessment'] ? ['notes' => $data['risk_assessment']] : null,
                'notes' => $data['notes'] ?? null,
                'status' => 'planned',
                'created_by_user_id' => $request->user()->id,
            ]);

            // Create resident pivot records
            $residentIds = $data['resident_ids'] ?? [];
            foreach ($residentIds as $clientId) {
                FleetOutingResident::create([
                    'outing_id' => $outing->id,
                    'client_id' => $clientId,
                ]);
            }

            // Auto-create vehicle booking if vehicle and dates are set
            if ($outing->asset_id && Schema::hasTable('fleet_vehicle_bookings')) {
                $booking = FleetVehicleBooking::create([
                    'asset_id' => $outing->asset_id,
                    'user_id' => $request->user()->id,
                    'purpose' => "Outing: {$outing->title}",
                    'destination' => $outing->destination,
                    'starts_at' => $outing->planned_departure,
                    'ends_at' => $outing->planned_return,
                    'passengers' => count($residentIds),
                    'status' => 'approved',
                    'notes' => "Auto-created from outing #{$outing->id}",
                ]);

                $outing->update(['booking_id' => $booking->id]);
            }

            return $outing;
        });

        AuditLogger::log('fleet.outing.create', $outing, [
            'title' => $data['title'],
            'destination' => $data['destination'],
            'resident_count' => count($data['resident_ids'] ?? []),
        ]);

        return redirect()->route('fleet-assets.outings.show', $outing)
            ->with('success', 'Outing created successfully.');
    }

    public function show(Request $request, FleetOuting $outing)
    {
        $outing->load([
            'asset:id,name,asset_tag',
            'driver:id,name,email',
            'booking',
            'createdBy:id,name',
            'residents.client:id,first_name,last_name,transport_needs',
        ]);

        // Get vehicle state for live map
        $vehicleState = null;
        if ($outing->asset_id && $outing->status === 'active') {
            $state = $outing->asset?->fleetState;
            if ($state) {
                $vehicleState = [
                    'lat' => $state->latitude,
                    'lng' => $state->longitude,
                    'speed_kph' => $state->speed_kph,
                    'last_seen_at' => optional($state->last_seen_at)->toISOString(),
                ];
            }
        }

        return Inertia::render('fleet-assets/outings/show', [
            'outing' => [
                'id' => $outing->id,
                'title' => $outing->title,
                'destination' => $outing->destination,
                'purpose' => $outing->purpose,
                'planned_departure' => optional($outing->planned_departure)->toISOString(),
                'planned_return' => optional($outing->planned_return)->toISOString(),
                'actual_departure' => optional($outing->actual_departure)->toISOString(),
                'actual_return' => optional($outing->actual_return)->toISOString(),
                'asset' => $outing->asset ? [
                    'id' => $outing->asset->id,
                    'name' => $outing->asset->name,
                    'asset_tag' => $outing->asset->asset_tag,
                ] : null,
                'driver' => $outing->driver ? [
                    'id' => $outing->driver->id,
                    'name' => $outing->driver->name,
                    'email' => $outing->driver->email,
                ] : null,
                'booking' => $outing->booking ? [
                    'id' => $outing->booking->id,
                    'purpose' => $outing->booking->purpose,
                    'status' => $outing->booking->status,
                ] : null,
                'created_by' => $outing->createdBy ? [
                    'id' => $outing->createdBy->id,
                    'name' => $outing->createdBy->name,
                ] : null,
                'risk_assessment' => $outing->risk_assessment,
                'status' => $outing->status,
                'notes' => $outing->notes,
                'residents' => $outing->residents->map(fn ($r) => [
                    'id' => $r->id,
                    'client_id' => $r->client_id,
                    'client_name' => trim(($r->client?->first_name ?? '') . ' ' . ($r->client?->last_name ?? '')),
                    'transport_needs' => $r->client?->transport_needs,
                    'pre_check_completed' => (bool) $r->pre_check_completed,
                    'medication_packed' => (bool) $r->medication_packed,
                    'returned_at' => optional($r->returned_at)->toISOString(),
                    'notes' => $r->notes,
                ])->values(),
                'created_at' => optional($outing->created_at)->toISOString(),
            ],
            'vehicle_state' => $vehicleState,
            'can' => [
                'manage' => (bool) $request->user()?->canDo('fleet.manage')
                    || (bool) $request->user()?->canDo('fleet.outings.manage'),
            ],
        ]);
    }

    public function start(Request $request, FleetOuting $outing)
    {
        if ($outing->status !== 'planned') {
            return back()->with('error', 'Outing can only be started from planned status.');
        }

        // Safety check: all residents must have pre-check and medication packing completed
        $residents = $outing->residents()->get();
        if ($residents->isNotEmpty()) {
            $unprepared = $residents->filter(fn ($r) => !$r->pre_check_completed);
            if ($unprepared->isNotEmpty()) {
                return back()->with('error', 'All residents must have their pre-departure check completed before starting the outing.');
            }
        }

        $outing->update([
            'status' => 'active',
            'actual_departure' => now(),
        ]);

        AuditLogger::log('fleet.outing.start', $outing);

        return back()->with('success', 'Outing started.');
    }

    public function complete(Request $request, FleetOuting $outing)
    {
        if ($outing->status !== 'active') {
            return back()->with('error', 'Outing can only be completed from active status.');
        }

        $unreturnedResidents = $outing->residents()
            ->whereNull('returned_at')
            ->count();

        if ($unreturnedResidents > 0) {
            return back()->with('error', "Cannot complete outing: {$unreturnedResidents} resident(s) not yet marked as returned.");
        }

        $outing->update([
            'status' => 'completed',
            'actual_return' => now(),
        ]);

        AuditLogger::log('fleet.outing.complete', $outing);

        return back()->with('success', 'Outing completed.');
    }

    public function markResidentReturned(Request $request, FleetOuting $outing, FleetOutingResident $resident)
    {
        abort_unless($outing->status === 'active', 422, 'Outing must be active to mark residents as returned.');
        abort_unless($resident->outing_id === $outing->id, 404);

        $resident->update(['returned_at' => now()]);

        return back()->with('success', 'Resident marked as returned.');
    }

    public function returnAllResidents(Request $request, FleetOuting $outing)
    {
        abort_unless($outing->status === 'active', 422, 'Outing must be active to mark residents as returned.');

        $outing->residents()
            ->whereNull('returned_at')
            ->update(['returned_at' => now()]);

        return back()->with('success', 'All residents marked as returned.');
    }

    public function cancel(Request $request, FleetOuting $outing)
    {
        if (in_array($outing->status, ['completed', 'cancelled'])) {
            return back()->with('error', 'Outing cannot be cancelled.');
        }

        $outing->update([
            'status' => 'cancelled',
        ]);

        // Cancel associated booking
        if ($outing->booking_id) {
            FleetVehicleBooking::where('id', $outing->booking_id)->update(['status' => 'cancelled']);
        }

        AuditLogger::log('fleet.outing.cancel', $outing);

        return back()->with('success', 'Outing cancelled.');
    }
}
