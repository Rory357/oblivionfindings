<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Http\Controllers\Controller;
use App\Models\AssetGeofence;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ControlRoomAlert;
use App\Models\FleetOuting;
use App\Models\FleetTelemetryEvent;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Services\Integration\IntegrationEventHistoryService;
use App\Services\Queclink\LocateNowService;
use App\Services\Tracking\GeofenceStatusService;
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
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $tenantId = $user?->tenant_id ?? 1;

        // Get tracking devices actively assigned to clients (canonical).
        $clientDevices = Device::query()
            ->where('domain', 'tracking')
            ->whereHas('assignments', function ($q) {
                $q->active()->where('assignable_type', 'client');
            })
            ->with(['assignments' => fn ($q) => $q->active()->where('assignable_type', 'client')])
            ->get();

        // Get client IDs the user is authorized to see.
        $authorizedClientIds = $this->getAuthorizedClientIds($user);

        // Filter to authorized clients.
        $clientDevices = $clientDevices->filter(function (Device $device) use ($authorizedClientIds) {
            $assignment = $device->assignments->first();
            if (! $assignment) {
                return false;
            }

            return $authorizedClientIds === null || in_array($assignment->assignable_id, $authorizedClientIds);
        });

        // Load client data (with house geofence relation for per-resident zone check).
        $clientIds = $clientDevices->map(fn ($d) => $d->assignments->first()?->assignable_id)->filter()->unique()->values();
        $clients = Client::with(['site:id,name', 'houseGeofence'])->whereIn('id', $clientIds)->get()->keyBy('id');

        // Load every active geofence (dashboard renders all of them).
        $geofences = collect();
        try {
            if (Schema::hasTable('asset_geofences')) {
                $geofences = AssetGeofence::where('is_active', true)->get();
            }
        } catch (\Throwable) {
            $geofences = collect();
        }

        // Load active outings.
        $activeOutingClientIds = [];
        try {
            if (Schema::hasTable('fleet_outings') && Schema::hasTable('fleet_outing_residents')) {
                $activeOutingClientIds = DB::table('fleet_outing_residents')
                    ->join('fleet_outings', 'fleet_outings.id', '=', 'fleet_outing_residents.outing_id')
                    ->where('fleet_outings.status', 'active')
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

        $totalClients = $authorizedClientIds === null
            ? Client::where('status', 'active')->count()
            : count($authorizedClientIds);
        $untracked = max(0, $totalClients - $totalTracked);

        // Recent alerts (unchanged).
        $recentAlerts = [];
        try {
            if (Schema::hasTable('control_room_alerts')) {
                $recentAlerts = ControlRoomAlert::whereIn('source', ['tracker', 'resident_tracker', 'geofence'])
                    ->latest()->limit(5)->get()
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
                $activeOutings = FleetOuting::where('status', 'active')
                    ->with(['clients', 'asset'])->latest()->limit(10)->get()
                    ->map(fn ($o) => [
                        'id' => $o->id, 'title' => $o->title ?? 'Outing',
                        'destination' => $o->destination ?? 'Unknown',
                        'resident_count' => $o->clients->count(),
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
        if ($focusClientId && $authorizedClientIds !== null && ! in_array($focusClientId, $authorizedClientIds, true)) {
            $focusClientId = null;
        }

        return Inertia::render('fleet-assets/resident-tracking/index', [
            'residents' => $residents,
            'stats' => [
                'tracked' => $totalTracked, 'online' => $online, 'offline' => $offline,
                'untracked' => $untracked,
                'online_percent' => $totalTracked > 0 ? round(($online / $totalTracked) * 100, 1) : 0,
                'in_geofence' => $inGeofence, 'outside_geofence' => $outsideGeofence,
                'low_battery' => $lowBattery, 'safety_score' => $safetyScore, 'avg_battery' => $avgBattery,
                'panic_active' => $residents->filter(fn ($r) => $r['panic_active'] ?? false)->count(),
            ],
            'recent_alerts' => $recentAlerts,
            'active_outings' => $activeOutings,
            'geofences' => $mapGeofences,
            'focus_client_id' => $focusClientId,
            'can' => [
                'manage' => (bool) $user?->canDo('fleet.manage'),
            ],
        ]);
    }

    public function assignPage(Request $request)
    {
        $user = $request->user();
        $authorizedClientIds = $this->getAuthorizedClientIds($user);

        // Clients already tracked (have an active tracking device assignment).
        $trackedClientIds = DeviceAssignment::query()
            ->active()
            ->where('assignable_type', 'client')
            ->whereHas('device', fn ($q) => $q->where('domain', 'tracking'))
            ->pluck('assignable_id')
            ->toArray();

        $clientQuery = Client::where('status', 'active')->orderBy('first_name');
        if ($authorizedClientIds !== null) {
            $clientQuery->whereIn('id', $authorizedClientIds);
        }
        $availableClients = $clientQuery->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => trim(($c->first_name ?? '').' '.($c->last_name ?? '')),
            'house' => $c->site?->name ?? 'Unknown',
            'is_tracked' => in_array($c->id, $trackedClientIds),
        ]);

        // Available trackers (tracking devices not assigned to anyone).
        $availableTrackers = Device::query()
            ->where('domain', 'tracking')
            ->whereNotIn('status', ['decommissioned', 'lost'])
            ->whereDoesntHave('assignments', fn ($q) => $q->active())
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
        $assignedDevices = Device::query()
            ->where('domain', 'tracking')
            ->whereHas('assignments', fn ($q) => $q->active()->where('assignable_type', 'client'))
            ->with(['assignments' => fn ($q) => $q->active()->where('assignable_type', 'client')])
            ->orderBy('name')
            ->get();

        $assignedClientIds = $assignedDevices->map(fn ($d) => $d->assignments->first()?->assignable_id)->filter()->unique()->all();
        $assignedClients = Client::with('site:id,name')->whereIn('id', $assignedClientIds)->get()->keyBy('id');

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

        return Inertia::render('fleet-assets/resident-tracking/assign', [
            'clients' => $availableClients,
            'available_trackers' => $availableTrackers,
            'assigned_trackers' => $assignedTrackers,
            'can' => [
                'manage' => (bool) $user?->canDo('fleet.manage'),
            ],
        ]);
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

        $device = Device::findOrFail($data['tracker_id']);

        $consentId = $data['consent_id']
            ?? ClientConsent::query()
                ->where('client_id', $data['client_id'])
                ->where('status', 'given')
                ->whereNull('withdrawn_at')
                ->latest('given_at')
                ->value('id');

        try {
            $this->assignmentService->assign(
                device: $device,
                assignableType: 'client',
                assignableId: $data['client_id'],
                assignedByUserId: $request->user()->id,
                consentId: $consentId,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['tracker_id' => $e->getMessage()]);
        }

        return redirect()->route('fleet-assets.resident-tracking.assign')
            ->with('success', 'Tracker assigned to resident.');
    }

    public function unassign(Device $device)
    {
        $this->assignmentService->release($device, auth()->id());

        return redirect()->route('fleet-assets.resident-tracking.assign')
            ->with('success', 'Tracker unassigned from resident.');
    }

    public function history(Request $request, Client $client)
    {
        $tenantId = $client->tenant_id ?? 1;

        // Canonical device lookup.
        $device = $this->registry
            ->forClient($tenantId, $client->id)
            ->where('domain', 'tracking')
            ->first();

        $locations = app(IntegrationEventHistoryService::class)
            ->forDevice($device, $request->only(['date_from', 'date_to']), true);

        return Inertia::render('fleet-assets/resident-tracking/history', [
            'client' => [
                'id' => $client->id,
                'name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
                'house' => $client->site?->name ?? 'Unknown',
                'photo' => $client->profile_photo_url,
            ],
            'tracker' => $device ? [
                'id' => $device->id,
                'device_uid' => $device->device_uid,
                'name' => $device->name,
                'serial' => $device->serial_number,
                'status' => $device->status?->value,
                'detail_url' => "/security-devices/devices/{$device->id}",
            ] : null,
            'locations' => $locations,
            'filters' => $request->only(['date_from', 'date_to']),
        ]);
    }

    public function locateNow(Request $request, Client $client, LocateNowService $locateNow)
    {
        $authorizedClientIds = $this->getAuthorizedClientIds($request->user());
        abort_unless($authorizedClientIds === null || in_array($client->id, $authorizedClientIds, true), 403);

        $tenantId = $client->tenant_id ?? 1;
        $device = $this->registry
            ->forClient($tenantId, $client->id)
            ->where('domain', 'tracking')
            ->first();

        if (! $device) {
            throw ValidationException::withMessages([
                'tracker' => 'This resident does not have a paired Queclink tracker.',
            ]);
        }

        $locateNow->queueForDevice($device, $request->user());

        return back()->with('success', 'Locate Now queued. The tracker will report on its next connection.');
    }

    public function acknowledgePanic(Request $request, Client $client)
    {
        $authorizedClientIds = $this->getAuthorizedClientIds($request->user());
        abort_unless($authorizedClientIds === null || in_array($client->id, $authorizedClientIds, true), 403);

        $this->acknowledgePanicForClient($client, $request->user()?->id);

        return back()->with('success', 'Panic acknowledged.');
    }

    private function acknowledgePanicForClient(Client $client, ?int $userId): void
    {
        $tenantId = $client->tenant_id ?? 1;
        $device = $this->registry
            ->forClient($tenantId, $client->id)
            ->where('domain', 'tracking')
            ->first();

        if ($device) {
            $meta = $device->meta ?? [];
            $meta['panic_active'] = false;
            $meta['panic_acknowledged_at'] = now()->toISOString();
            $meta['panic_acknowledged_by'] = $userId;
            $device->forceFill(['meta' => $meta])->save();
        }

        if (Schema::hasTable('control_room_alerts')) {
            ControlRoomAlert::query()
                ->where('client_id', $client->id)
                ->whereIn('source', ['tracker', 'resident_tracker'])
                ->whereIn('status', ['open', 'triaging'])
                ->update([
                    'status' => 'ack',
                    'acknowledged_at' => now(),
                    'acknowledged_by_user_id' => $userId,
                ]);
        }
    }

    private function getAuthorizedClientIds($user): ?array
    {
        if (
            in_array($user->role, ['admin', 'super-admin', 'manager'], true)
            || $user->canDo('clients.viewAny')
            || $user->canDo('fleet.viewAny')
            || $user->canDo('assets.viewAny')
        ) {
            return null;
        }

        $clientIds = [];

        if (Schema::hasTable('client_user')) {
            $pivotIds = DB::table('client_user')
                ->where('user_id', $user->id)
                ->pluck('client_id')
                ->toArray();
            $clientIds = array_merge($clientIds, $pivotIds);
        }

        if ($user->site_id) {
            $siteClientIds = Client::where('site_id', $user->site_id)
                ->where('status', 'active')
                ->pluck('id')
                ->toArray();
            $clientIds = array_merge($clientIds, $siteClientIds);
        }

        return array_unique($clientIds);
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
