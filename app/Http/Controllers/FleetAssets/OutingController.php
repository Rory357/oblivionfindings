<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\FleetOuting;
use App\Models\FleetOutingResident;
use App\Models\FleetVehicleBooking;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Fleet\ResidentTransportJourneyScope;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class OutingController extends Controller
{
    /**
     * FA-T01: every outing, resident row, driver name, count and picker covers
     * the viewer's approved Sites only. `fleet.manage` is the explicit
     * all-Sites authority, as in the sibling Fleet report controllers. The
     * outing rule itself is ResidentTransportJourneyScope::applyOutingScope.
     */
    private const SITE_BYPASS_PERMISSIONS = ['fleet.manage'];

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly ResidentTransportJourneyScope $journeyScope,
    ) {}

    public function index(Request $request)
    {
        $formOptions = $this->formOptions($request);

        if (! Schema::hasTable('fleet_outings')) {
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
                    'past_return' => 0,
                    'overdue_returns' => 0,
                    'critical_alerts' => 0,
                ],
                'chart_data' => [],
                ...$formOptions,
            ]);
        }

        $user = $request->user();

        $query = $this->outings($user)
            ->with(['asset:id,name,asset_tag'])
            ->withCount(['residents as visible_resident_count' => fn (Builder $residents) => $this->journeyScope
                ->applyOutingResidentScope($residents, $user)]);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('date_from')) {
            $query->where('planned_departure', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('planned_departure', '<=', $request->input('date_to').' 23:59:59');
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('destination', 'like', "%{$search}%");
            });
        }

        $outings = $query->latest('planned_departure')->paginate(25)->withQueryString();
        $drivers = $this->visibleStaff($user, $outings->getCollection()->pluck('driver_user_id')->all());

        // Stats
        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();

        $outingsThisWeek = $this->outings($user)
            ->whereBetween('planned_departure', [$weekStart, $weekEnd])
            ->count();

        $residentsThisWeek = $this->visibleResidents($user, fn (Builder $outing) => $outing
            ->whereBetween('planned_departure', [$weekStart, $weekEnd]))
            ->count();

        $avgDuration = $this->outings($user)
            ->whereNotNull('actual_departure')
            ->whereNotNull('actual_return')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, actual_departure, actual_return)) as avg_min')
            ->value('avg_min');

        $upcoming = $this->outings($user)
            ->where('status', 'planned')
            ->where('planned_departure', '>=', now())
            ->count();

        // Chart: outings per day of week (last 4 weeks)
        $chartData = $this->outings($user)
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
                    'driver' => ($driver = $drivers->get((int) $o->driver_user_id))
                        ? ['id' => $driver->id, 'name' => $driver->name]
                        : null,
                    'resident_count' => (int) $o->visible_resident_count,
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
                'planned_today' => $this->outings($user)
                    ->where('status', 'planned')
                    ->whereDate('planned_departure', today())
                    ->count(),
                'active_now' => $this->outings($user)->where('status', 'active')->count(),
                'residents_out_now' => $this->visibleResidents($user, fn (Builder $outing) => $outing
                    ->where('status', 'active'))
                    ->whereNull('returned_at')
                    ->count(),
                'completed_7d' => $this->outings($user)
                    ->where('status', 'completed')
                    ->where('planned_departure', '>=', now()->subDays(7))
                    ->count(),
                // Attention-strip escalations (the viewer's Sites, same
                // definitions as the fleet dashboard hero).
                'past_return' => $this->outings($user)
                    ->where('status', 'active')
                    ->where('planned_return', '<', now())
                    ->count(),
                'overdue_returns' => Schema::hasTable('fleet_vehicle_bookings')
                    ? FleetVehicleBooking::query()
                        ->whereIn('asset_id', $this->journeyScope->outingVehicles($user)->select('assets.id'))
                        ->where('status', 'checked_out')
                        ->where('ends_at', '<', now())
                        ->count()
                    : 0,
                'critical_alerts' => $this->criticalAlertCount($request),
            ],
            'chart_data' => $chartFormatted,
            'can' => [
                'manage' => (bool) $request->user()?->canDo('fleet.manage')
                    || (bool) $request->user()?->canDo('fleet.outings.manage'),
            ],
            ...$formOptions,
        ]);
    }

    private function criticalAlertCount(Request $request): int
    {
        $query = ControlRoomAlert::query()
            ->actionable()
            ->where('severity', 'critical');
        $this->siteAccess->applyAlertScope($query, $request->user(), self::SITE_BYPASS_PERMISSIONS);

        return $query->count();
    }

    private function formOptions(Request $request): array
    {
        $user = $request->user();
        $hasTransportNeeds = Schema::hasColumn('clients', 'transport_needs');
        $selectCols = ['id', 'first_name', 'last_name', 'site_id'];
        if ($hasTransportNeeds) {
            $selectCols[] = 'transport_needs';
            $selectCols[] = 'transport_notes';
        }

        $clients = $this->journeyScope->applyClientScope(Client::query(), $user)
            ->where('status', 'active')
            ->orderBy('first_name')
            ->with('site:id,name')
            ->get($selectCols)
            ->map(fn ($client) => [
                'id' => $client->id,
                'name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
                'transport_needs' => $hasTransportNeeds ? $client->transport_needs : null,
                'transport_notes' => $hasTransportNeeds ? $client->transport_notes : null,
                'site' => $client->site?->name,
            ])->values();

        $hasAccessibility = Schema::hasColumn('assets', 'has_wheelchair_ramp');
        $vehicles = $this->journeyScope->outingVehicles($user)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(array_merge(
                ['id', 'name', 'asset_tag'],
                $hasAccessibility
                    ? ['has_wheelchair_ramp', 'has_hoist', 'has_child_seat_anchors', 'has_medical_storage', 'seating_capacity']
                    : [],
            ))
            ->map(fn ($vehicle) => array_merge([
                'id' => $vehicle->id,
                'name' => $vehicle->name,
                'asset_tag' => $vehicle->asset_tag,
            ], $hasAccessibility ? [
                'has_wheelchair_ramp' => (bool) $vehicle->has_wheelchair_ramp,
                'has_hoist' => (bool) $vehicle->has_hoist,
                'has_child_seat_anchors' => (bool) $vehicle->has_child_seat_anchors,
                'has_medical_storage' => (bool) $vehicle->has_medical_storage,
                'seating_capacity' => $vehicle->seating_capacity,
            ] : []))->values();

        $drivers = $this->journeyScope->applyStaffScope(User::query(), $user)
            ->whereHas('hrDriverEligibility')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
            ])->values();

        return [
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
        ];
    }

    public function create(Request $request)
    {
        return redirect()->route('fleet-assets.outings.index', ['new' => 1]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        // Every reference must be one the picker offers this viewer; a foreign
        // vehicle, resident or driver fails exactly like a missing one and
        // nothing is written. A vehicle and a resident are required because an
        // outing without them is outside every viewer's boundary.
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'destination' => ['required', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'in:community,medical,social,recreational,shopping'],
            'planned_departure' => ['required', 'date'],
            'planned_return' => ['required', 'date', 'after:planned_departure'],
            'asset_id' => ['required', 'integer', Rule::exists('assets', 'id')->where(fn ($assets) => $assets
                ->where('status', 'active')
                ->whereIn('id', $this->journeyScope->outingVehicles($user)->select('assets.id')))],
            'driver_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($users) => $users
                ->whereIn('id', $this->journeyScope->applyStaffScope(User::query(), $user)->select('users.id')))],
            'risk_assessment' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'resident_ids' => ['required', 'array', 'min:1'],
            'resident_ids.*' => ['integer', 'distinct', Rule::exists('clients', 'id')->where(fn ($clients) => $clients
                ->whereIn('id', $this->journeyScope->applyClientScope(Client::query(), $user)->select('clients.id')))],
        ], [
            'asset_id.required' => 'Choose a vehicle for the outing.',
            'asset_id.exists' => 'The selected vehicle is not available.',
            'driver_user_id.exists' => 'The selected driver is not available.',
            'resident_ids.required' => 'Choose at least one resident for the outing.',
            'resident_ids.min' => 'Choose at least one resident for the outing.',
            'resident_ids.*.exists' => 'A selected resident is not available.',
            'resident_ids.*.distinct' => 'A resident was selected twice.',
        ]);

        // Verify assigned driver has valid eligibility
        if (! empty($data['driver_user_id'])) {
            $driverEligible = HrDriverEligibility::query()
                ->where('user_id', $data['driver_user_id'])
                ->where('status', 'eligible')
                ->where('licence_expires_at', '>', now())
                ->exists();

            if (! $driverEligible) {
                return back()->withErrors([
                    'driver_user_id' => 'The selected driver does not have valid eligibility or their licence has expired.',
                ]);
            }
        }

        $outing = DB::transaction(function () use ($data, $request) {
            // The asset row lock serializes this booking producer with
            // maintenance holds, just like the direct booking/approval paths.
            // It re-resolves the vehicle through the outing boundary, so a
            // vehicle that left the viewer's Sites since validation still
            // fails with zero writes.
            $asset = $this->journeyScope->outingVehicles($request->user())
                ->whereKey((int) $data['asset_id'])
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if (! $asset) {
                throw ValidationException::withMessages(['asset_id' => 'The selected vehicle is not available.']);
            }
            app(\App\Services\Fleet\MaintenanceRestrictionService::class)->assertBookable((int) $asset->id);
            $outing = FleetOuting::create([
                'title' => $data['title'],
                'destination' => $data['destination'],
                'purpose' => $data['purpose'] ?? null,
                'planned_departure' => $data['planned_departure'],
                'planned_return' => $data['planned_return'],
                'asset_id' => $data['asset_id'] ?? null,
                'driver_user_id' => $data['driver_user_id'] ?? null,
                'risk_assessment' => ! empty($data['risk_assessment']) ? ['notes' => $data['risk_assessment']] : null,
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

    public function show(Request $request, int $outing)
    {
        $user = $request->user();
        $outing = $this->journeyScope->outingFor($user, $outing);
        $outing->load([
            'asset:id,name,asset_tag',
            'booking',
            'residents' => fn ($residents) => $this->journeyScope
                ->applyOutingResidentScope($residents->getQuery(), $user)
                ->with('client:id,first_name,last_name,transport_needs'),
        ]);
        $people = $this->visibleStaff($user, [$outing->driver_user_id, $outing->created_by_user_id], ['id', 'name', 'email']);
        $driver = $people->get((int) $outing->driver_user_id);
        $createdBy = $people->get((int) $outing->created_by_user_id);

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
                'driver' => $driver ? [
                    'id' => $driver->id,
                    'name' => $driver->name,
                    'email' => $driver->email,
                ] : null,
                'booking' => $outing->booking ? [
                    'id' => $outing->booking->id,
                    'purpose' => $outing->booking->purpose,
                    'status' => $outing->booking->status,
                ] : null,
                'created_by' => $createdBy ? [
                    'id' => $createdBy->id,
                    'name' => $createdBy->name,
                ] : null,
                'risk_assessment' => $outing->risk_assessment,
                'status' => $outing->status,
                'notes' => $outing->notes,
                'residents' => $outing->residents->map(fn ($r) => [
                    'id' => $r->id,
                    'client_id' => $r->client_id,
                    'client_name' => trim(($r->client?->first_name ?? '').' '.($r->client?->last_name ?? '')),
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

    public function start(Request $request, int $outing)
    {
        $outing = $this->journeyScope->outingFor($request->user(), $outing);

        if ($outing->status !== 'planned') {
            return back()->with('error', 'Outing can only be started from planned status.');
        }

        // Safety check: every resident on the outing — including any from
        // another Site — must have pre-check and medication packing completed
        $residents = $outing->residents()->get();
        if ($residents->isNotEmpty()) {
            $unprepared = $residents->filter(fn ($r) => ! $r->pre_check_completed);
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

    public function complete(Request $request, int $outing)
    {
        $user = $request->user();
        $outing = $this->journeyScope->outingFor($user, $outing);

        if ($outing->status !== 'active') {
            return back()->with('error', 'Outing can only be completed from active status.');
        }

        // Every resident must be back, but only visible residents are counted
        // or named to this viewer.
        $unreturnedResidents = $this->journeyScope
            ->applyOutingResidentScope($outing->residents()->getQuery(), $user)
            ->whereNull('returned_at')
            ->count();

        if ($unreturnedResidents > 0) {
            return back()->with('error', "Cannot complete outing: {$unreturnedResidents} resident(s) not yet marked as returned.");
        }

        if ($outing->residents()->whereNull('returned_at')->exists()) {
            return back()->with('error', 'Cannot complete outing: residents from another Site have not been marked as returned yet.');
        }

        $outing->update([
            'status' => 'completed',
            'actual_return' => now(),
        ]);

        AuditLogger::log('fleet.outing.complete', $outing);

        return back()->with('success', 'Outing completed.');
    }

    public function markResidentReturned(Request $request, int $outing, int $resident)
    {
        $user = $request->user();
        $outing = $this->journeyScope->outingFor($user, $outing);
        $resident = $this->journeyScope
            ->applyOutingResidentScope($outing->residents()->getQuery(), $user)
            ->whereKey($resident)
            ->firstOrFail();

        abort_unless($outing->status === 'active', 422, 'Outing must be active to mark residents as returned.');

        $resident->update(['returned_at' => now()]);

        return back()->with('success', 'Resident marked as returned.');
    }

    public function returnAllResidents(Request $request, int $outing)
    {
        $user = $request->user();
        $outing = $this->journeyScope->outingFor($user, $outing);

        abort_unless($outing->status === 'active', 422, 'Outing must be active to mark residents as returned.');

        // Only the residents this viewer can see; another Site's residents
        // are returned by staff who can see them.
        $this->journeyScope
            ->applyOutingResidentScope($outing->residents()->getQuery(), $user)
            ->whereNull('returned_at')
            ->update(['returned_at' => now()]);

        return back()->with('success', 'All residents marked as returned.');
    }

    public function cancel(Request $request, int $outing)
    {
        $outing = $this->journeyScope->outingFor($request->user(), $outing);

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

    private function outings(?User $user): Builder
    {
        return $this->journeyScope->applyOutingScope(FleetOuting::query(), $user);
    }

    /**
     * Resident rows the viewer may see, on outings the viewer may see.
     *
     * @param  callable(Builder): Builder  $outingConstraint
     */
    private function visibleResidents(?User $user, callable $outingConstraint): Builder
    {
        return $this->journeyScope->applyOutingResidentScope(FleetOutingResident::query(), $user)
            ->whereHas('outing', fn (Builder $outing) => $outingConstraint(
                $this->journeyScope->applyOutingScope($outing, $user),
            ));
    }

    /**
     * People the viewer may see as staff, keyed by id — anyone else on an
     * outing (driver, creator) is left unnamed.
     *
     * @param  array<int, int|string|null>  $userIds
     * @param  list<string>  $columns
     * @return Collection<int, User>
     */
    private function visibleStaff(?User $viewer, array $userIds, array $columns = ['id', 'name']): Collection
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn (int $id) => $id > 0)));
        if ($userIds === []) {
            return collect();
        }

        return $this->journeyScope->applyStaffScope(User::query(), $viewer)
            ->whereIn('users.id', $userIds)
            ->get(array_map(fn (string $column) => "users.{$column}", $columns))
            ->keyBy('id');
    }
}
