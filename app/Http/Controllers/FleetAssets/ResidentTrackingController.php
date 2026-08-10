<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Domain\SecurityDevices\Services\PersonalTrackingLocationExportService;
use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\AssetGeofence;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ControlRoomAlert;
use App\Models\FleetOuting;
use App\Models\FleetTelemetryEvent;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\User;
use App\Services\ConsentValidationService;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\Integration\IntegrationEventHistoryService;
use App\Services\Queclink\LocateNowService;
use App\Services\Tracking\GeofenceStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ResidentTrackingController extends Controller
{
    public function __construct(
        private readonly DeviceRegistryService $registry,
        private readonly DeviceAssignmentService $assignmentService,
        private readonly GeofenceStatusService $geofenceStatus,
        private readonly SecurityDevicesAccessService $deviceAccess,
        private readonly PersonalTrackingPrivacyService $trackingPrivacy,
        private readonly PersonalTrackingLocationExportService $locationExport,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $authorizedClientIds = $this->getAuthorizedClientIds($user);

        // Get tracking devices actively assigned to clients (canonical).
        $clientDevices = $this->deviceAccess->visibleDevices($user)
            ->where('domain', 'tracking')
            ->whereHas('assignments', function ($q) use ($authorizedClientIds) {
                $q->active()
                    ->collectionActive()
                    ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
                    ->whereIn('assignable_id', $authorizedClientIds);
            })
            ->with(['assignments' => fn ($q) => $q
                ->active()
                ->collectionActive()
                ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
                ->whereIn('assignable_id', $authorizedClientIds)
                ->with('consent.consentType')])
            ->get();

        // Site-authoritative geofences. Asset provenance is only a fallback
        // when the geofence itself has no site_id.
        $geofences = collect();
        try {
            if (Schema::hasTable('asset_geofences')) {
                $geofences = $this->applyGeofenceScope(
                    AssetGeofence::query()->where('is_active', true),
                    $user,
                )->get();
            }
        } catch (\Throwable) {
            $geofences = collect();
        }

        // Load client data. The house geofence relation is constrained to the
        // same site-safe set used by the map, so nested payloads cannot leak a
        // contradictory foreign fence.
        $clientIds = $clientDevices->map(fn ($d) => $d->assignments->first()?->assignable_id)->filter()->unique()->values();
        $allowedGeofenceIds = $geofences->pluck('id')->all();
        $clients = Client::with([
            'site' => fn ($query) => $query->select(['id', 'name']),
            'houseGeofence' => fn ($query) => $query->whereIn('asset_geofences.id', $allowedGeofenceIds),
        ])
            ->whereIn('id', $clientIds)
            ->get()
            ->keyBy('id');
        $clientDevices = $clientDevices
            ->filter(function (Device $device) use ($clients): bool {
                $assignment = $device->assignments->first();
                $client = $assignment
                    ? $clients->get($assignment->assignable_id)
                    : null;

                return $assignment !== null
                    && $client !== null
                    && $this->trackingPrivacy->assignmentAuthorisesClient($assignment, $client);
            });
        $consentedClientIds = $clientDevices
            ->map(fn (Device $device): int => (int) $device->assignments->first()->assignable_id)
            ->unique()
            ->values()
            ->all();

        // Load active outings.
        $activeOutingClientIds = [];
        try {
            if (Schema::hasTable('fleet_outings') && Schema::hasTable('fleet_outing_residents')) {
                $activeOutingIds = $this->applyOutingScope(
                    FleetOuting::query()->where('status', 'active'),
                    $user,
                    $authorizedClientIds,
                )->pluck('id');
                $activeOutingClientIds = DB::table('fleet_outing_residents')
                    ->whereIn('outing_id', $activeOutingIds)
                    ->whereIn('client_id', $authorizedClientIds)
                    ->pluck('fleet_outing_residents.client_id')
                    ->toArray();
            }
        } catch (\Throwable) {
            $activeOutingClientIds = [];
        }

        // Build resident tracking data.
        $residents = $clientDevices->map(function (Device $device) use ($clients, $activeOutingClientIds) {
            $assignment = $device->assignments->first();
            if (! $assignment) {
                return null;
            }

            $client = $clients->get($assignment->assignable_id);
            if (! $client) {
                return null;
            }

            return $this->buildResidentPayload($device, $client, $activeOutingClientIds);
        })->filter()->values();

        // Stats.
        $totalTracked = $residents->count();
        $online = $residents->where('status', 'online')->count();
        $offline = $residents->where('status', 'offline')->count();
        $inGeofence = $residents->where('geofence_status', 'in_zone')->count();
        $outsideGeofence = $residents->where('geofence_status', 'outside_zone')->count();
        $lowBattery = $residents->filter(fn ($r) => ($r['battery_status'] ?? null) === 'low')->count();
        $safetyScore = $totalTracked > 0 ? round(($inGeofence / max($totalTracked, 1)) * 100, 1) : 0;
        $avgBattery = $totalTracked > 0 ? round($residents->avg(fn ($r) => $r['battery'] ?? 0), 0) : 0;

        $totalClientQuery = Client::query()
            ->where('status', 'active')
            ->whereIn('id', $authorizedClientIds);
        $totalClients = $totalClientQuery->count();
        $untracked = max(0, $totalClients - $totalTracked);

        // Recent alerts (unchanged).
        $recentAlerts = [];
        try {
            if (Schema::hasTable('control_room_alerts')) {
                $recentAlertQuery = ControlRoomAlert::whereIn('source', ['tracker', 'resident_tracker', 'geofence'])
                    ->actionable()
                    ->whereIn('client_id', $consentedClientIds);
                $recentAlerts = $recentAlertQuery->latest()->limit(5)->get()
                    ->map(function ($alert) {
                        $client = $alert->client_id ? Client::find($alert->client_id) : null;

                        return [
                            'id' => $alert->id,
                            'title' => $alert->alert_type ?? 'Alert',
                            'severity' => $alert->severity ?? 'medium',
                            'status' => $alert->status ?? 'open',
                            'created_at' => $alert->created_at?->toISOString(),
                            'resident_name' => $client ? trim(($client->first_name ?? '').' '.($client->last_name ?? '')) : null,
                        ];
                    })->toArray();
            }
        } catch (\Throwable) {
            $recentAlerts = [];
        }

        // Active outings (unchanged).
        $activeOutings = [];
        try {
            if (Schema::hasTable('fleet_outings')) {
                $activeOutings = $this->applyOutingScope(
                    FleetOuting::query()->where('status', 'active'),
                    $user,
                    $authorizedClientIds,
                )
                    ->with([
                        'asset' => fn ($query) => $query
                            ->whereIn('assets.id', $this->accessibleAssetIds($user))
                            ->select(['id', 'name']),
                    ])
                    ->withCount(['clients as authorized_resident_count' => fn ($query) => $query
                        ->whereIn('clients.id', $authorizedClientIds)])
                    ->latest()->limit(10)->get()
                    ->map(fn ($o) => [
                        'id' => $o->id, 'title' => $o->title ?? 'Outing',
                        'destination' => $o->destination ?? 'Unknown',
                        'resident_count' => (int) $o->authorized_resident_count,
                        'departed_at' => $o->actual_departure?->toISOString() ?? $o->planned_departure?->toISOString(),
                        'vehicle_name' => $o->asset?->name ?? null,
                    ])->toArray();
            }
        } catch (\Throwable) {
            $activeOutings = [];
        }

        // Geofences for map: every active fence (with applies_to scope).
        $mapGeofences = $this->buildMapGeofences($geofences);

        $focusClientId = $request->integer('focus') ?: null;
        if ($focusClientId && ! in_array($focusClientId, $consentedClientIds, true)) {
            $focusClientId = null;
        }

        // Hero alert stats (safety command centre band).
        $activeAlertCount = 0;
        $wandering7d = 0;
        $panic7d = 0;
        try {
            if (Schema::hasTable('control_room_alerts')) {
                $alertBase = ControlRoomAlert::query()
                    ->whereIn('source', ['tracker', 'geofence', 'resident_tracker'])
                    ->whereNotNull('client_id')
                    ->whereIn('client_id', $consentedClientIds);
                $activeAlertCount = (clone $alertBase)->actionable()->count();
                $wandering7d = (clone $alertBase)
                    ->whereIn('alert_type', ['geofence_breach', 'wandering'])
                    ->where('triggered_at', '>=', now()->subDays(7))
                    ->count();
                $panic7d = (clone $alertBase)
                    ->where(function ($q) {
                        $q->whereIn('alert_type', ['sos', 'panic', 'man_down'])
                            ->orWhere('alert_type', 'like', '%panic%')
                            ->orWhere('alert_type', 'like', '%sos%');
                    })
                    ->where('triggered_at', '>=', now()->subDays(7))
                    ->count();
            }
        } catch (\Throwable) {
            // hero counts stay zero when the alerts table is unavailable
        }

        $tab = $request->input('tab') === 'wandering' ? 'wandering' : 'tracking';

        return Inertia::render('fleet-assets/resident-tracking/index', [
            'tab' => $tab,
            'residents' => $residents,
            'stats' => [
                'tracked' => $totalTracked, 'online' => $online, 'offline' => $offline,
                'untracked' => $untracked,
                'online_percent' => $totalTracked > 0 ? round(($online / $totalTracked) * 100, 1) : 0,
                'in_geofence' => $inGeofence, 'outside_geofence' => $outsideGeofence,
                'low_battery' => $lowBattery, 'safety_score' => $safetyScore, 'avg_battery' => $avgBattery,
                'panic_active' => $residents->filter(fn ($r) => $r['panic_active'] ?? false)->count(),
                'active_alerts' => $activeAlertCount,
                'wandering_7d' => $wandering7d,
                'panic_7d' => $panic7d,
            ],
            'recent_alerts' => $recentAlerts,
            'active_outings' => $activeOutings,
            'geofences' => $mapGeofences,
            'focus_client_id' => $focusClientId,
            // Wandering-alerts tab payload (merged from the retired
            // /fleet-assets/wandering-alerts page) — only when the tab is open.
            'wandering' => $tab === 'wandering'
                ? $this->wanderingPayload($request, $consentedClientIds)
                : null,
            // Assign-tracker modal payload (retired /resident-tracking/assign
            // page) — only when opened via ?new=1.
            'assign' => $request->boolean('new') ? $this->assignPayload($user) : null,
            'can' => [
                'manage' => (bool) $user?->canDo('fleet.manage'),
                'manage_alerts' => (bool) ($user?->canDo('fleet.manage') || $user?->canDo('assets.alerts.manage')),
            ],
        ])->toResponse($request)->withHeaders($this->privateLocationHeaders());
    }

    /**
     * Wandering-alerts tab payload — ported from the retired
     * WanderingAlertController index.
     */
    private function wanderingPayload(Request $request, array $consentedClientIds): array
    {
        if (! Schema::hasTable('control_room_alerts')) {
            return [
                'alerts' => ['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]],
                'stats' => ['active_alerts' => 0, 'resolved_today' => 0, 'total_this_week' => 0],
                'filters' => $request->only(['status']),
            ];
        }

        $query = ControlRoomAlert::query()
            ->whereIn('source', ['tracker', 'geofence', 'resident_tracker'])
            ->whereNotNull('client_id');
        $query->whereIn('client_id', $consentedClientIds);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        } else {
            $query->actionable();
        }

        $alerts = $query->latest('triggered_at')
            ->paginate(25)
            ->withQueryString();

        // Load client data.
        $clientIds = $alerts->getCollection()->pluck('client_id')->unique()->filter();
        $clients = Client::query()
            ->whereIn('id', $clientIds)
            ->get()
            ->keyBy('id');

        // Canonical tracking devices assigned to these clients for last-known location.
        $devicesByClient = $this->deviceAccess->visibleDevices($request->user())
            ->where('domain', 'tracking')
            ->whereHas('assignments', function ($q) use ($clientIds) {
                $q->active()
                    ->where('assignable_type', 'client')
                    ->whereIn('assignable_id', $clientIds);
            })
            ->with(['assignments' => fn ($q) => $q->active()->where('assignable_type', 'client')])
            ->get()
            ->keyBy(fn (Device $d) => $d->assignments->first()?->assignable_id);

        $alertData = $alerts->getCollection()->map(function ($alert) use ($clients, $devicesByClient) {
            $client = $clients->get($alert->client_id);
            $device = $devicesByClient->get($alert->client_id);
            $meta = $device?->meta ?? [];
            $context = $alert->context ?? [];

            return [
                'id' => $alert->id,
                'alert_type' => $alert->alert_type,
                'severity' => $alert->severity,
                'status' => $alert->status,
                'triggered_at' => optional($alert->triggered_at)->toISOString(),
                'acknowledged_at' => optional($alert->acknowledged_at)->toISOString(),
                'resolved_at' => optional($alert->resolved_at)->toISOString(),
                'notes' => $alert->notes,
                'context' => $context,
                'client' => $client ? [
                    'id' => $client->id,
                    'name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
                    'photo' => $client->profile_photo_url,
                    'house' => $client->site?->name ?? 'Unknown',
                ] : null,
                'last_lat' => $device?->latitude ?? $meta['lat'] ?? $meta['latitude'] ?? $context['lat'] ?? null,
                'last_lng' => $device?->longitude ?? $meta['lng'] ?? $meta['longitude'] ?? $context['lng'] ?? null,
                'geofence_name' => $context['geofence_name'] ?? $context['zone_name'] ?? null,
            ];
        })->values();

        $alertBase = ControlRoomAlert::query()
            ->whereIn('source', ['tracker', 'geofence', 'resident_tracker'])
            ->whereNotNull('client_id');
        $alertBase->whereIn('client_id', $consentedClientIds);

        return [
            'alerts' => [
                'data' => $alertData,
                'links' => $alerts->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $alerts->currentPage(),
                    'last_page' => $alerts->lastPage(),
                    'total' => $alerts->total(),
                ],
            ],
            'stats' => [
                'active_alerts' => (clone $alertBase)->actionable()->count(),
                'resolved_today' => (clone $alertBase)->where('status', 'resolved')->whereDate('resolved_at', today())->count(),
                'total_this_week' => (clone $alertBase)->where('triggered_at', '>=', now()->startOfWeek())->count(),
            ],
            'filters' => $request->only(['status']),
        ];
    }

    /**
     * The standalone assign page is retired — deep links reopen the modal on
     * the tracking index via ?new=1.
     */
    public function assignPage(Request $request)
    {
        return redirect()->route(
            'fleet-assets.resident-tracking.index',
            array_merge($request->query(), ['new' => 1]),
        );
    }

    /**
     * Assign-tracker modal payload — ported from the retired assign page.
     */
    private function assignPayload(User $user): array
    {
        $authorizedClientIds = $this->getAuthorizedClientIds($user);

        // Clients already tracked (have an active tracking device assignment).
        $trackedClientIds = DeviceAssignment::query()
            ->active()
            ->where('assignable_type', 'client')
            ->whereIn('assignable_id', $authorizedClientIds)
            ->whereHas('device', fn ($q) => $q
                ->where('domain', 'tracking'))
            ->pluck('assignable_id')
            ->toArray();

        $clientQuery = Client::query()
            ->where('status', 'active')
            ->whereIn('id', $authorizedClientIds)
            ->orderBy('first_name');
        $availableClients = $clientQuery->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => trim(($c->first_name ?? '').' '.($c->last_name ?? '')),
            'house' => $c->site?->name ?? 'Unknown',
            'is_tracked' => in_array($c->id, $trackedClientIds),
        ]);

        // Available trackers (tracking devices not assigned to anyone).
        $availableTrackerQuery = $this->deviceAccess->visibleDevices($user)
            ->where('domain', 'tracking')
            ->whereNotIn('status', ['decommissioned', 'lost'])
            ->whereDoesntHave('assignments', fn ($q) => $q->active());
        $availableTrackers = $availableTrackerQuery
            ->orderBy('name')
            ->get()
            ->map(fn (Device $d) => [
                'id' => $d->id,
                'device_uid' => $d->device_uid,
                'name' => $d->name,
                'serial' => $d->serial_number,
                'mac' => $d->mac_address,
                'provider' => $d->provider,
                'status' => $d->status?->value,
                'battery' => $d->battery_level,
            ]);

        // Currently assigned trackers.
        $assignedDeviceQuery = $this->deviceAccess->visibleDevices($user)
            ->where('domain', 'tracking')
            ->whereHas('assignments', fn ($q) => $q
                ->active()
                ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
                ->whereIn('assignable_id', $authorizedClientIds));
        $assignedDeviceQuery->with(['assignments' => fn ($q) => $q
            ->active()
            ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
            ->whereIn('assignable_id', $authorizedClientIds)]);
        $assignedDevices = $assignedDeviceQuery
            ->orderBy('name')
            ->get();

        $assignedClientIds = $assignedDevices->map(fn ($d) => $d->assignments->first()?->assignable_id)->filter()->unique()->all();
        $assignedClients = Client::with([
            'site' => fn ($query) => $query->select(['id', 'name']),
        ])
            ->whereIn('id', $assignedClientIds)
            ->get()
            ->keyBy('id');

        $assignedTrackers = $assignedDevices->map(function (Device $d) use ($assignedClients) {
            $assignment = $d->assignments->first();
            $client = $assignedClients[$assignment?->assignable_id] ?? null;

            return [
                'id' => $d->id,
                'device_uid' => $d->device_uid,
                'name' => $d->name,
                'serial' => $d->serial_number,
                'provider' => $d->provider,
                'status' => $d->status?->value,
                'client_id' => $assignment?->assignable_id,
                'client_name' => $client ? trim(($client->first_name ?? '').' '.($client->last_name ?? '')) : 'Unknown',
                'client_house' => $client?->site?->name ?? 'Unknown',
                'battery' => $d->battery_level,
                'detail_url' => "/security-devices/devices/{$d->id}",
            ];
        });

        return [
            'clients' => $availableClients,
            'available_trackers' => $availableTrackers,
            'assigned_trackers' => $assignedTrackers,
        ];
    }

    public function assign(Request $request)
    {
        $data = $request->validate([
            'tracker_id' => ['required', 'integer', 'exists:devices,id'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            // Optional: an explicit consent record gathered alongside the
            // assign form. When omitted we look up any existing valid Fleet
            // Tracking consent for the client. The service still rejects
            // client+tracking assignments with no resolvable consent.
            'consent_id' => ['nullable', 'integer', 'exists:client_consents,id'],
        ]);

        $user = $request->user();

        try {
            DB::transaction(function () use ($data, $user): void {
                $client = Client::query()
                    ->whereIn('id', $this->getAuthorizedClientIds($user))
                    ->lockForUpdate()
                    ->find($data['client_id']);
                abort_unless($client, 403);

                $device = $this->deviceAccess->visibleDevices($user)
                    ->where('domain', 'tracking')
                    ->lockForUpdate()
                    ->find($data['tracker_id']);
                abort_unless($device, 403);

                if ($device->assignments()->active()->lockForUpdate()->first()) {
                    throw ValidationException::withMessages([
                        'tracker_id' => 'This tracker is already assigned. Unassign it before assigning it to another resident.',
                    ]);
                }

                $consentId = $this->resolveTrackingConsentId($client, $data['consent_id'] ?? null);

                $this->assignmentService->assign(
                    device: $device,
                    assignableType: DeviceAssignment::TARGET_CLIENT,
                    assignableId: $client->id,
                    assignedByUserId: $user->id,
                    consentId: $consentId,
                );
            });
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['tracker_id' => $e->getMessage()]);
        }

        return redirect()->route('fleet-assets.resident-tracking.index', ['new' => 1])
            ->with('success', 'Tracker assigned to resident.');
    }

    public function unassign(Request $request, Device $device)
    {
        $user = $request->user();

        DB::transaction(function () use ($device, $user): void {
            $lockedDevice = $this->deviceAccess->visibleDevices($user)
                ->lockForUpdate()
                ->find($device->id);
            abort_unless($lockedDevice, 403);

            $this->deviceAccess->assertCanManageActiveAssignment($user, $lockedDevice, true);

            $activeClientAssignment = $lockedDevice->assignments()
                ->active()
                ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
                ->lockForUpdate()
                ->first();
            abort_unless(
                $activeClientAssignment
                    && in_array(
                        (int) $activeClientAssignment->assignable_id,
                        $this->getAuthorizedClientIds($user),
                        true,
                    ),
                403,
            );

            $this->assignmentService->release($lockedDevice, $user->id);
        });

        return redirect()->route('fleet-assets.resident-tracking.index', ['new' => 1])
            ->with('success', 'Tracker unassigned from resident.');
    }

    public function history(Request $request, Client $client)
    {
        $user = $request->user();
        $this->assertCanAccessClient($user, $client);
        $assignment = $this->assertHasActiveTrackingConsent($client);
        $device = $assignment->device;

        // Range pills: today | 24h | 7d | 30d | custom. Default 24h.
        $range = $request->string('range')->toString() ?: '24h';
        [$dateFrom, $dateTo] = $this->resolveHistoryRange(
            $range,
            $request->input('date_from'),
            $request->input('date_to'),
        );

        $eventTypesInput = $request->input('event_types');
        $eventTypes = is_array($eventTypesInput)
            ? $eventTypesInput
            : array_filter(array_map('trim', explode(',', (string) $eventTypesInput)));

        $filters = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'event_types' => $eventTypes,
        ];

        $locations = app(IntegrationEventHistoryService::class)
            ->forDevice($device, $filters, true, $assignment->retention_days);

        $availableEventTypes = $locations
            ->pluck('event_type')
            ->filter()
            ->unique()
            ->values();

        $client->loadMissing(['site' => fn ($query) => $query->select(['id', 'name'])]);
        $houseGeofence = $client->house_geofence_id
            ? $this->applyGeofenceScope(
                AssetGeofence::query()->whereKey($client->house_geofence_id),
                $request->user(),
            )->first()
            : null;
        $client->setRelation('houseGeofence', $houseGeofence);

        $resident = $device ? $this->buildResidentPayload($device, $client, []) : null;

        return Inertia::render('fleet-assets/resident-tracking/history', [
            'client' => [
                'id' => $client->id,
                'name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
                'house' => $client->site?->name ?? 'Unknown',
                'photo' => $client->profile_photo_url,
            ],
            'resident' => $resident,
            'tracker' => $device ? [
                'id' => $device->id,
                'device_uid' => $device->device_uid,
                'name' => $device->name,
                'serial' => $device->serial_number,
                'status' => $device->status?->value,
                'detail_url' => "/security-devices/devices/{$device->id}",
            ] : null,
            'locations' => $locations,
            'available_event_types' => $availableEventTypes,
            'filters' => [
                'range' => $range,
                'date_from' => $dateFrom ? substr((string) $dateFrom, 0, 10) : null,
                'date_to' => $dateTo ? substr((string) $dateTo, 0, 10) : null,
                'event_types' => $eventTypes,
            ],
            'privacy_status_url' => route(
                'fleet-assets.resident-tracking.privacy-status',
                ['client' => $client->id],
                false,
            ),
            'export_url' => route(
                'fleet-assets.resident-tracking.export',
                ['client' => $client->id],
                false,
            ),
            'can_export' => $user->canDo('assets.telemetry.export'),
            'retention_days' => (int) $assignment->retention_days,
        ])->toResponse($request)->withHeaders($this->privateLocationHeaders());
    }

    public function privacyStatus(Request $request, Client $client)
    {
        $user = $request->user();
        $this->assertCanAccessClient($user, $client);
        $assignment = $this->trackingPrivacy->authorisedClientAssignment($client);

        return response()->json([
            'active' => $assignment !== null,
            'checked_at' => now()->toISOString(),
            'retention_days' => $assignment?->retention_days,
            'export_allowed' => $assignment !== null
                && $user->canDo('assets.telemetry.export'),
        ])->withHeaders($this->privateLocationHeaders());
    }

    public function exportHistory(Request $request, Client $client)
    {
        $user = $request->user();
        $this->assertCanAccessClient($user, $client);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'date_from' => ['required', 'date', 'before_or_equal:today'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from', 'before_or_equal:today'],
            'event_types' => ['sometimes', 'array', 'max:20'],
            'event_types.*' => ['string', 'max:100'],
        ]);

        return $this->locationExport->export($client, $user, $data);
    }

    private function resolveHistoryRange(string $range, mixed $rawFrom, mixed $rawTo): array
    {
        $now = now();
        switch ($range) {
            case 'today':
                return [$now->copy()->startOfDay()->toDateTimeString(), $now->toDateTimeString()];
            case '24h':
                return [$now->copy()->subDay()->toDateTimeString(), $now->toDateTimeString()];
            case '7d':
                return [$now->copy()->subDays(7)->toDateTimeString(), $now->toDateTimeString()];
            case '30d':
                return [$now->copy()->subDays(30)->toDateTimeString(), $now->toDateTimeString()];
            case 'custom':
            default:
                return [
                    $rawFrom ? (string) $rawFrom : null,
                    $rawTo ? (string) $rawTo : null,
                ];
        }
    }

    public function locateNow(Request $request, Client $client, LocateNowService $locateNow)
    {
        $user = $request->user();
        $this->assertCanAccessClient($user, $client);
        $assignment = $this->assertHasActiveTrackingConsent($client);
        $device = $assignment->device;

        if (! $device) {
            throw ValidationException::withMessages([
                'tracker' => 'This resident does not have a paired Queclink tracker.',
            ]);
        }

        $managementUrl = $locateNow->managementUrlForDevice($device);

        return redirect()->to($managementUrl)->with(
            'success',
            'Review the governed location refresh, confirm your identity, and record the operational reason before dispatch.',
        );
    }

    public function acknowledgePanic(
        Request $request,
        Client $client,
        ControlRoomAlertLifecycleService $lifecycle,
    ) {
        $user = $request->user();
        $this->assertCanAccessClient($user, $client);
        $this->assertHasActiveTrackingConsent($client);

        $this->acknowledgePanicForClient($client, $user, $lifecycle);

        return back()->with('success', 'Panic acknowledged.');
    }

    private function acknowledgePanicForClient(
        Client $client,
        User $actor,
        ControlRoomAlertLifecycleService $lifecycle,
    ): void {
        DB::transaction(function () use ($client, $actor, $lifecycle): void {
            $device = $this->registry
                ->forClient($client->id)
                ->where('domain', 'tracking')
                ->first();

            if ($device) {
                $meta = $device->meta ?? [];
                $meta['panic_active'] = false;
                $meta['panic_acknowledged_at'] = now()->toISOString();
                $meta['panic_acknowledged_by'] = $actor->id;
                $device->forceFill(['meta' => $meta])->save();
            }

            if (Schema::hasTable('control_room_alerts')) {
                ControlRoomAlert::query()
                    ->where('client_id', $client->id)
                    ->whereIn('source', ['tracker', 'resident_tracker'])
                    ->where('status', ControlRoomAlert::STATUS_OPEN)
                    ->get()
                    ->each(fn (ControlRoomAlert $alert) => $lifecycle->acknowledge($alert, $actor));
            }
        });
    }

    private function applyGeofenceScope(Builder $query, User $user): Builder
    {
        $siteIds = $this->accessibleSiteIds($user);
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $assetIds = $this->accessibleAssetIds($user);

        return $query->where(function (Builder $scope) use ($siteIds, $assetIds) {
            $scope->whereIn('site_id', $siteIds)
                ->orWhere(function (Builder $fallback) use ($assetIds) {
                    $fallback->whereNull('site_id')
                        ->where(function (Builder $assets) use ($assetIds) {
                            $assets->whereHas('asset', fn (Builder $asset) => $asset
                                ->whereIn('assets.id', $assetIds))
                                ->orWhereHas('assignedAssets', fn (Builder $asset) => $asset
                                    ->whereIn('assets.id', $assetIds));
                        });
                });
        });
    }

    /**
     * @param  array<int, int>  $authorizedClientIds
     */
    private function applyOutingScope(
        Builder $query,
        User $user,
        array $authorizedClientIds,
    ): Builder {
        $assetIds = $this->accessibleAssetIds($user);
        $clientIds = $authorizedClientIds;
        if ($assetIds === [] && $clientIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('asset_id', $assetIds)
            ->whereHas('clients', fn (Builder $clients) => $clients
                ->whereIn('clients.id', $clientIds));
    }

    /**
     * @return array<int, int>
     */
    private function accessibleAssetIds(User $user): array
    {
        return $this->deviceAccess->authorizedAssetIds($user);
    }

    private function getAuthorizedClientIds(User $user): array
    {
        return $this->deviceAccess->authorizedClientIds($user);
    }

    /**
     * @return array<int, int>
     */
    private function accessibleSiteIds(User $user): array
    {
        return $this->deviceAccess->accessibleSiteIds($user);
    }

    private function assertCanAccessClient(User $user, Client $client): void
    {
        abort_unless(
            in_array((int) $client->id, $this->getAuthorizedClientIds($user), true),
            403,
        );
    }

    private function assertHasActiveTrackingConsent(Client $client): DeviceAssignment
    {
        $assignment = $this->trackingPrivacy->authorisedClientAssignment($client);
        abort_unless($assignment, 403);

        return $assignment;
    }

    private function privateLocationHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Vary' => 'Cookie',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    private function resolveTrackingConsentId(Client $client, ?int $requestedConsentId): int
    {
        $candidate = $requestedConsentId
            ? ClientConsent::query()->find($requestedConsentId)
            : ConsentValidationService::latestValidTrackingConsentForClient($client);

        $consent = $candidate
            ? $this->validTrackingConsentQuery($client)
                ->whereKey($candidate->id)
                ->lockForUpdate()
                ->first()
            : null;

        if (! $consent) {
            throw ValidationException::withMessages([
                'consent_id' => $requestedConsentId
                    ? 'The selected tracking consent is not active for this resident.'
                    : 'Assigning a resident tracker requires active location tracking consent.',
            ]);
        }

        return (int) $consent->id;
    }

    private function validTrackingConsentQuery(Client $client): Builder
    {
        return $this->trackingConsentQuery()->where('client_id', $client->id);
    }

    private function trackingConsentQuery(): Builder
    {
        return ClientConsent::query()
            ->where('status', 'given')
            ->whereNull('withdrawn_at')
            ->whereNull('superseded_by_consent_id')
            ->where(function (Builder $expiry): void {
                $expiry->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->whereHas('consentType', function (Builder $type): void {
                $type->where('active', true)
                    ->where(function (Builder $tracking): void {
                        $tracking->whereIn('name', [
                            'Fleet Tracking',
                            'Personal Tracker (Wandering Risk)',
                            'Asset Location Tracking (Safety)',
                        ])->orWhere('name', 'like', '%Tracking%')
                            ->orWhere('name', 'like', '%Tracker%')
                            ->orWhere('name', 'like', '%Location%');
                    });
            });
    }

    private function latestLocateCommandStatus(Device $device): ?string
    {
        return QueclinkPendingCommand::query()
            ->where('command_word', 'GTRTO')
            ->whereHas('device', fn ($query) => $query->where('device_id', $device->id))
            ->latest()
            ->value('status');
    }

    private function isTruthy(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 'true'
            || $value === 'yes';
    }

    private function latestAddressForDevice(Device $device): ?string
    {
        return FleetTelemetryEvent::query()
            ->where('device_id', $device->id)
            ->where('consent_blocked', false)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereNotNull('address')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->value('address');
    }

    private function formatCoordinates(mixed $lat, mixed $lng): ?string
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        return sprintf('%.6f, %.6f', (float) $lat, (float) $lng);
    }

    private function buildMapGeofences($geofences): array
    {
        try {
            return $geofences->map(fn ($gf) => $this->serialiseGeofence($gf))->filter()->values()->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    private function serialiseGeofence($gf): ?array
    {
        if (! $gf) {
            return null;
        }

        $shape = $gf->shape ?? [];
        $result = [
            'id' => (string) $gf->id,
            'name' => $gf->name,
            'type' => $gf->type ?? 'circle',
            'scope' => $gf->scope,
            'applies_to' => $this->geofenceAppliesTo($gf),
            'color' => $shape['color'] ?? '#8b5cf6',
            'is_active' => (bool) ($gf->is_active ?? true),
        ];

        if ($gf->type === 'circle') {
            $result['center'] = [
                'lat' => $shape['lat'] ?? $shape['latitude'] ?? 0,
                'lng' => $shape['lng'] ?? $shape['lon'] ?? $shape['longitude'] ?? 0,
            ];
            $result['radius_m'] = $shape['radius_m'] ?? $shape['radius'] ?? 100;
        } elseif ($gf->type === 'polygon') {
            $points = $shape['coordinates'] ?? $shape['points'] ?? [];
            $result['coordinates'] = collect($points)->map(fn ($p) => [
                'lat' => $p['lat'] ?? $p['latitude'] ?? 0,
                'lng' => $p['lng'] ?? $p['lon'] ?? $p['longitude'] ?? 0,
            ])->toArray();
        }

        return $result;
    }

    private function geofenceAppliesTo($gf): string
    {
        $scope = strtolower((string) ($gf->scope ?? ''));
        if (in_array($scope, ['house', 'resident', 'asset', 'vehicle', 'perimeter'], true)) {
            return $scope;
        }

        if ($gf->asset_id) {
            return 'asset';
        }

        if ($gf->site_id) {
            return 'house';
        }

        return 'custom';
    }

    private function buildResidentPayload(Device $device, Client $client, array $activeOutingClientIds): array
    {
        $meta = $device->meta ?? [];
        $lat = $device->latitude ?? $meta['lat'] ?? $meta['latitude'] ?? null;
        $lng = $device->longitude ?? $meta['lng'] ?? $meta['longitude'] ?? null;
        $address = $lat !== null && $lng !== null ? $this->latestAddressForDevice($device) : null;
        $coordinates = $this->formatCoordinates($lat, $lng);
        $battery = $this->resolveBatteryLevel($device, $meta);
        $batteryThreshold = (int) ($meta['battery_low_threshold'] ?? 20);
        $batteryStatus = $meta['battery_status']
            ?? ($battery === null ? 'unknown' : ((float) $battery <= $batteryThreshold ? 'low' : 'normal'));

        $geofenceStatus = $this->geofenceStatus->evaluate($lat, $lng, $client->houseGeofence);

        $onOuting = in_array($client->id, $activeOutingClientIds);
        $statusValue = $device->status?->value ?? 'unknown';

        return [
            'id' => $device->id,
            'device_uid' => $device->device_uid,
            'client_id' => $client->id,
            'name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
            'preferred_name' => $client->preferred_name,
            'house' => $client->site?->name ?? 'Unknown',
            'site_id' => $client->site_id,
            'photo' => $client->profile_photo_url,
            'tracker_name' => $device->name,
            'tracker_serial' => $device->serial_number,
            'status' => $statusValue === 'active' ? 'online' : ($statusValue === 'offline' ? 'offline' : 'unknown'),
            'health_status' => $device->health_status?->value,
            'last_seen_at' => $device->last_seen_at?->toISOString(),
            'lat' => $lat !== null ? (float) $lat : null,
            'lng' => $lng !== null ? (float) $lng : null,
            'address' => $address,
            'coordinates' => $coordinates,
            'display_location' => $address ?: $coordinates,
            'battery' => $battery,
            'battery_status' => $batteryStatus,
            'battery_voltage_mv' => $meta['battery_voltage_mv'] ?? null,
            'battery_low_threshold' => $batteryThreshold,
            'battery_updated_at' => optional($device->battery_updated_at)->toISOString(),
            'charging_status' => $meta['charging_status'] ?? null,
            'external_power' => $this->isTruthy($meta['external_power'] ?? false),
            'last_power_event' => $meta['power_event'] ?? null,
            'last_safety_event' => $meta['last_safety_event'] ?? null,
            'last_safety_event_at' => $meta['last_safety_event_at'] ?? null,
            'panic_active' => (bool) ($meta['panic_active'] ?? false),
            'speed' => $meta['speed'] ?? null,
            'heading' => $meta['heading'] ?? null,
            'accuracy' => $meta['accuracy'] ?? null,
            'altitude' => $meta['altitude'] ?? null,
            'motion' => $meta['motion'] ?? null,
            'imei' => $device->imei,
            'mac' => $device->mac_address,
            'model' => $device->model,
            'manufacturer' => $device->manufacturer,
            'firmware_version' => $device->firmware_version,
            'provider' => $device->provider,
            'hardware_version' => $meta['hardware_version'] ?? null,
            'ble_firmware' => $meta['ble_firmware'] ?? null,
            'ble_mac' => $meta['ble_mac'] ?? null,
            'sim_iccid' => $meta['iccid'] ?? null,
            'imsi' => $meta['imsi'] ?? null,
            'network_type' => $meta['network_type'] ?? null,
            'rsrp' => $meta['rsrp'] ?? null,
            'band' => $meta['band'] ?? null,
            'mcc' => $meta['mcc'] ?? null,
            'mnc' => $meta['mnc'] ?? null,
            'cell_id' => $meta['cell_id'] ?? null,
            'lac' => $meta['lac'] ?? null,
            'satellites' => $meta['satellites'] ?? null,
            'last_frame_at' => $meta['last_frame_at'] ?? null,
            'last_location_at' => $meta['last_location_at'] ?? null,
            'config_snapshot' => $meta['config_snapshot'] ?? null,
            'geofence_status' => $geofenceStatus,
            'on_outing' => $onOuting,
            'house_geofence' => $this->serialiseGeofence($client->houseGeofence),
            'locate_now_url' => route('fleet-assets.resident-tracking.locate-now', ['client' => $client->id], false),
            'acknowledge_panic_url' => route('fleet-assets.resident-tracking.acknowledge-panic', ['client' => $client->id], false),
            'profile_url' => "/operations/clients/{$client->id}?tab=location&from=fleet",
            'history_url' => "/fleet-assets/resident-tracking/history/{$client->id}",
            'last_command_status' => $this->latestLocateCommandStatus($device),
            'detail_url' => "/security-devices/devices/{$device->id}",
        ];
    }

    private function resolveBatteryLevel(Device $device, array $meta): ?int
    {
        $raw = $device->battery_level ?? $meta['battery'] ?? $meta['battery_level'] ?? null;
        if ($raw === null) {
            return null;
        }

        return (int) $raw;
    }
}
