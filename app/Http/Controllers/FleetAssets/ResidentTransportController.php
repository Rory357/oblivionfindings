<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\FleetMedicationTransitLog;
use App\Models\FleetResidentTransport;
use App\Models\FleetResidentTransportEvent;
use App\Models\Shift;
use App\Models\User;
use App\Services\Fleet\ResidentTransportJourneyScope;
use App\Services\Fleet\ResidentTransportJourneyService;
use App\Services\Medication\ControlledMedicationTransportWitnessService;
use App\Services\MedicationScanVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ResidentTransportController extends Controller
{
    public function __construct(
        protected MedicationScanVerificationService $scanVerificationService,
        protected ResidentTransportJourneyScope $journeyScope,
        protected ResidentTransportJourneyService $journeys,
        protected ControlledMedicationTransportWitnessService $transportWitnesses,
    ) {}

    private function canManageMedicationTransit(?User $user): bool
    {
        return $this->journeyScope->canManageMedicationTransit($user);
    }

    private function assertCanManageMedicationTransit(Request $request): void
    {
        abort_unless(
            $this->canManageMedicationTransit($request->user()),
            403,
            'You do not have permission to manage medications in transit.'
        );
    }

    private function buildMedicationScanPayload(Client $client, ClientMedication $medication): array
    {
        $payload = $this->scanVerificationService->payload($client, $medication);
        $payload['svg_url'] = route('api.medications.scan_code.svg', [
            'client' => $client->id,
            'medication' => $medication->id,
        ]);

        return $payload;
    }

    public function index(Request $request)
    {
        $formOptions = $this->formOptions($request);

        if (! Schema::hasTable('fleet_resident_transports')) {
            return Inertia::render('fleet-assets/transports/index', [
                'transports' => [
                    'data' => [],
                    'links' => [],
                    'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0],
                ],
                'filters' => $request->only(['transport_type', 'asset_id', 'status', 'search', 'date_from', 'date_to']),
                'vehicles' => $formOptions['vehicles'],
                'stats' => [
                    'total_this_month' => 0,
                    'residents_this_month' => 0,
                    'avg_duration_minutes' => 0,
                    'most_active_vehicle' => null,
                ],
                'hero' => [
                    'today' => 0,
                    'in_progress' => 0,
                    'completed_7d' => 0,
                    'with_medications_7d' => 0,
                ],
                ...$formOptions,
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
        $this->journeyScope->applyTransportScope($query, $request->user());

        if ($request->filled('transport_type')) {
            $query->where('transport_type', $request->input('transport_type'));
        }

        if ($request->filled('asset_id')) {
            abort_unless(
                collect($formOptions['vehicles'])->contains('id', (int) $request->input('asset_id')),
                404,
            );
            $query->where('asset_id', (int) $request->input('asset_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $query->where('resident_name', 'like', '%'.$request->input('search').'%');
        }

        if ($request->filled('date_from')) {
            $query->where('departed_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('departed_at', '<=', $request->input('date_to').' 23:59:59');
        }

        // CSV export
        if ($request->input('export') === 'csv') {
            $exportQuery = (clone $query)->latest('departed_at');
            $canViewCareContext = $this->journeyScope->canViewResidentCareContext($request->user());

            return response()->streamDownload(function () use ($exportQuery, $canViewCareContext) {
                $handle = fopen('php://output', 'w');
                $this->putCsv($handle, ['ID', 'Vehicle', 'Driver', 'Resident', 'Type', 'Pickup', 'Dropoff', 'Departed', 'Arrived', 'Duration (min)', 'Passengers', 'Supervisor', 'Status', 'Notes']);
                foreach ($exportQuery->lazy(200) as $t) {
                    $duration = ($t->departed_at && $t->arrived_at)
                        ? round($t->departed_at->diffInMinutes($t->arrived_at), 1)
                        : '';
                    $this->putCsv($handle, [
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
                        $canViewCareContext ? ($t->supervisor_name ?? '') : '',
                        $t->status,
                        $canViewCareContext ? ($t->notes ?? '') : '',
                    ]);
                }
                fclose($handle);
            }, 'resident-transports-'.now()->format('Y-m-d').'.csv');
        }

        $transports = $query->latest('departed_at')->paginate(25)->withQueryString();

        // Summary stats
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $monthQuery = FleetResidentTransport::query()
            ->whereBetween('departed_at', [$monthStart, $monthEnd]);
        $this->journeyScope->applyTransportScope($monthQuery, $request->user());

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
                    'supervisor_name' => null,
                    'status' => $t->status,
                    'duration_minutes' => $t->duration_minutes,
                    'notes' => null,
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
            'vehicles' => $formOptions['vehicles'],
            'stats' => [
                'total_this_month' => $totalThisMonth,
                'residents_this_month' => $residentsThisMonth,
                'avg_duration_minutes' => round((float) ($avgDuration ?? 0), 1),
                'most_active_vehicle' => $mostActiveVehicleName,
            ],
            'hero' => $this->transportHero($request),
            ...$formOptions,
        ]);
    }

    private function transportHero(Request $request): array
    {
        $base = FleetResidentTransport::query();
        $this->journeyScope->applyTransportScope($base, $request->user());

        $withMedications = 0;
        if (
            Schema::hasTable('fleet_medication_transit_logs')
            && $this->journeyScope->canViewMedicationTransit($request->user())
        ) {
            $medications = FleetMedicationTransitLog::query()
                ->where('created_at', '>=', now()->subDays(7));
            $this->journeyScope->applyMedicationTransitScope($medications, $request->user());
            $withMedications = $medications->distinct('transport_id')->count('transport_id');
        }

        return [
            'today' => (clone $base)->whereDate('departed_at', today())->count(),
            'in_progress' => (clone $base)->where('status', 'in_progress')->count(),
            'completed_7d' => (clone $base)
                ->where('status', 'completed')
                ->where('departed_at', '>=', now()->subDays(7))
                ->count(),
            'with_medications_7d' => $withMedications,
        ];
    }

    private function formOptions(Request $request): array
    {
        $actor = $request->user();
        $selectedShift = null;
        $selectedClient = null;

        if ($request->filled('shift_id')) {
            $selectedShift = $this->journeyScope->shiftFor($actor, (int) $request->input('shift_id'));
            $selectedShift->load(['client:id,first_name,last_name,site_id', 'staff:id,name', 'serviceContext:id,name']);
            abort_unless($selectedShift->client_id && $selectedShift->client, 404);
            abort_unless((int) $selectedShift->user_id === (int) $actor->id, 404);
            $selectedClient = $this->journeyScope->clientFor($actor, (int) $selectedShift->client_id);
            if ($request->filled('client_id')) {
                abort_unless((int) $request->input('client_id') === (int) $selectedClient->id, 404);
            }
        } elseif ($request->filled('client_id')) {
            $selectedClient = $this->journeyScope->clientFor($actor, (int) $request->input('client_id'));
        }

        $vehicleQuery = Asset::query()->vehicles()->where('status', 'active')->orderBy('name');
        $this->journeyScope->applyVehicleScope($vehicleQuery, $actor);
        if ($selectedClient?->site_id) {
            $siteId = (int) $selectedClient->site_id;
            $clientId = (int) $selectedClient->id;
            $vehicleQuery->where(function ($vehicle) use ($siteId): void {
                $vehicle->where('site_id', $siteId)
                    ->orWhere(fn ($homeSite) => $homeSite->whereNull('site_id')->where('home_site_id', $siteId));
            })->where(fn ($residentBinding) => $residentBinding
                ->whereNull('client_id')
                ->orWhere('client_id', $clientId));
        } else {
            $vehicleQuery->whereNull('client_id');
        }
        $vehicles = $vehicleQuery->limit(200)->get(['id', 'name', 'asset_tag']);

        $recentResidents = collect();
        if (Schema::hasTable('fleet_resident_transports')) {
            $recentQuery = FleetResidentTransport::query()
                ->select('resident_name')
                ->distinct()
                ->orderBy('resident_name')
                ->limit(100);
            $this->journeyScope->applyTransportScope($recentQuery, $actor);
            $recentResidents = $recentQuery->pluck('resident_name');
        }

        $clientQuery = Client::query()->orderBy('first_name')->limit(200);
        $this->journeyScope->applyClientScope($clientQuery, $actor);
        $clients = $clientQuery
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn ($client) => [
                'id' => $client->id,
                'name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
            ])->values();

        $shiftQuery = Shift::query()
            ->whereNotNull('client_id')
            ->where('user_id', $actor->id)
            ->whereIn('status', ['draft', 'scheduled', 'in_progress'])
            ->where('ends_at', '>=', now()->subHours(12))
            ->with(['client:id,first_name,last_name,site_id', 'staff:id,name', 'serviceContext:id,name'])
            ->orderBy('starts_at')
            ->limit(100);
        $this->journeyScope->applyShiftScope($shiftQuery, $actor);
        if ($selectedClient) {
            $shiftQuery->where('client_id', $selectedClient->id);
        }
        $shifts = $shiftQuery->get()->map(fn ($shift) => [
            'id' => $shift->id,
            'client_id' => $shift->client_id,
            'client_name' => $shift->client
                ? trim(($shift->client->first_name ?? '').' '.($shift->client->last_name ?? ''))
                : null,
            'staff_name' => $shift->staff?->name,
            'starts_at' => optional($shift->starts_at)->toISOString(),
            'ends_at' => optional($shift->ends_at)->toISOString(),
            'status' => $shift->status,
            'shift_type' => $shift->shift_type ?? 'standard',
            'location' => $shift->location,
            'service_context' => $shift->serviceContext?->name,
        ])->values();

        $clientMedications = collect();
        $medicationWitnesses = collect();
        if ($selectedClient && $this->canManageMedicationTransit($actor) && Schema::hasTable('client_medications')) {
            $clientMedications = ClientMedication::query()
                ->active()
                ->where('client_id', $selectedClient->id)
                ->where(fn ($query) => $query->where('is_prn', true)->orWhereNotNull('dose_times'))
                ->get([
                    'id', 'client_id', 'name', 'dosage', 'frequency', 'is_prn',
                    'controlled_drug', 'witness_required', 'dose_times', 'route', 'instructions',
                    'barcode', 'nzulm_code',
                ])
                ->map(fn ($medication) => [
                    'id' => $medication->id,
                    'name' => $medication->name,
                    'dosage' => $medication->dosage,
                    'frequency' => $medication->frequency,
                    'is_prn' => (bool) $medication->is_prn,
                    'controlled_drug' => (bool) $medication->controlled_drug,
                    'witness_required' => $medication->requiresWitness(),
                    'dose_times' => $medication->dose_times,
                    'route' => $medication->route,
                    'instructions' => $medication->instructions,
                    'scan_verification' => $this->buildMedicationScanPayload($selectedClient, $medication),
                ]);

            $medicationWitnesses = $this->transportWitnesses
                ->eligibleWitnessesForSite((int) $selectedClient->site_id, now(), (int) $actor->id)
                ->map(fn (User $witness): array => [
                    'id' => $witness->id,
                    'name' => $witness->name,
                ]);
        }

        return [
            'vehicles' => $vehicles,
            'recent_residents' => $recentResidents,
            'clients' => $clients,
            'client_medications' => $clientMedications,
            'medication_witnesses' => $medicationWitnesses,
            'shifts' => $shifts,
            'selected_shift_id' => $selectedShift?->id,
            'auth_user' => [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
            ],
        ];
    }

    public function create(Request $request)
    {
        return redirect()->route('fleet-assets.transports.index', array_filter([
            'new' => 1,
            'shift_id' => $request->query('shift_id'),
            'client_id' => $request->query('client_id'),
        ]));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer'],
            'shift_id' => ['nullable', 'integer'],
            'resident_name' => ['required', 'string', 'max:255'],
            'transport_type' => ['required', 'string', 'in:medical,respite,community,shopping,appointment,social,other'],
            'pickup_location' => ['nullable', 'string', 'max:255'],
            'dropoff_location' => ['nullable', 'string', 'max:255'],
            'departed_at' => ['required', 'date'],
            'passengers_count' => ['nullable', 'integer', 'min:1', 'max:20'],
            'supervisor_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'booking_id' => ['nullable', 'integer'],
            'client_id' => ['nullable', 'integer'],
            'client_request_uuid' => ['nullable', 'uuid'],
            'medications' => ['nullable', 'array'],
            'medications.*.medication_id' => ['required', 'integer'],
            'medications.*.medication_order_version_id' => ['nullable', 'integer'],
            'medications.*.medication_name' => ['required', 'string', 'max:255'],
            'medications.*.is_controlled_drug' => ['required', 'boolean'],
            'medications.*.attestation_state' => ['nullable', 'string', 'in:accepted'],
            'medications.*.witnessed_by_user_id' => ['nullable', 'integer'],
            'medications.*.witness_credential' => ['nullable', 'string', 'max:255'],
            'medications.*.attestation_reason' => ['nullable', 'string', 'max:1000'],
            'medications.*.scan_code' => ['nullable', 'string', 'max:255'],
            'medications.*.scan_source' => ['nullable', 'string', 'in:manual,scanner'],
            'medications.*.scan_verified' => ['nullable', 'boolean'],
            'medications.*.scan_match_source' => ['nullable', 'string', 'max:50'],
        ]);

        if (! empty($data['medications'])) {
            $this->assertCanManageMedicationTransit($request);
        }

        $result = $this->journeys->create($request->user(), $data);
        $transport = $result['transport'];

        return redirect()->route('fleet-assets.transports.show', $transport)
            ->with('success', $result['replayed'] ? 'Transport log already created.' : 'Transport log created.');
    }

    public function show(Request $request, FleetResidentTransport $transport)
    {
        $transport = $this->journeyScope->transportFor($request->user(), (int) $transport->id);
        $transport->load(['asset:id,name,asset_tag', 'driver:id,name,email', 'booking']);
        $transport->load([
            'shift:id,client_id,user_id,starts_at,ends_at,shift_type,location,service_context_id',
            'shift.staff:id,name',
            'shift.serviceContext:id,name',
            'serviceContext:id,name',
        ]);

        // Vehicle position for live map during active transport.
        // Reads from canonical device linked to the transport vehicle via device_asset_links.
        $vehiclePosition = null;
        if ($transport->status === 'in_progress' && $transport->asset_id) {
            $vehicleDevice = DeviceAssetLink::query()
                ->active()
                ->forAsset($transport->asset_id)
                ->with('device')
                ->first()
                ?->device;

            if ($vehicleDevice) {
                $meta = $vehicleDevice->meta ?? [];
                $lat = $vehicleDevice->latitude ?? $meta['lat'] ?? $meta['latitude'] ?? null;
                $lng = $vehicleDevice->longitude ?? $meta['lng'] ?? $meta['longitude'] ?? null;
                if ($lat !== null) {
                    $vehiclePosition = [
                        'lat' => (float) $lat,
                        'lng' => (float) ($lng ?? 0),
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
        $canViewCareContext = $this->journeyScope->canViewResidentCareContext($request->user());
        $clientId = $transport->resident_id;
        if (
            $clientId
            && $canViewCareContext
            && Schema::hasTable('client_support_plan_items')
        ) {
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

        $canManageMedicationTransit = $this->canManageMedicationTransit($request->user());
        $canViewMedicationTransit = $this->journeyScope->canViewMedicationTransit($request->user());
        $transportClient = $transport->resident_id && $canViewMedicationTransit
            ? $this->journeyScope->clientFor($request->user(), (int) $transport->resident_id)
            : null;

        $availableMedications = [];
        if ($transportClient && $canManageMedicationTransit && Schema::hasTable('client_medications')) {
            $availableMedications = ClientMedication::query()
                ->active()
                ->where('client_id', $transportClient->id)
                ->where(function ($query) {
                    $query->where('is_prn', true)
                        ->orWhereNotNull('dose_times');
                })
                ->get([
                    'id',
                    'name',
                    'dosage',
                    'frequency',
                    'is_prn',
                    'controlled_drug',
                    'witness_required',
                    'dose_times',
                    'route',
                    'instructions',
                ])
                ->map(fn ($medication) => [
                    'id' => $medication->id,
                    'name' => $medication->name,
                    'dosage' => $medication->dosage,
                    'frequency' => $medication->frequency,
                    'is_prn' => (bool) $medication->is_prn,
                    'controlled_drug' => (bool) $medication->controlled_drug,
                    'witness_required' => $medication->requiresWitness(),
                    'dose_times' => $medication->dose_times,
                    'route' => $medication->route,
                    'instructions' => $medication->instructions,
                    'scan_verification' => $this->buildMedicationScanPayload($transportClient, $medication),
                ])
                ->values()
                ->toArray();
        }

        $transitLogs = [];
        $packingAttestationHistory = [];
        if ($canViewMedicationTransit && Schema::hasTable('fleet_medication_transit_logs')) {
            $transitQuery = FleetMedicationTransitLog::query()
                ->with([
                    'client:id,first_name,last_name',
                    'medication:id,client_id,name,dosage,barcode,nzulm_code',
                    'packedBy:id,name',
                    'packedWitness:id,name',
                    'packingAttestationEvent:id,action,witness_user_id,occurred_at,context',
                    'administeredBy:id,name',
                    'witnessedBy:id,name',
                ])
                ->where('transport_id', $transport->id)
                ->orderByDesc('packed_at');
            $this->journeyScope->applyMedicationTransitScope($transitQuery, $request->user());
            $transitLogs = $transitQuery->get()
                ->map(fn ($log) => [
                    'id' => $log->id,
                    'client' => $log->client ? [
                        'id' => $log->client->id,
                        'name' => trim(($log->client->first_name ?? '').' '.($log->client->last_name ?? '')),
                    ] : null,
                    'medication_id' => $log->medication_id,
                    'medication_name' => $log->medication_name,
                    'is_controlled_drug' => $log->is_controlled_drug,
                    'witness_required' => $log->witness_required,
                    'packed_witness_name' => $log->packed_witness_name,
                    'packed_witness' => $log->packedWitness ? [
                        'id' => $log->packedWitness->id,
                        'name' => $log->packedWitness->name,
                    ] : null,
                    'packed_witnessed_at' => optional($log->packed_witnessed_at)->toISOString(),
                    'packing_witness_method' => $log->packing_witness_method,
                    'packing_attestation_event_id' => $log->packing_attestation_event_id,
                    'packing_attestation_state' => data_get($log->packingAttestationEvent?->context, 'attestation.state'),
                    'packed_by' => $log->packedBy ? [
                        'id' => $log->packedBy->id,
                        'name' => $log->packedBy->name,
                    ] : null,
                    'packed_at' => optional($log->packed_at)->toISOString(),
                    'administered_by' => $log->administeredBy ? [
                        'id' => $log->administeredBy->id,
                        'name' => $log->administeredBy->name,
                    ] : null,
                    'administered_at' => optional($log->administered_at)->toISOString(),
                    'witnessed_by' => $log->witnessedBy ? [
                        'id' => $log->witnessedBy->id,
                        'name' => $log->witnessedBy->name,
                    ] : null,
                    'returned_to_house_at' => optional($log->returned_to_house_at)->toISOString(),
                    'status' => $log->status,
                    'notes' => $log->notes,
                    'scan_verification' => $log->client && $log->medication
                        ? $this->buildMedicationScanPayload($log->client, $log->medication)
                        : null,
                ])
                ->values()
                ->toArray();

            if (Schema::hasTable('fleet_resident_transport_events')) {
                $packingAttestationHistory = FleetResidentTransportEvent::query()
                    ->with([
                        'actor:id,name',
                        'witness:id,name',
                        'medication:id,name,dosage',
                    ])
                    ->where('transport_id', $transport->id)
                    ->where('client_id', $transport->resident_id)
                    ->where('site_id', $transport->site_id)
                    ->whereHas('medication', fn ($medication) => $medication
                        ->where('client_id', $transport->resident_id))
                    ->whereIn('action', [
                        'medication_packed',
                        'medication_packing_refused',
                        'medication_packing_unavailable',
                        'medication_packing_attestation_corrected',
                    ])
                    ->whereNotNull('context->attestation->state')
                    ->latest('id')
                    ->limit(25)
                    ->get()
                    ->map(fn (FleetResidentTransportEvent $event): array => [
                        'id' => $event->id,
                        'state' => data_get($event->context, 'attestation.state'),
                        'medication_name' => $event->medication
                            ? trim($event->medication->name.' '.($event->medication->dosage ?? ''))
                            : 'Medication record',
                        'actor_name' => $event->actor?->name,
                        'witness_name' => $event->witness?->name,
                        'occurred_at' => optional($event->occurred_at)->toISOString(),
                        'reason' => data_get($event->context, 'attestation_reason')
                            ?? data_get($event->context, 'correction_reason'),
                        'supersedes_event_id' => data_get($event->context, 'supersedes_event_id'),
                    ])
                    ->values()
                    ->toArray();
            }
        }

        $witnesses = $canManageMedicationTransit
            ? $this->transportWitnesses
                ->eligibleWitnessesForSite((int) $transport->site_id, now(), (int) $request->user()->id)
                ->map(fn ($user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                ])
                ->values()
                ->toArray()
            : [];

        return Inertia::render('fleet-assets/transports/show', [
            'transport' => [
                'id' => $transport->id,
                'resident_id' => $transport->resident_id,
                'asset' => $transport->asset ? [
                    'id' => $transport->asset->id,
                    'name' => $transport->asset->name,
                    'asset_tag' => $transport->asset->asset_tag,
                ] : null,
                'driver' => $transport->driver ? [
                    'id' => $transport->driver->id,
                    'name' => $transport->driver->name,
                    'email' => $canViewCareContext ? $transport->driver->email : null,
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
                'supervisor_name' => $canViewCareContext ? $transport->supervisor_name : null,
                'notes' => $canViewCareContext ? $transport->notes : null,
                'status' => $transport->status,
                'duration_minutes' => $transport->duration_minutes,
                'created_at' => optional($transport->created_at)->toISOString(),
            ],
            'vehicle_position' => $vehiclePosition,
            'pre_check_status' => $preCheckStatus,
            'care_needs' => $careNeeds,
            'completion_blockers' => $this->getCompletionBlockers($request, $transport),
            'medication_context' => [
                'client' => $transportClient ? [
                    'id' => $transportClient->id,
                    'name' => trim(($transportClient->first_name ?? '').' '.($transportClient->last_name ?? '')),
                ] : null,
                'available_medications' => $availableMedications,
                'transit_logs' => $transitLogs,
                'packing_attestation_history' => $packingAttestationHistory,
                'witnesses' => $witnesses,
                'can_manage' => $canManageMedicationTransit,
            ],
        ]);
    }

    private function getCompletionBlockers(Request $request, FleetResidentTransport $transport): array
    {
        $blockers = [];

        if ($transport->status !== 'in_progress') {
            return $blockers;
        }

        if (Schema::hasTable('fleet_medication_transit_logs')) {
            $unresolvedQuery = FleetMedicationTransitLog::query()->where('transport_id', $transport->id);
            $this->journeyScope->applyMedicationTransitScope($unresolvedQuery, $request->user());
            $unresolvedMeds = $unresolvedQuery
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

            $controlledQuery = FleetMedicationTransitLog::query()->where('transport_id', $transport->id);
            $this->journeyScope->applyMedicationTransitScope($controlledQuery, $request->user());
            $controlledMissingWitness = $controlledQuery
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

            $packingAttestationQuery = FleetMedicationTransitLog::query()
                ->where('transport_id', $transport->id);
            $this->journeyScope->applyMedicationTransitScope($packingAttestationQuery, $request->user());
            $packingAttestationGaps = $this->journeys
                ->governedPackingAttestationGaps($packingAttestationQuery)
                ->count();

            if ($packingAttestationGaps > 0) {
                $blockers[] = [
                    'type' => 'medication_packing_attestation',
                    'count' => $packingAttestationGaps,
                    'message' => "{$packingAttestationGaps} medication packing attestation(s) need an authenticated witness or correction",
                ];
            }
        }

        return $blockers;
    }

    public function complete(Request $request, FleetResidentTransport $transport)
    {
        $transport = $this->journeyScope->mutableTransportFor($request->user(), (int) $transport->id);
        $data = $request->validate([
            'arrived_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'client_request_uuid' => ['nullable', 'uuid'],
        ]);

        try {
            $result = $this->journeys->complete($request->user(), (int) $transport->id, $data);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->first());
        }

        return redirect()->route('fleet-assets.transports.show', $result['transport'])
            ->with('success', $result['replayed'] ? 'Transport was already marked as completed.' : 'Transport marked as completed.');
    }

    /* ------------------------------------------------------------------
     *  Medication-in-Transit
     * ------------------------------------------------------------------ */

    public function medicationIndex(Request $request)
    {
        abort_unless(
            $this->journeyScope->canViewMedicationTransit($request->user()),
            403,
            'You do not have permission to view medications in transit.',
        );
        $selectedTransport = null;

        if ($request->filled('transport_id') && Schema::hasTable('fleet_resident_transports')) {
            $selectedTransport = $this->journeyScope->transportFor(
                $request->user(),
                (int) $request->input('transport_id'),
            );
            $selectedTransport->load(['asset:id,name,asset_tag']);
        }

        if (! Schema::hasTable('fleet_medication_transit_logs')) {
            return Inertia::render('fleet-assets/transports/medications', [
                'logs' => ['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]],
                'filters' => $request->only(['date_from', 'date_to', 'client_id', 'status', 'transport_id']),
                'clients' => [],
                'witnesses' => [],
                'transport_scope' => $selectedTransport ? [
                    'id' => $selectedTransport->id,
                    'resident_name' => $selectedTransport->resident_name,
                    'transport_type' => $selectedTransport->transport_type,
                    'status' => $selectedTransport->status,
                    'departed_at' => optional($selectedTransport->departed_at)->toISOString(),
                    'arrived_at' => optional($selectedTransport->arrived_at)->toISOString(),
                    'asset' => $selectedTransport->asset ? [
                        'id' => $selectedTransport->asset->id,
                        'name' => $selectedTransport->asset->name,
                        'asset_tag' => $selectedTransport->asset->asset_tag,
                    ] : null,
                ] : null,
                'stats' => [
                    'total_packed_today' => 0,
                    'controlled_drugs_out' => 0,
                    'awaiting_return' => 0,
                ],
            ]);
        }

        $query = FleetMedicationTransitLog::query()
            ->with([
                'client:id,first_name,last_name',
                'transport:id,resident_name,transport_type,status,departed_at,arrived_at,asset_id',
                'transport.asset:id,name,asset_tag',
                'packedBy:id,name',
                'packedWitness:id,name',
                'packingAttestationEvent:id,action,witness_user_id,occurred_at,context',
                'administeredBy:id,name',
                'witnessedBy:id,name',
                'medication',
            ]);
        $this->journeyScope->applyMedicationTransitScope($query, $request->user());

        if ($request->filled('transport_id')) {
            $query->where('transport_id', (int) $request->input('transport_id'));
        }

        if ($request->filled('client_id')) {
            $this->journeyScope->clientFor($request->user(), (int) $request->input('client_id'));
            $query->where('client_id', (int) $request->input('client_id'));
        }

        if ($request->filled('date_from')) {
            $query->where('packed_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('packed_at', '<=', $request->input('date_to').' 23:59:59');
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
            $exportQuery = (clone $query)->latest('packed_at');

            return response()->streamDownload(function () use ($exportQuery) {
                $handle = fopen('php://output', 'w');
                $this->putCsv($handle, ['ID', 'Resident', 'Medication', 'Controlled Drug', 'Packed By', 'Packing Witness', 'Packing Attested At', 'Packed At', 'Administered By', 'Administered At', 'Witnessed By', 'Returned At', 'Notes']);
                foreach ($exportQuery->lazy(200) as $log) {
                    $this->putCsv($handle, [
                        $log->id,
                        trim(($log->client?->first_name ?? '').' '.($log->client?->last_name ?? '')),
                        $log->medication_name,
                        $log->is_controlled_drug ? 'Yes' : 'No',
                        $log->packedBy?->name ?? '',
                        $log->packedWitness?->name ?? ($log->packed_witness_name ? '[legacy label] '.$log->packed_witness_name : ''),
                        optional($log->packed_witnessed_at)->format('Y-m-d H:i') ?? '',
                        optional($log->packed_at)->format('Y-m-d H:i') ?? '',
                        $log->administeredBy?->name ?? '',
                        optional($log->administered_at)->format('Y-m-d H:i') ?? '',
                        $log->witnessedBy?->name ?? '',
                        optional($log->returned_to_house_at)->format('Y-m-d H:i') ?? '',
                        $log->notes ?? '',
                    ]);
                }
                fclose($handle);
            }, 'medication-transit-audit-'.now()->format('Y-m-d').'.csv');
        }

        $logs = $query->latest('packed_at')->paginate(25)->withQueryString();

        // Stats
        $today = now()->startOfDay();
        $statsQuery = FleetMedicationTransitLog::query();
        $this->journeyScope->applyMedicationTransitScope($statsQuery, $request->user());
        $totalPackedToday = (clone $statsQuery)->where('packed_at', '>=', $today)->count();
        $controlledDrugsOut = (clone $statsQuery)
            ->where('is_controlled_drug', true)
            ->whereNull('administered_at')
            ->whereNull('returned_to_house_at')
            ->count();
        $awaitingReturn = (clone $statsQuery)
            ->whereNull('administered_at')
            ->whereNull('returned_to_house_at')
            ->count();

        $clients = [];
        if (Schema::hasTable('clients')) {
            $clientQuery = Client::query()->orderBy('first_name')->limit(200);
            $this->journeyScope->applyClientScope($clientQuery, $request->user());
            $clients = $clientQuery
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => trim(($c->first_name ?? '').' '.($c->last_name ?? '')),
                ]);
        }

        return Inertia::render('fleet-assets/transports/medications', [
            'logs' => [
                'data' => $logs->getCollection()->map(fn ($log) => [
                    'id' => $log->id,
                    'transport' => $log->transport ? [
                        'id' => $log->transport->id,
                        'resident_name' => $log->transport->resident_name,
                        'transport_type' => $log->transport->transport_type,
                        'status' => $log->transport->status,
                        'departed_at' => optional($log->transport->departed_at)->toISOString(),
                        'arrived_at' => optional($log->transport->arrived_at)->toISOString(),
                        'asset' => $log->transport->asset ? [
                            'id' => $log->transport->asset->id,
                            'name' => $log->transport->asset->name,
                            'asset_tag' => $log->transport->asset->asset_tag,
                        ] : null,
                    ] : null,
                    'client' => $log->client ? [
                        'id' => $log->client->id,
                        'name' => trim(($log->client->first_name ?? '').' '.($log->client->last_name ?? '')),
                    ] : null,
                    'medication_id' => $log->medication_id,
                    'medication_name' => $log->medication_name,
                    'is_controlled_drug' => $log->is_controlled_drug,
                    'witness_required' => $log->witness_required,
                    'packed_witness_name' => $log->packed_witness_name,
                    'packed_witness' => $log->packedWitness ? ['id' => $log->packedWitness->id, 'name' => $log->packedWitness->name] : null,
                    'packed_witnessed_at' => optional($log->packed_witnessed_at)->toISOString(),
                    'packing_witness_method' => $log->packing_witness_method,
                    'packing_attestation_event_id' => $log->packing_attestation_event_id,
                    'packing_attestation_state' => data_get($log->packingAttestationEvent?->context, 'attestation.state'),
                    'packed_by' => $log->packedBy ? ['id' => $log->packedBy->id, 'name' => $log->packedBy->name] : null,
                    'packed_at' => optional($log->packed_at)->toISOString(),
                    'administered_by' => $log->administeredBy ? ['id' => $log->administeredBy->id, 'name' => $log->administeredBy->name] : null,
                    'administered_at' => optional($log->administered_at)->toISOString(),
                    'witnessed_by' => $log->witnessedBy ? ['id' => $log->witnessedBy->id, 'name' => $log->witnessedBy->name] : null,
                    'returned_to_house_at' => optional($log->returned_to_house_at)->toISOString(),
                    'status' => $log->status,
                    'notes' => $log->notes,
                    'scan_verification' => $log->client && $log->medication
                        ? $this->buildMedicationScanPayload($log->client, $log->medication)
                        : null,
                ])->values(),
                'links' => $logs->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'last_page' => $logs->lastPage(),
                    'total' => $logs->total(),
                ],
            ],
            'filters' => $request->only(['date_from', 'date_to', 'client_id', 'status', 'transport_id']),
            'clients' => $clients,
            'witnesses' => $this->transportWitnesses->eligibleWitnessesForSites(
                $selectedTransport
                    ? [(int) $selectedTransport->site_id]
                    : $this->journeyScope->accessibleSiteIds($request->user()),
                now(),
                (int) $request->user()->id,
            )->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
            ])->values(),
            'transport_scope' => $selectedTransport ? [
                'id' => $selectedTransport->id,
                'resident_name' => $selectedTransport->resident_name,
                'transport_type' => $selectedTransport->transport_type,
                'status' => $selectedTransport->status,
                'departed_at' => optional($selectedTransport->departed_at)->toISOString(),
                'arrived_at' => optional($selectedTransport->arrived_at)->toISOString(),
                'asset' => $selectedTransport->asset ? [
                    'id' => $selectedTransport->asset->id,
                    'name' => $selectedTransport->asset->name,
                    'asset_tag' => $selectedTransport->asset->asset_tag,
                ] : null,
            ] : null,
            'stats' => [
                'total_packed_today' => $totalPackedToday,
                'controlled_drugs_out' => $controlledDrugsOut,
                'awaiting_return' => $awaitingReturn,
            ],
        ]);
    }

    public function packMedication(Request $request, FleetResidentTransport $transport)
    {
        $this->assertCanManageMedicationTransit($request);
        $transport = $this->journeyScope->transportFor($request->user(), (int) $transport->id);

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'medication_id' => ['required', 'integer'],
            'medication_order_version_id' => ['nullable', 'integer'],
            'medication_name' => ['required', 'string', 'max:255'],
            'is_controlled_drug' => ['required', 'boolean'],
            'attestation_state' => ['nullable', 'string', 'in:accepted,refused,unavailable'],
            'witnessed_by_user_id' => ['nullable', 'integer'],
            'witness_credential' => ['nullable', 'string', 'max:255'],
            'attestation_reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'scan_code' => ['nullable', 'string', 'max:255'],
            'scan_source' => ['nullable', 'string', 'in:manual,scanner'],
            'scan_verified' => ['nullable', 'boolean'],
            'scan_match_source' => ['nullable', 'string', 'max:50'],
            'client_request_uuid' => ['nullable', 'uuid'],
            'captured_offline_at' => ['nullable', 'date'],
            'origin_device_id' => ['nullable', 'string', 'max:255'],
            'queued_offline' => ['nullable', 'boolean'],
        ]);

        $result = $this->journeys->packMedication(
            $request->user(),
            (int) $transport->id,
            $data,
        );
        $log = $result['log'];

        $payload = [
            'success' => true,
            'attestation_state' => $result['attestation_state'],
            'log' => $log ? [
                'id' => $log->id,
                'transport_id' => $log->transport_id,
                'client_id' => $log->client_id,
                'medication_id' => $log->medication_id,
                'medication_name' => $log->medication_name,
                'status' => $log->status,
                'packed_at' => $log->packed_at?->toIso8601String(),
                'packing_attestation_event_id' => $log->packing_attestation_event_id,
            ] : null,
        ];

        if (filled($data['client_request_uuid'] ?? null)) {
            $payload['sync'] = $this->syncPayload($data, $result['replayed']);

            return response()->json($payload);
        }

        return back()->with('success', match ($result['attestation_state']) {
            'refused' => 'Second-checker refusal recorded. Medication was not packed.',
            'unavailable' => 'Second-checker unavailability recorded. Medication was not packed.',
            default => 'Medication packed for transit.',
        });
    }

    public function correctPackingAttestation(Request $request, FleetMedicationTransitLog $log)
    {
        $this->assertCanManageMedicationTransit($request);
        $log = $this->journeyScope->medicationTransitLogFor($request->user(), (int) $log->id);
        $data = $request->validate([
            'witnessed_by_user_id' => ['required', 'integer'],
            'witness_credential' => ['required', 'string', 'max:255'],
            'correction_reason' => ['required', 'string', 'max:1000'],
            'client_request_uuid' => ['nullable', 'uuid'],
        ]);

        $result = $this->journeys->correctPackingAttestation(
            $request->user(),
            (int) $log->id,
            $data,
        );
        $log = $result['log'];
        $payload = [
            'success' => true,
            'log' => [
                'id' => $log->id,
                'packing_attestation_event_id' => $log->packing_attestation_event_id,
                'packed_witnessed_by_user_id' => $log->packed_witnessed_by_user_id,
                'packed_witnessed_at' => $log->packed_witnessed_at?->toIso8601String(),
            ],
        ];

        if (filled($data['client_request_uuid'] ?? null)) {
            $payload['sync'] = $this->syncPayload($data, $result['replayed']);

            return response()->json($payload);
        }

        return back()->with('success', $result['replayed']
            ? 'The packing witness correction was already recorded.'
            : 'Packing witness correction recorded.');
    }

    public function administerMedication(Request $request, FleetMedicationTransitLog $log)
    {
        $this->assertCanManageMedicationTransit($request);
        $log = $this->journeyScope->medicationTransitLogFor($request->user(), (int) $log->id);

        $rules = [
            'witnessed_by_user_id' => ['nullable', 'integer'],
            'witness_credential' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'scan_code' => ['nullable', 'string', 'max:255'],
            'scan_source' => ['nullable', 'string', 'in:manual,scanner'],
            'scan_verified' => ['nullable', 'boolean'],
            'scan_match_source' => ['nullable', 'string', 'max:50'],
            'client_request_uuid' => ['nullable', 'uuid'],
            'captured_offline_at' => ['nullable', 'date'],
            'origin_device_id' => ['nullable', 'string', 'max:255'],
            'queued_offline' => ['nullable', 'boolean'],
        ];

        $data = $request->validate($rules);

        $result = $this->journeys->administerMedication(
            $request->user(),
            (int) $log->id,
            $data,
        );
        $log = $result['log'];

        $payload = [
            'success' => true,
            'log' => [
                'id' => $log->id,
                'status' => $log->status,
                'administered_at' => $log->administered_at?->toIso8601String(),
                'returned_to_house_at' => $log->returned_to_house_at?->toIso8601String(),
                'witnessed_by_user_id' => $log->witnessed_by_user_id,
            ],
        ];

        if (filled($data['client_request_uuid'] ?? null)) {
            $payload['sync'] = $this->syncPayload($data, $result['replayed']);

            return response()->json($payload);
        }

        return back()->with('success', 'Medication administration recorded.');
    }

    public function returnMedication(Request $request, FleetMedicationTransitLog $log)
    {
        $this->assertCanManageMedicationTransit($request);
        $log = $this->journeyScope->medicationTransitLogFor($request->user(), (int) $log->id);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'scan_code' => ['nullable', 'string', 'max:255'],
            'scan_source' => ['nullable', 'string', 'in:manual,scanner'],
            'scan_verified' => ['nullable', 'boolean'],
            'scan_match_source' => ['nullable', 'string', 'max:50'],
            'client_request_uuid' => ['nullable', 'uuid'],
            'captured_offline_at' => ['nullable', 'date'],
            'origin_device_id' => ['nullable', 'string', 'max:255'],
            'queued_offline' => ['nullable', 'boolean'],
        ]);

        $result = $this->journeys->returnMedication(
            $request->user(),
            (int) $log->id,
            $data,
        );
        $log = $result['log'];

        $payload = [
            'success' => true,
            'log' => [
                'id' => $log->id,
                'status' => $log->status,
                'administered_at' => $log->administered_at?->toIso8601String(),
                'returned_to_house_at' => $log->returned_to_house_at?->toIso8601String(),
            ],
        ];

        if (filled($data['client_request_uuid'] ?? null)) {
            $payload['sync'] = $this->syncPayload($data, $result['replayed']);

            return response()->json($payload);
        }

        return back()->with('success', 'Medication returned to house.');
    }

    private function syncPayload(array $data, bool $replayed): array
    {
        return array_filter([
            'status' => $replayed ? 'duplicate' : (($data['queued_offline'] ?? false) ? 'synced' : 'processed'),
            'duplicate' => $replayed,
            'queued_offline' => (bool) ($data['queued_offline'] ?? false),
            'client_request_uuid' => $data['client_request_uuid'] ?? null,
            'captured_offline_at' => $data['captured_offline_at'] ?? null,
            'origin_device_id' => $data['origin_device_id'] ?? null,
            'message' => $replayed ? 'This medication request was already processed.' : null,
        ], fn ($value) => $value !== null);
    }

    /* ------------------------------------------------------------------
     *  Pre-Transport Safety Check
     * ------------------------------------------------------------------ */

    public function preCheck(Request $request, FleetResidentTransport $transport)
    {
        $transport = $this->journeyScope->transportFor($request->user(), (int) $transport->id);
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

        $client = $transport->resident_id
            ? $this->journeyScope->clientFor($request->user(), (int) $transport->resident_id)
            : null;

        if ($client) {
            // Care needs from support plan
            if (
                $this->journeyScope->canViewResidentCareContext($request->user())
                && Schema::hasTable('client_support_plan_items')
            ) {
                $careNeeds = DB::table('client_support_plan_items')
                    ->where('client_id', $client->id)
                    ->whereIn('category', ['transport', 'mobility'])
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
            if (
                $this->journeyScope->canViewResidentCareContext($request->user())
                && Schema::hasTable('client_emergency_contacts')
            ) {
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
            if (
                $this->journeyScope->canViewMedicationTransit($request->user())
                && Schema::hasTable('client_medications')
            ) {
                $medications = ClientMedication::query()
                    ->active()
                    ->where('client_id', $client->id)
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
        $transport = $this->journeyScope->mutableTransportFor($request->user(), (int) $transport->id);
        $data = $request->validate([
            'checks' => ['required', 'array'],
            'checks.*' => ['nullable', 'boolean'],
            'client_request_uuid' => ['nullable', 'uuid'],
        ]);

        $result = $this->journeys->savePreCheck(
            $request->user(),
            (int) $transport->id,
            $data,
        );

        return redirect()->route('fleet-assets.transports.show', $result['transport'])
            ->with('success', $result['replayed'] ? 'Pre-transport safety check was already completed.' : 'Pre-transport safety check completed.');
    }
}
