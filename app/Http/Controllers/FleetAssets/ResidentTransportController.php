<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\FleetMedicationTransitLog;
use App\Models\FleetResidentTransport;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\Fleet\FleetControlledDrugTransitNotification;
use App\Services\AuditLogger;
use App\Services\CoverageRoleService;
use App\Services\ShiftOperationalSnapshotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ResidentTransportController extends Controller
{
    public function __construct(
        protected CoverageRoleService $coverageRoles,
        protected ShiftOperationalSnapshotService $snapshots,
    ) {
    }

    public function index(Request $request)
    {
        if (!Schema::hasTable('fleet_resident_transports')) {
            $vehicles = Asset::vehicles()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'asset_tag']);

            return Inertia::render('fleet-assets/transports/index', [
                'transports' => [
                    'data' => [],
                    'links' => [],
                    'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0],
                ],
                'filters' => $request->only(['transport_type', 'asset_id', 'status', 'search', 'date_from', 'date_to']),
                'vehicles' => $vehicles,
                'stats' => [
                    'total_this_month' => 0,
                    'residents_this_month' => 0,
                    'avg_duration_minutes' => 0,
                    'most_active_vehicle' => null,
                ],
            ]);
        }

        $query = FleetResidentTransport::query()
            ->with([
                'asset:id,name,asset_tag',
                'driver:id,name',
                'shift:id,client_id,user_id,starts_at,ends_at,shift_type,location,service_context_id',
                'shift.staff:id,name',
                'shift.serviceContext:id,name',
                'serviceContext:id,name',
            ]);

        if ($request->filled('transport_type')) {
            $query->where('transport_type', $request->input('transport_type'));
        }

        if ($request->filled('asset_id')) {
            $query->where('asset_id', (int) $request->input('asset_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $query->where('resident_name', 'like', '%' . $request->input('search') . '%');
        }

        if ($request->filled('date_from')) {
            $query->where('departed_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('departed_at', '<=', $request->input('date_to') . ' 23:59:59');
        }

        // CSV export
        if ($request->input('export') === 'csv') {
            $all = (clone $query)->latest('departed_at')->limit(5000)->get();
            return response()->streamDownload(function () use ($all) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['ID', 'Vehicle', 'Driver', 'Resident', 'Type', 'Pickup', 'Dropoff', 'Departed', 'Arrived', 'Duration (min)', 'Passengers', 'Supervisor', 'Status', 'Notes']);
                foreach ($all as $t) {
                    $duration = ($t->departed_at && $t->arrived_at)
                        ? round($t->departed_at->diffInMinutes($t->arrived_at), 1)
                        : '';
                    fputcsv($handle, [
                        $t->id,
                        $t->asset?->name ?? '',
                        $t->driver?->name ?? '',
                        $t->resident_name,
                        $t->transport_type,
                        $t->pickup_location ?? '',
                        $t->dropoff_location ?? '',
                        optional($t->departed_at)->format('Y-m-d H:i') ?? '',
                        optional($t->arrived_at)->format('Y-m-d H:i') ?? '',
                        $duration,
                        $t->passengers_count,
                        $t->supervisor_name ?? '',
                        $t->status,
                        $t->notes ?? '',
                    ]);
                }
                fclose($handle);
            }, 'resident-transports-' . now()->format('Y-m-d') . '.csv');
        }

        $transports = $query->latest('departed_at')->paginate(25)->withQueryString();

        // Summary stats
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $monthQuery = FleetResidentTransport::query()
            ->whereBetween('departed_at', [$monthStart, $monthEnd]);

        $totalThisMonth = (clone $monthQuery)->count();
        $residentsThisMonth = (clone $monthQuery)->distinct('resident_name')->count('resident_name');

        $avgDuration = (clone $monthQuery)
            ->whereNotNull('arrived_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, departed_at, arrived_at)) as avg_min')
            ->value('avg_min');

        $mostActiveVehicle = (clone $monthQuery)
            ->select('asset_id', DB::raw('COUNT(*) as trip_count'))
            ->groupBy('asset_id')
            ->orderByDesc('trip_count')
            ->first();

        $mostActiveVehicleName = null;
        if ($mostActiveVehicle) {
            $mostActiveVehicleName = Asset::where('id', $mostActiveVehicle->asset_id)->value('name');
        }

        $vehicles = Asset::vehicles()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'asset_tag']);

        // Recent resident names for autocomplete
        $recentResidents = Schema::hasTable('fleet_resident_transports')
            ? FleetResidentTransport::query()
                ->select('resident_name')
                ->distinct()
                ->orderBy('resident_name')
                ->limit(100)
                ->pluck('resident_name')
            : collect();

        return Inertia::render('fleet-assets/transports/index', [
            'transports' => [
                'data' => $transports->getCollection()->map(fn ($t) => [
                    'id' => $t->id,
                    'asset' => $t->asset ? ['id' => $t->asset->id, 'name' => $t->asset->name, 'asset_tag' => $t->asset->asset_tag] : null,
                    'driver' => $t->driver ? ['id' => $t->driver->id, 'name' => $t->driver->name] : null,
                    'site_name' => $t->site_name_snapshot,
                    'resident_name' => $t->resident_name,
                    'shift' => $t->shift ? [
                        'id' => $t->shift->id,
                        'starts_at' => optional($t->shift->starts_at)->toISOString(),
                        'ends_at' => optional($t->shift->ends_at)->toISOString(),
                        'shift_type' => $t->shift->shift_type ?? 'standard',
                        'staff_name' => $t->shift->staff?->name,
                    ] : null,
                    'service_context' => $t->serviceContext?->name ?? $t->shift?->serviceContext?->name,
                    'transport_type' => $t->transport_type,
                    'pickup_location' => $t->pickup_location,
                    'dropoff_location' => $t->dropoff_location,
                    'departed_at' => optional($t->departed_at)->toISOString(),
                    'arrived_at' => optional($t->arrived_at)->toISOString(),
                    'passengers_count' => $t->passengers_count,
                    'supervisor_name' => $t->supervisor_name,
                    'status' => $t->status,
                    'duration_minutes' => $t->duration_minutes,
                    'notes' => $t->notes,
                    'created_at' => optional($t->created_at)->toISOString(),
                ])->values(),
                'links' => $transports->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $transports->currentPage(),
                    'last_page' => $transports->lastPage(),
                    'total' => $transports->total(),
                ],
            ],
            'filters' => $request->only(['transport_type', 'asset_id', 'status', 'search', 'date_from', 'date_to']),
            'vehicles' => $vehicles,
            'stats' => [
                'total_this_month' => $totalThisMonth,
                'residents_this_month' => $residentsThisMonth,
                'avg_duration_minutes' => round((float) ($avgDuration ?? 0), 1),
                'most_active_vehicle' => $mostActiveVehicleName,
            ],
        ]);
    }

    public function create(Request $request)
    {
        $vehicles = Asset::vehicles()
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'asset_tag']);

        $selectedShift = $request->filled('shift_id')
            ? Shift::query()
                ->with(['client:id,first_name,last_name', 'staff:id,name', 'serviceContext:id,name'])
                ->find($request->input('shift_id'))
            : null;

        // Recent resident names for autocomplete
        $recentResidents = Schema::hasTable('fleet_resident_transports')
            ? FleetResidentTransport::query()
                ->select('resident_name')
                ->distinct()
                ->orderBy('resident_name')
                ->limit(100)
                ->pluck('resident_name')
            : collect();

        // If a client/resident is selected, pass their active medications
        $clientMedications = [];
        $clientIdForContext = $selectedShift?->client_id ?: ($request->filled('client_id') ? (int) $request->input('client_id') : null);

        if ($clientIdForContext && Schema::hasTable('client_medications')) {
            $clientMedications = ClientMedication::where('client_id', $clientIdForContext)
                ->where('active', true)
                ->whereNull('ceased_at')
                ->where(function ($q) {
                    $q->where('is_prn', true)
                      ->orWhereNotNull('dose_times');
                })
                ->get(['id', 'name', 'dosage', 'frequency', 'is_prn', 'controlled_drug', 'dose_times', 'route', 'instructions'])
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'dosage' => $m->dosage,
                    'frequency' => $m->frequency,
                    'is_prn' => (bool) $m->is_prn,
                    'controlled_drug' => (bool) $m->controlled_drug,
                    'dose_times' => $m->dose_times,
                    'route' => $m->route,
                    'instructions' => $m->instructions,
                ]);
        }

        // Get clients for resident selection
        $clients = [];
        if (Schema::hasTable('clients')) {
            $clients = Client::query()
                ->orderBy('first_name')
                ->limit(200)
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                ]);
        }

        $shifts = Shift::query()
            ->whereIn('status', ['draft', 'scheduled', 'in_progress'])
            ->where('ends_at', '>=', now()->subHours(12))
            ->with(['client:id,first_name,last_name', 'staff:id,name', 'serviceContext:id,name'])
            ->orderBy('starts_at')
            ->limit(100)
            ->get()
            ->map(fn ($shift) => [
                'id' => $shift->id,
                'client_id' => $shift->client_id,
                'client_name' => $shift->client ? trim(($shift->client->first_name ?? '') . ' ' . ($shift->client->last_name ?? '')) : null,
                'staff_name' => $shift->staff?->name,
                'starts_at' => optional($shift->starts_at)->toISOString(),
                'ends_at' => optional($shift->ends_at)->toISOString(),
                'status' => $shift->status,
                'shift_type' => $shift->shift_type ?? 'standard',
                'location' => $shift->location,
                'service_context' => $shift->serviceContext?->name,
            ])
            ->values();

        return Inertia::render('fleet-assets/transports/create', [
            'vehicles' => $vehicles,
            'recent_residents' => $recentResidents,
            'clients' => $clients,
            'client_medications' => $clientMedications,
            'shifts' => $shifts,
            'selected_shift_id' => $selectedShift?->id,
            'auth_user' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'resident_name' => ['required', 'string', 'max:255'],
            'transport_type' => ['required', 'string', 'in:medical,respite,community,shopping,appointment,social,other'],
            'pickup_location' => ['nullable', 'string', 'max:255'],
            'dropoff_location' => ['nullable', 'string', 'max:255'],
            'departed_at' => ['required', 'date'],
            'passengers_count' => ['nullable', 'integer', 'min:1', 'max:20'],
            'supervisor_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'booking_id' => ['nullable', 'integer', 'exists:fleet_vehicle_bookings,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            // Medications to pack
            'medications' => ['nullable', 'array'],
            'medications.*.medication_id' => ['required', 'integer'],
            'medications.*.medication_name' => ['required', 'string', 'max:255'],
            'medications.*.is_controlled_drug' => ['required', 'boolean'],
            'medications.*.witness_name' => ['nullable', 'string', 'max:255'],
        ]);

        $shift = null;
        if (! empty($data['shift_id'])) {
            $shift = Shift::query()->with(['client:id,first_name,last_name,site_id', 'staff:id,name', 'serviceContext:id,name'])->findOrFail($data['shift_id']);

            if (! empty($data['client_id']) && $shift->client_id && (int) $data['client_id'] !== (int) $shift->client_id) {
                throw ValidationException::withMessages([
                    'shift_id' => 'The selected shift does not match the selected resident.',
                ]);
            }

            $departedAt = \Illuminate\Support\Carbon::parse($data['departed_at']);
            $allowedStart = $shift->starts_at?->copy()->subHours(2);
            $allowedEnd = $shift->ends_at?->copy()->addHours(2);

            if (($allowedStart && $departedAt->lt($allowedStart)) || ($allowedEnd && $departedAt->gt($allowedEnd))) {
                throw ValidationException::withMessages([
                    'departed_at' => 'Transport must align with the linked shift window. Use a time within two hours of the shift boundary or unlink the transport from the shift.',
                ]);
            }

            if (! $this->coverageRoles->userHasRole($request->user(), 'driver')) {
                throw ValidationException::withMessages([
                    'asset_id' => 'Only staff with current driver eligibility can log a resident transport against a shift.',
                ]);
            }

            if ($shift->client_id) {
                $data['client_id'] = $shift->client_id;
                $data['resident_id'] = $shift->client_id;
                $data['resident_name'] = trim(($shift->client?->first_name ?? '') . ' ' . ($shift->client?->last_name ?? ''));
            }

            $data['service_context_id'] = $shift->service_context_id;
            $data['pickup_location'] = $data['pickup_location'] ?: $shift->location;
        } elseif (! empty($data['client_id'])) {
            $data['resident_id'] = $data['client_id'];
        }

        $data['driver_user_id'] = $request->user()->id;
        $data['status'] = 'in_progress';
        $data['passengers_count'] = $data['passengers_count'] ?? 1;
        $clientSnapshot = ! $shift && ! empty($data['client_id'])
            ? $this->snapshots->snapshotForClient(
                Client::query()->with(['site:id,name', 'serviceContext:id,name'])->find((int) $data['client_id']),
                $request->user(),
                $data['pickup_location'] ?? null,
            )
            : null;
        $transport = FleetResidentTransport::create([
            ...collect($data)->except(['medications', 'client_id'])->toArray(),
            ...($shift
                ? $this->snapshots->transportSnapshotForShift($shift, $request->user())
                : [
                    'site_id' => $clientSnapshot['site_id'] ?? null,
                    'site_name_snapshot' => $clientSnapshot['site_name'] ?? null,
                    'shift_location_snapshot' => $clientSnapshot['location'] ?? null,
                    'service_context_name_snapshot' => $clientSnapshot['service_context_name'] ?? null,
                    'driver_name_snapshot' => $clientSnapshot['staff_name'] ?? $request->user()->name,
                ]),
        ]);

        // Create medication transit logs if medications were packed
        if (!empty($data['medications']) && !empty($data['client_id']) && Schema::hasTable('fleet_medication_transit_logs')) {
            foreach ($data['medications'] as $med) {
                FleetMedicationTransitLog::create([
                    'transport_id' => $transport->id,
                    'client_id' => $data['client_id'],
                    'medication_id' => $med['medication_id'],
                    'medication_name' => $med['medication_name'],
                    'is_controlled_drug' => $med['is_controlled_drug'],
                    'packed_by_user_id' => $request->user()->id,
                    'packed_at' => now(),
                ]);
            }
        }

        AuditLogger::log('fleet.transport.create', $transport, [
            'asset_id' => $data['asset_id'],
            'resident_name' => $data['resident_name'],
            'medications_packed' => count($data['medications'] ?? []),
        ]);

        return redirect()->route('fleet-assets.transports.show', $transport)
            ->with('success', 'Transport log created.');
    }

    public function show(Request $request, FleetResidentTransport $transport)
    {
        $transport->load(['asset:id,name,asset_tag', 'driver:id,name,email', 'booking']);
        $transport->load([
            'shift:id,client_id,user_id,starts_at,ends_at,shift_type,location,service_context_id',
            'shift.staff:id,name',
            'shift.serviceContext:id,name',
            'serviceContext:id,name',
        ]);

        // Vehicle position for live map during active transport
        $vehiclePosition = null;
        if ($transport->status === 'in_progress' && $transport->asset_id) {
            $tracker = \App\Models\LocationHardware::where('linked_asset_id', $transport->asset_id)
                ->where('category', \App\Models\LocationHardware::CATEGORY_TRACKER)
                ->first();
            if ($tracker && $tracker->meta) {
                $meta = $tracker->meta;
                if (($meta['lat'] ?? $meta['latitude'] ?? null) !== null) {
                    $vehiclePosition = [
                        'lat' => (float) ($meta['lat'] ?? $meta['latitude']),
                        'lng' => (float) ($meta['lng'] ?? $meta['longitude'] ?? 0),
                        'heading' => $meta['heading'] ?? null,
                        'speed' => $meta['speed'] ?? null,
                    ];
                }
            }
        }

        // Pre-check status
        $preCheckStatus = null;
        if (Schema::hasTable('fleet_transport_pre_checks')) {
            $preCheck = DB::table('fleet_transport_pre_checks')
                ->where('transport_id', $transport->id)
                ->first();
            $preCheckStatus = $preCheck ? 'completed' : 'pending';
        }

        // Care needs (from client support plan if available)
        $careNeeds = [];
        $clientId = $transport->resident_id;
        if ($clientId && Schema::hasTable('client_support_plan_items')) {
            $careNeeds = DB::table('client_support_plan_items')
                ->where('client_id', $clientId)
                ->whereIn('category', ['transport', 'mobility'])
                ->select('id', 'label', 'notes')
                ->limit(10)
                ->get()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'label' => $item->label,
                    'notes' => $item->notes,
                ])
                ->toArray();
        }

        return Inertia::render('fleet-assets/transports/show', [
            'transport' => [
                'id' => $transport->id,
                'asset' => $transport->asset ? [
                    'id' => $transport->asset->id,
                    'name' => $transport->asset->name,
                    'asset_tag' => $transport->asset->asset_tag,
                ] : null,
                'driver' => $transport->driver ? [
                    'id' => $transport->driver->id,
                    'name' => $transport->driver->name,
                    'email' => $transport->driver->email,
                ] : null,
                'booking' => $transport->booking ? [
                    'id' => $transport->booking->id,
                    'purpose' => $transport->booking->purpose,
                ] : null,
                'shift' => $transport->shift ? [
                    'id' => $transport->shift->id,
                    'starts_at' => optional($transport->shift->starts_at)->toISOString(),
                    'ends_at' => optional($transport->shift->ends_at)->toISOString(),
                    'shift_type' => $transport->shift->shift_type ?? 'standard',
                    'location' => $transport->shift->location,
                    'service_context' => $transport->shift->serviceContext?->name,
                    'staff_name' => $transport->shift->staff?->name,
                ] : null,
                'service_context' => $transport->serviceContext?->name ?? $transport->shift?->serviceContext?->name,
                'site_name' => $transport->site_name_snapshot,
                'resident_name' => $transport->resident_name,
                'transport_type' => $transport->transport_type,
                'pickup_location' => $transport->pickup_location,
                'dropoff_location' => $transport->dropoff_location,
                'departed_at' => optional($transport->departed_at)->toISOString(),
                'arrived_at' => optional($transport->arrived_at)->toISOString(),
                'passengers_count' => $transport->passengers_count,
                'supervisor_name' => $transport->supervisor_name,
                'notes' => $transport->notes,
                'status' => $transport->status,
                'duration_minutes' => $transport->duration_minutes,
                'created_at' => optional($transport->created_at)->toISOString(),
            ],
            'vehicle_position' => $vehiclePosition,
            'pre_check_status' => $preCheckStatus,
            'care_needs' => $careNeeds,
            'completion_blockers' => $this->getCompletionBlockers($transport),
        ]);
    }

    private function getCompletionBlockers(FleetResidentTransport $transport): array
    {
        $blockers = [];

        if ($transport->status !== 'in_progress') {
            return $blockers;
        }

        if (Schema::hasTable('fleet_medication_transit_logs')) {
            $unresolvedMeds = FleetMedicationTransitLog::query()
                ->where('transport_id', $transport->id)
                ->whereNull('administered_at')
                ->whereNull('returned_to_house_at')
                ->count();

            if ($unresolvedMeds > 0) {
                $blockers[] = [
                    'type' => 'unresolved_medications',
                    'count' => $unresolvedMeds,
                    'message' => "{$unresolvedMeds} medication(s) not yet administered or returned",
                ];
            }

            $controlledMissingWitness = FleetMedicationTransitLog::query()
                ->where('transport_id', $transport->id)
                ->where('is_controlled_drug', true)
                ->whereNotNull('administered_at')
                ->whereNull('witnessed_by_user_id')
                ->count();

            if ($controlledMissingWitness > 0) {
                $blockers[] = [
                    'type' => 'controlled_drug_witness',
                    'count' => $controlledMissingWitness,
                    'message' => "{$controlledMissingWitness} controlled drug(s) administered without witness",
                ];
            }
        }

        return $blockers;
    }

    public function complete(Request $request, FleetResidentTransport $transport)
    {
        $data = $request->validate([
            'arrived_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Verify all medications are accounted for (administered or returned)
        $unresolvedMeds = FleetMedicationTransitLog::query()
            ->where('transport_id', $transport->id)
            ->whereNull('administered_at')
            ->whereNull('returned_to_house_at')
            ->count();

        if ($unresolvedMeds > 0) {
            return back()->with('error', "Cannot complete transport: {$unresolvedMeds} medication(s) still unresolved. All medications must be administered or returned to house first.");
        }

        $transport->update([
            'status' => 'completed',
            'arrived_at' => $data['arrived_at'] ?? now(),
            'notes' => $data['notes'] ?? $transport->notes,
        ]);

        AuditLogger::log('fleet.transport.complete', $transport);

        return redirect()->route('fleet-assets.transports.show', $transport)
            ->with('success', 'Transport marked as completed.');
    }

    /* ------------------------------------------------------------------
     *  Medication-in-Transit
     * ------------------------------------------------------------------ */

    public function medicationIndex(Request $request)
    {
        if (!Schema::hasTable('fleet_medication_transit_logs')) {
            return Inertia::render('fleet-assets/transports/medications', [
                'logs' => ['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]],
                'filters' => $request->only(['date_from', 'date_to', 'client_id', 'status']),
                'clients' => [],
                'stats' => [
                    'total_packed_today' => 0,
                    'controlled_drugs_out' => 0,
                    'awaiting_return' => 0,
                ],
            ]);
        }

        $query = FleetMedicationTransitLog::query()
            ->with(['client:id,first_name,last_name', 'packedBy:id,name', 'administeredBy:id,name', 'witnessedBy:id,name']);

        if ($request->filled('client_id')) {
            $query->where('client_id', (int) $request->input('client_id'));
        }

        if ($request->filled('date_from')) {
            $query->where('packed_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('packed_at', '<=', $request->input('date_to') . ' 23:59:59');
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status === 'packed') {
                $query->whereNull('administered_at')->whereNull('returned_to_house_at');
            } elseif ($status === 'administered') {
                $query->whereNotNull('administered_at')->whereNull('returned_to_house_at');
            } elseif ($status === 'returned') {
                $query->whereNotNull('returned_to_house_at');
            }
        }

        // CSV export for compliance
        if ($request->input('export') === 'csv') {
            $all = (clone $query)->latest('packed_at')->limit(5000)->get();
            return response()->streamDownload(function () use ($all) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['ID', 'Resident', 'Medication', 'Controlled Drug', 'Packed By', 'Packed At', 'Administered By', 'Administered At', 'Witnessed By', 'Returned At', 'Notes']);
                foreach ($all as $log) {
                    fputcsv($handle, [
                        $log->id,
                        trim(($log->client?->first_name ?? '') . ' ' . ($log->client?->last_name ?? '')),
                        $log->medication_name,
                        $log->is_controlled_drug ? 'Yes' : 'No',
                        $log->packedBy?->name ?? '',
                        optional($log->packed_at)->format('Y-m-d H:i') ?? '',
                        $log->administeredBy?->name ?? '',
                        optional($log->administered_at)->format('Y-m-d H:i') ?? '',
                        $log->witnessedBy?->name ?? '',
                        optional($log->returned_to_house_at)->format('Y-m-d H:i') ?? '',
                        $log->notes ?? '',
                    ]);
                }
                fclose($handle);
            }, 'medication-transit-audit-' . now()->format('Y-m-d') . '.csv');
        }

        $logs = $query->latest('packed_at')->paginate(25)->withQueryString();

        // Stats
        $today = now()->startOfDay();
        $totalPackedToday = FleetMedicationTransitLog::where('packed_at', '>=', $today)->count();
        $controlledDrugsOut = FleetMedicationTransitLog::where('is_controlled_drug', true)
            ->whereNull('returned_to_house_at')
            ->count();
        $awaitingReturn = FleetMedicationTransitLog::whereNull('returned_to_house_at')->count();

        $clients = [];
        if (Schema::hasTable('clients')) {
            $clients = Client::query()->orderBy('first_name')->limit(200)
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                ]);
        }

        return Inertia::render('fleet-assets/transports/medications', [
            'logs' => [
                'data' => $logs->getCollection()->map(fn ($log) => [
                    'id' => $log->id,
                    'client' => $log->client ? [
                        'id' => $log->client->id,
                        'name' => trim(($log->client->first_name ?? '') . ' ' . ($log->client->last_name ?? '')),
                    ] : null,
                    'medication_name' => $log->medication_name,
                    'is_controlled_drug' => $log->is_controlled_drug,
                    'packed_by' => $log->packedBy ? ['id' => $log->packedBy->id, 'name' => $log->packedBy->name] : null,
                    'packed_at' => optional($log->packed_at)->toISOString(),
                    'administered_by' => $log->administeredBy ? ['id' => $log->administeredBy->id, 'name' => $log->administeredBy->name] : null,
                    'administered_at' => optional($log->administered_at)->toISOString(),
                    'witnessed_by' => $log->witnessedBy ? ['id' => $log->witnessedBy->id, 'name' => $log->witnessedBy->name] : null,
                    'returned_to_house_at' => optional($log->returned_to_house_at)->toISOString(),
                    'status' => $log->status,
                    'notes' => $log->notes,
                ])->values(),
                'links' => $logs->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'total' => $logs->total(),
                ],
            ],
            'filters' => $request->only(['date_from', 'date_to', 'client_id', 'status']),
            'clients' => $clients,
            'stats' => [
                'total_packed_today' => $totalPackedToday,
                'controlled_drugs_out' => $controlledDrugsOut,
                'awaiting_return' => $awaitingReturn,
            ],
        ]);
    }

    public function packMedication(Request $request, FleetResidentTransport $transport)
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'medication_id' => ['nullable', 'integer'],
            'medication_name' => ['required', 'string', 'max:255'],
            'is_controlled_drug' => ['required', 'boolean'],
            'witness_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $log = FleetMedicationTransitLog::create([
            'transport_id' => $transport->id,
            'client_id' => $data['client_id'],
            'medication_id' => $data['medication_id'] ?? null,
            'medication_name' => $data['medication_name'],
            'is_controlled_drug' => $data['is_controlled_drug'],
            'packed_by_user_id' => $request->user()->id,
            'packed_at' => now(),
            'notes' => $data['notes'] ?? null,
        ]);

        AuditLogger::log('fleet.medication.pack', $log, [
            'transport_id' => $transport->id,
            'medication_name' => $data['medication_name'],
            'controlled_drug' => $data['is_controlled_drug'],
        ]);

        // Notify medication managers when a controlled drug is packed
        if ($data['is_controlled_drug']) {
            $log->load(['client:id,first_name,last_name', 'packedBy:id,name']);
            User::whereHas('roles', function ($q) {
                $q->whereHas('permissions', fn ($p) => $p->where('key', 'fleet.medication.manage'));
            })->get()->each->notify(new FleetControlledDrugTransitNotification($log));
        }

        return back()->with('success', 'Medication packed for transit.');
    }

    public function administerMedication(Request $request, FleetMedicationTransitLog $log)
    {
        $rules = [
            'witnessed_by_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];

        // NZ regulation: controlled drugs require a witness
        if ($log->is_controlled_drug) {
            $rules['witnessed_by_user_id'] = ['required', 'integer', 'exists:users,id'];
        }

        $data = $request->validate($rules);

        $log->update([
            'administered_at' => now(),
            'administered_by_user_id' => $request->user()->id,
            'witnessed_by_user_id' => $data['witnessed_by_user_id'] ?? null,
            'notes' => $data['notes'] ?? $log->notes,
        ]);

        AuditLogger::log('fleet.medication.administer', $log, [
            'medication_name' => $log->medication_name,
            'controlled_drug' => $log->is_controlled_drug,
        ]);

        return back()->with('success', 'Medication administration recorded.');
    }

    public function returnMedication(Request $request, FleetMedicationTransitLog $log)
    {
        $log->update([
            'returned_to_house_at' => now(),
            'notes' => $request->input('notes') ?? $log->notes,
        ]);

        AuditLogger::log('fleet.medication.return', $log, [
            'medication_name' => $log->medication_name,
        ]);

        return back()->with('success', 'Medication returned to house.');
    }

    /* ------------------------------------------------------------------
     *  Pre-Transport Safety Check
     * ------------------------------------------------------------------ */

    public function preCheck(Request $request, FleetResidentTransport $transport)
    {
        $transport->load(['asset:id,name,asset_tag']);

        // Check if pre-check already completed
        $preCheckCompleted = false;
        if (Schema::hasTable('fleet_transport_pre_checks')) {
            $preCheckCompleted = DB::table('fleet_transport_pre_checks')
                ->where('transport_id', $transport->id)
                ->exists();
        }

        // Load care needs, emergency contacts, medications by client_id
        $careNeeds = [];
        $emergencyContacts = [];
        $medications = [];

        $client = $transport->resident_id ? \App\Models\Client::find($transport->resident_id) : null;

        if ($client) {
            // Care needs from support plan
            if (Schema::hasTable('client_support_plan_items')) {
                $careNeeds = DB::table('client_support_plan_items')
                    ->where('client_id', $client->id)
                    ->select('id', 'label', 'notes')
                    ->limit(20)
                    ->get()
                    ->map(fn ($item) => [
                        'id' => $item->id,
                        'label' => $item->label,
                        'notes' => $item->notes ?? null,
                    ])
                    ->toArray();
            }

            // Emergency contacts
            if (Schema::hasTable('client_emergency_contacts')) {
                $emergencyContacts = DB::table('client_emergency_contacts')
                    ->where('client_id', $client->id)
                    ->select('name', 'relation', 'phone')
                    ->limit(5)
                    ->get()
                    ->map(fn ($c) => [
                        'name' => $c->name,
                        'relation' => $c->relation ?? '',
                        'phone' => $c->phone ?? '',
                    ])
                    ->toArray();
            }

            // Medications
            if (Schema::hasTable('client_medications')) {
                $medications = DB::table('client_medications')
                    ->where('client_id', $client->id)
                    ->where('is_active', true)
                    ->select('name', 'dosage', 'frequency')
                    ->limit(20)
                    ->get()
                    ->map(fn ($m) => [
                        'name' => $m->name,
                        'dosage' => $m->dosage ?? null,
                        'frequency' => $m->frequency ?? null,
                    ])
                    ->toArray();
            }
        }

        return Inertia::render('fleet-assets/transports/pre-check', [
            'transport' => [
                'id' => $transport->id,
                'resident_name' => $transport->resident_name,
                'transport_type' => $transport->transport_type,
                'status' => $transport->status,
                'asset' => $transport->asset ? [
                    'id' => $transport->asset->id,
                    'name' => $transport->asset->name,
                    'asset_tag' => $transport->asset->asset_tag,
                ] : null,
            ],
            'care_needs' => $careNeeds,
            'emergency_contacts' => $emergencyContacts,
            'medications' => $medications,
            'pre_check_completed' => $preCheckCompleted,
        ]);
    }

    public function savePreCheck(Request $request, FleetResidentTransport $transport)
    {
        $data = $request->validate([
            'checks' => ['required', 'array'],
            'checks.*' => ['nullable', 'boolean'],
        ]);

        // Store the pre-check result
        if (Schema::hasTable('fleet_transport_pre_checks')) {
            DB::table('fleet_transport_pre_checks')->updateOrInsert(
                ['transport_id' => $transport->id],
                [
                    'checks' => json_encode($data['checks']),
                    'completed_by_user_id' => $request->user()->id,
                    'completed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        AuditLogger::log('fleet.transport.pre_check', $transport, [
            'checks' => $data['checks'],
        ]);

        return redirect()->route('fleet-assets.transports.show', $transport)
            ->with('success', 'Pre-transport safety check completed.');
    }
}
