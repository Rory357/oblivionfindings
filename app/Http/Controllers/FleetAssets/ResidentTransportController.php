<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Concerns\HandlesMedicationSync;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\FleetMedicationTransitLog;
use App\Models\FleetResidentTransport;
use App\Models\Shift;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CoverageRoleService;
use App\Services\MedicationIncidentIntegrationService;
use App\Services\MedicationScanVerificationService;
use App\Services\ShiftOperationalSnapshotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ResidentTransportController extends Controller
{
    use HandlesMedicationSync;

    public function __construct(
        protected CoverageRoleService $coverageRoles,
        protected ShiftOperationalSnapshotService $snapshots,
        protected MedicationScanVerificationService $scanVerificationService,
    ) {
    }

    private function canManageMedicationTransit(?User $user): bool
    {
        return (bool) $user && (
            $user->canDo('fleet.medication.manage')
            || $user->canDo('medications.administer.record')
            || $user->canDo('medications.stock.update')
            || $user->canDo('clients.update')
        );
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

    private function resolveTransitMedication(Client $client, ?int $medicationId): ?ClientMedication
    {
        if (! $medicationId) {
            return null;
        }

        return ClientMedication::query()
            ->where('client_id', $client->id)
            ->find($medicationId);
    }

    private function verifyMedicationScanOrFail(
        Client $client,
        ClientMedication $medication,
        array $payload,
        string $errorKey = 'scan_code'
    ): array {
        if (! ($payload['scan_verified'] ?? false) || blank($payload['scan_code'] ?? null)) {
            throw ValidationException::withMessages([
                $errorKey => 'Verify the medication code before continuing.',
            ]);
        }

        $result = $this->scanVerificationService->verify(
            $client,
            $medication,
            (string) $payload['scan_code']
        );

        if (! $result['matched']) {
            throw ValidationException::withMessages([
                $errorKey => $result['message'],
            ]);
        }

        if (
            filled($payload['scan_match_source'] ?? null)
            && ($payload['scan_match_source'] ?? null) !== $result['match_source']
        ) {
            throw ValidationException::withMessages([
                $errorKey => 'The medication verification needs to be repeated.',
            ]);
        }

        return [
            'scan_source' => $payload['scan_source'] ?? 'manual',
            'scan_match_source' => $result['match_source'],
            'scan_match_label' => $result['match_label'],
            'scan_code_suffix' => substr(
                $this->scanVerificationService->normalize((string) $payload['scan_code']),
                -6
            ),
        ];
    }

    private function createTransitMedicationLog(
        FleetResidentTransport $transport,
        Client $client,
        Request $request,
        array $payload,
        ?ClientMedication $medication = null,
        ?array $scanAudit = null
    ): FleetMedicationTransitLog {
        $isControlledDrug = (bool) ($medication?->controlled_drug ?? $payload['is_controlled_drug'] ?? false);

        $attributes = [
            'transport_id' => $transport->id,
            'client_id' => $client->id,
            'medication_id' => $medication?->id ?? ($payload['medication_id'] ?? null),
            'medication_name' => $medication
                ? trim($medication->name . ($medication->dosage ? ' ' . $medication->dosage : ''))
                : $payload['medication_name'],
            'is_controlled_drug' => $isControlledDrug,
            'packed_by_user_id' => $request->user()->id,
            'packed_at' => now(),
            'notes' => $payload['notes'] ?? null,
        ];

        if ($this->supportsPackedWitnessName()) {
            $attributes['packed_witness_name'] = filled($payload['witness_name'] ?? null)
                ? trim((string) $payload['witness_name'])
                : null;
        }

        $log = FleetMedicationTransitLog::create($attributes);

        AuditLogger::log('fleet.medication.pack', $log, array_filter([
            'transport_id' => $transport->id,
            'client_id' => $client->id,
            'client_medication_id' => $medication?->id,
            'medication_name' => $log->medication_name,
            'controlled_drug' => $isControlledDrug,
            'packed_witness_name' => $log->packed_witness_name,
            'scan_source' => $scanAudit['scan_source'] ?? null,
            'scan_match_source' => $scanAudit['scan_match_source'] ?? null,
            'scan_match_label' => $scanAudit['scan_match_label'] ?? null,
            'entered_code_suffix' => $scanAudit['scan_code_suffix'] ?? null,
        ], fn ($value) => $value !== null && $value !== ''));

        if ($isControlledDrug) {
            app(MedicationIncidentIntegrationService::class)->handleTransitException($log);
        }

        return $log;
    }

    private function supportsPackedWitnessName(): bool
    {
        static $supportsPackedWitnessName = null;

        if ($supportsPackedWitnessName === null) {
            $supportsPackedWitnessName = Schema::hasTable('fleet_medication_transit_logs')
                && Schema::hasColumn('fleet_medication_transit_logs', 'packed_witness_name');
        }

        return $supportsPackedWitnessName;
    }

    public function index(Request $request)
    {
        $formOptions = $this->formOptions($request);

        if (!Schema::hasTable('fleet_resident_transports')) {
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
                $this->putCsv($handle, ['ID', 'Vehicle', 'Driver', 'Resident', 'Type', 'Pickup', 'Dropoff', 'Departed', 'Arrived', 'Duration (min)', 'Passengers', 'Supervisor', 'Status', 'Notes']);
                foreach ($all as $t) {
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
            'vehicles' => $formOptions['vehicles'],
            'stats' => [
                'total_this_month' => $totalThisMonth,
                'residents_this_month' => $residentsThisMonth,
                'avg_duration_minutes' => round((float) ($avgDuration ?? 0), 1),
                'most_active_vehicle' => $mostActiveVehicleName,
            ],
            'hero' => [
                'today' => FleetResidentTransport::query()->whereDate('departed_at', today())->count(),
                'in_progress' => FleetResidentTransport::query()->where('status', 'in_progress')->count(),
                'completed_7d' => FleetResidentTransport::query()
                    ->where('status', 'completed')
                    ->where('departed_at', '>=', now()->subDays(7))
                    ->count(),
                'with_medications_7d' => Schema::hasTable('fleet_medication_transit_logs')
                    ? FleetMedicationTransitLog::query()
                        ->where('created_at', '>=', now()->subDays(7))
                        ->distinct('transport_id')
                        ->count('transport_id')
                    : 0,
            ],
            ...$formOptions,
        ]);
    }

    private function formOptions(Request $request): array
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

        $recentResidents = Schema::hasTable('fleet_resident_transports')
            ? FleetResidentTransport::query()
                ->select('resident_name')
                ->distinct()
                ->orderBy('resident_name')
                ->limit(100)
                ->pluck('resident_name')
            : collect();

        $clients = Schema::hasTable('clients')
            ? Client::query()
                ->orderBy('first_name')
                ->limit(200)
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn ($client) => [
                    'id' => $client->id,
                    'name' => trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')),
                ])->values()
            : collect();

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
                'client_name' => $shift->client
                    ? trim(($shift->client->first_name ?? '') . ' ' . ($shift->client->last_name ?? ''))
                    : null,
                'staff_name' => $shift->staff?->name,
                'starts_at' => optional($shift->starts_at)->toISOString(),
                'ends_at' => optional($shift->ends_at)->toISOString(),
                'status' => $shift->status,
                'shift_type' => $shift->shift_type ?? 'standard',
                'location' => $shift->location,
                'service_context' => $shift->serviceContext?->name,
            ])->values();

        $clientId = $selectedShift?->client_id
            ?: ($request->filled('client_id') ? (int) $request->input('client_id') : null);
        $scanClient = $selectedShift?->client;
        if (! $scanClient && $clientId) {
            $scanClient = Client::query()->find($clientId, ['id']);
        }

        $clientMedications = collect();
        if ($clientId && Schema::hasTable('client_medications')) {
            $clientMedications = ClientMedication::query()
                ->where('client_id', $clientId)
                ->where('active', true)
                ->whereNull('ceased_at')
                ->where(fn ($query) => $query
                    ->where('is_prn', true)
                    ->orWhereNotNull('dose_times'))
                ->get([
                    'id', 'name', 'dosage', 'frequency', 'is_prn',
                    'controlled_drug', 'dose_times', 'route', 'instructions',
                    'barcode', 'nzulm_code',
                ])
                ->map(fn ($medication) => [
                    'id' => $medication->id,
                    'name' => $medication->name,
                    'dosage' => $medication->dosage,
                    'frequency' => $medication->frequency,
                    'is_prn' => (bool) $medication->is_prn,
                    'controlled_drug' => (bool) $medication->controlled_drug,
                    'dose_times' => $medication->dose_times,
                    'route' => $medication->route,
                    'instructions' => $medication->instructions,
                    'scan_verification' => $scanClient
                        ? $this->buildMedicationScanPayload($scanClient, $medication)
                        : null,
                ]);
        }

        return [
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
            'medications.*.medication_id' => ['required', 'integer', 'exists:client_medications,id'],
            'medications.*.medication_name' => ['required', 'string', 'max:255'],
            'medications.*.is_controlled_drug' => ['required', 'boolean'],
            'medications.*.witness_name' => ['nullable', 'string', 'max:255'],
            'medications.*.scan_code' => ['nullable', 'string', 'max:255'],
            'medications.*.scan_source' => ['nullable', 'string', 'in:manual,scanner'],
            'medications.*.scan_verified' => ['nullable', 'boolean'],
            'medications.*.scan_match_source' => ['nullable', 'string', 'max:50'],
        ]);

        if (! empty($data['medications'])) {
            $this->assertCanManageMedicationTransit($request);
        }

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

        $medicationClient = null;
        $preparedTransitMedications = [];

        if (! empty($data['medications'])) {
            if (empty($data['client_id'])) {
                throw ValidationException::withMessages([
                    'medications' => 'Select a resident before packing medications for transport.',
                ]);
            }

            $medicationClient = Client::query()->findOrFail((int) $data['client_id']);
            foreach ($data['medications'] as $med) {
                $medication = $this->resolveTransitMedication(
                    $medicationClient,
                    isset($med['medication_id']) ? (int) $med['medication_id'] : null,
                );

                if (($med['medication_id'] ?? null) && ! $medication) {
                    throw ValidationException::withMessages([
                        'medications' => 'One or more selected medications do not belong to this resident.',
                    ]);
                }

                $isControlledDrug = (bool) ($medication?->controlled_drug ?? $med['is_controlled_drug']);

                if ($isControlledDrug && blank($med['witness_name'] ?? null)) {
                    throw ValidationException::withMessages([
                        'medications' => 'Controlled drugs require a packing witness name before transport can be logged.',
                    ]);
                }

                $preparedTransitMedications[] = [
                    'payload' => $med,
                    'medication' => $medication,
                    'scan_audit' => $medication
                        ? $this->verifyMedicationScanOrFail($medicationClient, $medication, $med, 'medications')
                        : null,
                ];
            }
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
        $transport = DB::transaction(function () use (
            $data,
            $shift,
            $request,
            $clientSnapshot,
            $preparedTransitMedications,
            $medicationClient
        ) {
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

            if ($medicationClient && Schema::hasTable('fleet_medication_transit_logs')) {
                foreach ($preparedTransitMedications as $preparedMedication) {
                    $this->createTransitMedicationLog(
                        $transport,
                        $medicationClient,
                        $request,
                        $preparedMedication['payload'],
                        $preparedMedication['medication'],
                        $preparedMedication['scan_audit'],
                    );
                }
            }

            return $transport;
        });

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

        // Vehicle position for live map during active transport.
        // Reads from canonical device linked to the transport vehicle via device_asset_links.
        $vehiclePosition = null;
        if ($transport->status === 'in_progress' && $transport->asset_id) {
            $vehicleDevice = \App\Domain\SecurityDevices\Models\DeviceAssetLink::query()
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

        $canManageMedicationTransit = $this->canManageMedicationTransit($request->user());
        $transportClient = $transport->resident_id
            ? Client::query()->find($transport->resident_id, ['id', 'first_name', 'last_name'])
            : null;

        $availableMedications = [];
        if ($transportClient && Schema::hasTable('client_medications')) {
            $availableMedications = ClientMedication::query()
                ->where('client_id', $transportClient->id)
                ->where('active', true)
                ->whereNull('ceased_at')
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
                    'dose_times' => $medication->dose_times,
                    'route' => $medication->route,
                    'instructions' => $medication->instructions,
                    'scan_verification' => $this->buildMedicationScanPayload($transportClient, $medication),
                ])
                ->values()
                ->toArray();
        }

        $transitLogs = [];
        if (Schema::hasTable('fleet_medication_transit_logs')) {
            $transitLogs = FleetMedicationTransitLog::query()
                ->with([
                    'client:id,first_name,last_name',
                    'medication:id,client_id,name,dosage,barcode,nzulm_code',
                    'packedBy:id,name',
                    'administeredBy:id,name',
                    'witnessedBy:id,name',
                ])
                ->where('transport_id', $transport->id)
                ->orderByDesc('packed_at')
                ->get()
                ->map(fn ($log) => [
                    'id' => $log->id,
                    'client' => $log->client ? [
                        'id' => $log->client->id,
                        'name' => trim(($log->client->first_name ?? '') . ' ' . ($log->client->last_name ?? '')),
                    ] : null,
                    'medication_id' => $log->medication_id,
                    'medication_name' => $log->medication_name,
                    'is_controlled_drug' => $log->is_controlled_drug,
                    'packed_witness_name' => $log->packed_witness_name,
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
        }

        $witnesses = $canManageMedicationTransit
            ? User::query()
                ->orderBy('name')
                ->get(['id', 'name'])
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
            'medication_context' => [
                'client' => $transportClient ? [
                    'id' => $transportClient->id,
                    'name' => trim(($transportClient->first_name ?? '') . ' ' . ($transportClient->last_name ?? '')),
                ] : null,
                'available_medications' => $availableMedications,
                'transit_logs' => $transitLogs,
                'witnesses' => $witnesses,
                'can_manage' => $canManageMedicationTransit,
            ],
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
        $selectedTransport = null;

        if ($request->filled('transport_id') && Schema::hasTable('fleet_resident_transports')) {
            $selectedTransport = FleetResidentTransport::query()
                ->with(['asset:id,name,asset_tag'])
                ->find((int) $request->input('transport_id'));
        }

        if (!Schema::hasTable('fleet_medication_transit_logs')) {
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
                'administeredBy:id,name',
                'witnessedBy:id,name',
                'medication',
            ]);

        if ($request->filled('transport_id')) {
            $query->where('transport_id', (int) $request->input('transport_id'));
        }

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
                $this->putCsv($handle, ['ID', 'Resident', 'Medication', 'Controlled Drug', 'Packed By', 'Packing Witness', 'Packed At', 'Administered By', 'Administered At', 'Witnessed By', 'Returned At', 'Notes']);
                foreach ($all as $log) {
                    $this->putCsv($handle, [
                        $log->id,
                        trim(($log->client?->first_name ?? '') . ' ' . ($log->client?->last_name ?? '')),
                        $log->medication_name,
                        $log->is_controlled_drug ? 'Yes' : 'No',
                        $log->packedBy?->name ?? '',
                        $log->packed_witness_name ?? '',
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
                        'name' => trim(($log->client->first_name ?? '') . ' ' . ($log->client->last_name ?? '')),
                    ] : null,
                    'medication_id' => $log->medication_id,
                    'medication_name' => $log->medication_name,
                    'is_controlled_drug' => $log->is_controlled_drug,
                    'packed_witness_name' => $log->packed_witness_name,
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
            'witnesses' => User::query()->orderBy('name')->get(['id', 'name'])->map(fn ($user) => [
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

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'medication_id' => ['nullable', 'integer', 'exists:client_medications,id'],
            'medication_name' => ['required', 'string', 'max:255'],
            'is_controlled_drug' => ['required', 'boolean'],
            'witness_name' => ['nullable', 'string', 'max:255'],
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

        $scope = "fleet-medication-pack:{$transport->id}";

        if (
            $this->medicationSyncRequested($data)
            && ($cached = $this->getCachedMedicationSyncResponse($scope, $data))
        ) {
            return response()->json($cached);
        }

        $client = Client::query()->findOrFail((int) $data['client_id']);

        if ($transport->resident_id && (int) $transport->resident_id !== (int) $client->id) {
            throw ValidationException::withMessages([
                'client_id' => 'Only medications for the resident assigned to this transport can be packed here.',
            ]);
        }

        $medication = $this->resolveTransitMedication(
            $client,
            isset($data['medication_id']) ? (int) $data['medication_id'] : null,
        );

        if (($data['medication_id'] ?? null) && ! $medication) {
            throw ValidationException::withMessages([
                'medication_id' => 'The selected medication does not belong to this resident.',
            ]);
        }

        $isControlledDrug = (bool) ($medication?->controlled_drug ?? $data['is_controlled_drug']);

        if ($isControlledDrug && blank($data['witness_name'] ?? null)) {
            throw ValidationException::withMessages([
                'witness_name' => 'Controlled drugs require a packing witness name.',
            ]);
        }

        $scanAudit = $medication
            ? $this->verifyMedicationScanOrFail($client, $medication, $data)
            : null;

        $log = $this->createTransitMedicationLog(
            $transport,
            $client,
            $request,
            $data,
            $medication,
            $scanAudit,
        );

        $payload = [
            'success' => true,
            'log' => [
                'id' => $log->id,
                'transport_id' => $log->transport_id,
                'client_id' => $log->client_id,
                'medication_id' => $log->medication_id,
                'medication_name' => $log->medication_name,
                'status' => $log->status,
                'packed_at' => $log->packed_at?->toIso8601String(),
            ],
        ];

        if ($this->medicationSyncRequested($data)) {
            return response()->json(
                $this->rememberMedicationSyncResponse(
                    $scope,
                    $data,
                    $this->withMedicationSync(
                        $payload,
                        $data,
                        $this->medicationProcessedStatus($data),
                    ),
                ),
            );
        }

        return back()->with('success', 'Medication packed for transit.');
    }

    public function administerMedication(Request $request, FleetMedicationTransitLog $log)
    {
        $this->assertCanManageMedicationTransit($request);

        $rules = [
            'witnessed_by_user_id' => ['nullable', 'integer', 'exists:users,id'],
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

        // NZ regulation: controlled drugs require a witness
        if ($log->is_controlled_drug) {
            $rules['witnessed_by_user_id'] = ['required', 'integer', 'exists:users,id'];
        }

        $data = $request->validate($rules);

        $scope = "fleet-medication-administer:{$log->id}";

        if (
            $this->medicationSyncRequested($data)
            && ($cached = $this->getCachedMedicationSyncResponse($scope, $data))
        ) {
            return response()->json($cached);
        }

        if ($log->returned_to_house_at) {
            $message = 'This medication was already returned before the current administration request could be applied.';

            if ($this->medicationSyncRequested($data)) {
                return response()->json(
                    $this->buildMedicationConflictPayload($data, $message),
                    409,
                );
            }

            return back()->with('error', $message);
        }

        if ($log->administered_at) {
            $message = 'This medication administration was already recorded before the current request could be applied.';

            if ($this->medicationSyncRequested($data)) {
                return response()->json(
                    $this->buildMedicationConflictPayload($data, $message),
                    409,
                );
            }

            return back()->with('error', $message);
        }

        if (
            $log->is_controlled_drug
            && isset($data['witnessed_by_user_id'])
            && (int) $data['witnessed_by_user_id'] === (int) $request->user()->id
        ) {
            throw ValidationException::withMessages([
                'witnessed_by_user_id' => 'Witness must be a different user.',
            ]);
        }

        $log->loadMissing(['client', 'medication']);
        $scanAudit = ($log->client && $log->medication)
            ? $this->verifyMedicationScanOrFail($log->client, $log->medication, $data)
            : null;

        $log->update([
            'administered_at' => now(),
            'administered_by_user_id' => $request->user()->id,
            'witnessed_by_user_id' => $data['witnessed_by_user_id'] ?? null,
            'notes' => $data['notes'] ?? $log->notes,
        ]);

        AuditLogger::log('fleet.medication.administer', $log, [
            'medication_name' => $log->medication_name,
            'controlled_drug' => $log->is_controlled_drug,
            'scan_source' => $scanAudit['scan_source'] ?? null,
            'scan_match_source' => $scanAudit['scan_match_source'] ?? null,
            'scan_match_label' => $scanAudit['scan_match_label'] ?? null,
            'entered_code_suffix' => $scanAudit['scan_code_suffix'] ?? null,
        ]);

        app(MedicationIncidentIntegrationService::class)->resolveTransitException(
            $log,
            'Medication administered during transit.',
            $request->user()->id
        );

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

        if ($this->medicationSyncRequested($data)) {
            return response()->json(
                $this->rememberMedicationSyncResponse(
                    $scope,
                    $data,
                    $this->withMedicationSync(
                        $payload,
                        $data,
                        $this->medicationProcessedStatus($data),
                    ),
                ),
            );
        }

        return back()->with('success', 'Medication administration recorded.');
    }

    public function returnMedication(Request $request, FleetMedicationTransitLog $log)
    {
        $this->assertCanManageMedicationTransit($request);

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

        $scope = "fleet-medication-return:{$log->id}";

        if (
            $this->medicationSyncRequested($data)
            && ($cached = $this->getCachedMedicationSyncResponse($scope, $data))
        ) {
            return response()->json($cached);
        }

        if ($log->returned_to_house_at) {
            $message = 'This medication return was already recorded before the current request could be applied.';

            if ($this->medicationSyncRequested($data)) {
                return response()->json(
                    $this->buildMedicationConflictPayload($data, $message),
                    409,
                );
            }

            return back()->with('error', $message);
        }

        $log->loadMissing(['client', 'medication']);
        $scanAudit = ($log->client && $log->medication)
            ? $this->verifyMedicationScanOrFail($log->client, $log->medication, $data)
            : null;

        $log->update([
            'returned_to_house_at' => now(),
            'notes' => $data['notes'] ?? $log->notes,
        ]);

        AuditLogger::log('fleet.medication.return', $log, [
            'medication_name' => $log->medication_name,
            'scan_source' => $scanAudit['scan_source'] ?? null,
            'scan_match_source' => $scanAudit['scan_match_source'] ?? null,
            'scan_match_label' => $scanAudit['scan_match_label'] ?? null,
            'entered_code_suffix' => $scanAudit['scan_code_suffix'] ?? null,
        ]);

        app(MedicationIncidentIntegrationService::class)->resolveTransitException(
            $log,
            'Medication returned from transit.',
            $request->user()->id
        );

        $payload = [
            'success' => true,
            'log' => [
                'id' => $log->id,
                'status' => $log->status,
                'administered_at' => $log->administered_at?->toIso8601String(),
                'returned_to_house_at' => $log->returned_to_house_at?->toIso8601String(),
            ],
        ];

        if ($this->medicationSyncRequested($data)) {
            return response()->json(
                $this->rememberMedicationSyncResponse(
                    $scope,
                    $data,
                    $this->withMedicationSync(
                        $payload,
                        $data,
                        $this->medicationProcessedStatus($data),
                    ),
                ),
            );
        }

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
