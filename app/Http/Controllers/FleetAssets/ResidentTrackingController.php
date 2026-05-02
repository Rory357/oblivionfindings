<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Http\Controllers\Controller;
use App\Models\AssetGeofence;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\FleetOuting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class ResidentTrackingController extends Controller
{
    public function __construct(
        private readonly DeviceRegistryService $registry,
        private readonly DeviceAssignmentService $assignmentService,
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
            if (!$assignment) return false;
            return $authorizedClientIds === null || in_array($assignment->assignable_id, $authorizedClientIds);
        });

        // Load client data.
        $clientIds = $clientDevices->map(fn ($d) => $d->assignments->first()?->assignable_id)->filter()->unique()->values();
        $clients = Client::with('site:id,name')->whereIn('id', $clientIds)->get()->keyBy('id');

        // Load geofences for resident zones.
        $geofences = collect();
        try {
            if (Schema::hasTable('asset_geofences')) {
                $geofences = AssetGeofence::where('is_active', true)
                    ->where(function ($q) {
                        $q->where('scope', 'resident')
                          ->orWhereHas('asset', fn ($q2) => $q2->where('asset_type', 'house'));
                    })
                    ->get();
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
        $residents = $clientDevices->map(function (Device $device) use ($clients, $geofences, $activeOutingClientIds) {
            $assignment = $device->assignments->first();
            if (!$assignment) return null;

            $client = $clients->get($assignment->assignable_id);
            if (!$client) return null;

            $meta = $device->meta ?? [];
            $lat = $device->latitude ?? $meta['lat'] ?? $meta['latitude'] ?? null;
            $lng = $device->longitude ?? $meta['lng'] ?? $meta['longitude'] ?? null;
            $battery = $device->battery_level ?? $meta['battery'] ?? $meta['battery_level'] ?? null;

            // Determine geofence status.
            $geofenceStatus = 'unknown';
            if ($lat !== null && $lng !== null) {
                $siteId = $client->site_id;
                $applicableGeofence = $geofences->first(function ($gf) use ($siteId) {
                    if ($gf->scope === 'resident' && $gf->site_id === $siteId) return true;
                    if ($gf->asset && $gf->asset->site_id === $siteId) return true;
                    return false;
                });

                if ($applicableGeofence) {
                    $shape = $applicableGeofence->shape ?? [];
                    if ($applicableGeofence->type === 'circle') {
                        $centerLat = $shape['lat'] ?? $shape['latitude'] ?? null;
                        $centerLng = $shape['lng'] ?? $shape['lon'] ?? $shape['longitude'] ?? null;
                        $radiusM = $shape['radius_m'] ?? $shape['radius'] ?? null;
                        if ($centerLat !== null && $centerLng !== null && $radiusM !== null) {
                            $distance = sqrt(pow($lat - $centerLat, 2) + pow($lng - $centerLng, 2)) * 111000;
                            $geofenceStatus = $distance <= $radiusM ? 'in_zone' : 'outside_zone';
                        }
                    } elseif ($applicableGeofence->type === 'polygon') {
                        $geofenceStatus = 'in_zone';
                    }
                }
            }

            $onOuting = in_array($client->id, $activeOutingClientIds);
            $statusValue = $device->status?->value ?? 'unknown';

            return [
                'id' => $device->id,
                'device_uid' => $device->device_uid,
                'client_id' => $client->id,
                'name' => trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')),
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
                'battery' => $battery,
                'speed' => $meta['speed'] ?? null,
                'geofence_status' => $geofenceStatus,
                'on_outing' => $onOuting,
                'detail_url' => "/security-devices/devices/{$device->id}",
            ];
        })->filter()->values();

        // Stats.
        $totalTracked = $residents->count();
        $online = $residents->where('status', 'online')->count();
        $offline = $residents->where('status', 'offline')->count();
        $inGeofence = $residents->where('geofence_status', 'in_zone')->count();
        $outsideGeofence = $residents->where('geofence_status', 'outside_zone')->count();
        $lowBattery = $residents->filter(fn ($r) => ($r['battery'] ?? 100) < 20)->count();
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
                            'resident_name' => $client ? trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')) : null,
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

        // Geofences for map (unchanged).
        $mapGeofences = $this->buildMapGeofences($geofences);

        return Inertia::render('fleet-assets/resident-tracking/index', [
            'residents' => $residents,
            'stats' => [
                'tracked' => $totalTracked, 'online' => $online, 'offline' => $offline,
                'untracked' => $untracked,
                'online_percent' => $totalTracked > 0 ? round(($online / $totalTracked) * 100, 1) : 0,
                'in_geofence' => $inGeofence, 'outside_geofence' => $outsideGeofence,
                'low_battery' => $lowBattery, 'safety_score' => $safetyScore, 'avg_battery' => $avgBattery,
            ],
            'recent_alerts' => $recentAlerts,
            'active_outings' => $activeOutings,
            'geofences' => $mapGeofences,
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
            'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
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
                'client_name' => $client ? trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')) : 'Unknown',
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
            ?? \App\Models\ClientConsent::query()
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

        $locations = app(\App\Services\Integration\IntegrationEventHistoryService::class)
            ->forDevice($device, $request->only(['date_from', 'date_to']), true);

        return Inertia::render('fleet-assets/resident-tracking/history', [
            'client' => [
                'id' => $client->id,
                'name' => trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')),
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

    private function getAuthorizedClientIds($user): ?array
    {
        if (in_array($user->role, ['admin', 'super-admin', 'manager'])) {
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

    private function buildMapGeofences($geofences): array
    {
        try {
            return $geofences->map(function ($gf) {
                $shape = $gf->shape ?? [];
                $result = [
                    'id' => (string) $gf->id, 'name' => $gf->name,
                    'type' => $gf->type ?? 'circle', 'color' => $shape['color'] ?? '#8b5cf6',
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
            })->toArray();
        } catch (\Throwable) {
            return [];
        }
    }
}
