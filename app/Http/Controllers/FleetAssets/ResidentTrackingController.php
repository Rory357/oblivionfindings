<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\AssetGeofence;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\FleetOuting;
use App\Models\LocationHardware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class ResidentTrackingController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Get trackers linked to clients
        $query = LocationHardware::query()
            ->where('category', LocationHardware::CATEGORY_TRACKER)
            ->where('linked_person_type', 'client')
            ->whereNotNull('linked_person_id');

        $trackers = $query->get();

        // Get client IDs the user is authorized to see
        $authorizedClientIds = $this->getAuthorizedClientIds($user);

        // Filter trackers to only those linked to authorized clients
        $trackers = $trackers->filter(function ($tracker) use ($authorizedClientIds) {
            return $authorizedClientIds === null || in_array($tracker->linked_person_id, $authorizedClientIds);
        });

        // Load client data
        $clientIds = $trackers->pluck('linked_person_id')->unique()->filter()->values();
        $clients = Client::whereIn('id', $clientIds)->get()->keyBy('id');

        // Load geofences for resident zones
        $geofences = collect();
        try {
            if (Schema::hasTable('asset_geofences')) {
                $geofences = AssetGeofence::where('is_active', true)
                    ->where(function ($q) {
                        $q->where('scope', 'resident')
                          ->orWhereHas('asset', function ($q2) {
                              $q2->where('asset_type', 'house');
                          });
                    })
                    ->get();
            }
        } catch (\Throwable $e) {
            $geofences = collect();
        }

        // Load active outings to detect residents on outings
        $activeOutingClientIds = [];
        try {
            if (Schema::hasTable('fleet_outings') && Schema::hasTable('fleet_outing_residents')) {
                $activeOutingClientIds = DB::table('fleet_outing_residents')
                    ->join('fleet_outings', 'fleet_outings.id', '=', 'fleet_outing_residents.outing_id')
                    ->where('fleet_outings.status', 'active')
                    ->pluck('fleet_outing_residents.client_id')
                    ->toArray();
            }
        } catch (\Throwable $e) {
            $activeOutingClientIds = [];
        }

        // Build resident tracking data with geofence status
        $residents = $trackers->map(function ($tracker) use ($clients, $geofences, $activeOutingClientIds) {
            $client = $clients->get($tracker->linked_person_id);
            if (!$client) {
                return null;
            }

            $meta = $tracker->meta ?? [];
            $lat = $meta['lat'] ?? $meta['latitude'] ?? null;
            $lng = $meta['lng'] ?? $meta['longitude'] ?? null;
            $battery = $meta['battery'] ?? $meta['battery_level'] ?? null;

            // Determine geofence status
            $geofenceStatus = 'unknown';
            if ($lat !== null && $lng !== null) {
                $siteId = $client->site_id;
                // Find applicable geofence
                $applicableGeofence = $geofences->first(function ($gf) use ($siteId) {
                    if ($gf->scope === 'resident' && $gf->site_id === $siteId) {
                        return true;
                    }
                    if ($gf->asset && $gf->asset->site_id === $siteId) {
                        return true;
                    }
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
                        // Simple polygon check is complex; default to in_zone if geofence exists
                        $geofenceStatus = 'in_zone';
                    }
                }
            }

            // Check if on outing
            $onOuting = in_array($client->id, $activeOutingClientIds);

            return [
                'id' => $tracker->id,
                'client_id' => $client->id,
                'name' => trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')),
                'preferred_name' => $client->preferred_name,
                'house' => $client->site?->name ?? 'Unknown',
                'site_id' => $client->site_id,
                'photo' => $client->profile_photo_url,
                'tracker_name' => $tracker->name,
                'tracker_serial' => $tracker->serial,
                'status' => $tracker->status ?? 'unknown',
                'last_seen_at' => optional($tracker->last_seen_at)->toISOString(),
                'lat' => $lat,
                'lng' => $lng,
                'battery' => $battery,
                'speed' => $meta['speed'] ?? null,
                'geofence_status' => $geofenceStatus,
                'on_outing' => $onOuting,
            ];
        })->filter()->values();

        // Stats
        $totalTracked = $residents->count();
        $online = $residents->where('status', 'online')->count();
        $offline = $residents->where('status', 'offline')->count();
        $inGeofence = $residents->where('geofence_status', 'in_zone')->count();
        $outsideGeofence = $residents->where('geofence_status', 'outside_zone')->count();
        $lowBattery = $residents->filter(fn ($r) => ($r['battery'] ?? 100) < 20)->count();
        $safetyScore = $totalTracked > 0 ? round(($inGeofence / max($totalTracked, 1)) * 100, 1) : 0;
        $avgBattery = $totalTracked > 0
            ? round($residents->avg(fn ($r) => $r['battery'] ?? 0), 0)
            : 0;

        // Total clients to calculate untracked
        $totalClients = $authorizedClientIds === null
            ? Client::where('status', 'active')->count()
            : count($authorizedClientIds);
        $untracked = max(0, $totalClients - $totalTracked);

        // Recent alerts
        $recentAlerts = [];
        try {
            if (Schema::hasTable('control_room_alerts')) {
                $recentAlerts = ControlRoomAlert::whereIn('source', ['tracker', 'resident_tracker', 'geofence'])
                    ->latest()
                    ->limit(5)
                    ->get()
                    ->map(function ($alert) {
                        $client = $alert->client_id ? Client::find($alert->client_id) : null;
                        $residentName = $client
                            ? trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? ''))
                            : null;

                        return [
                            'id' => $alert->id,
                            'title' => $alert->alert_type ?? 'Alert',
                            'severity' => $alert->severity ?? 'medium',
                            'status' => $alert->status ?? 'open',
                            'created_at' => $alert->created_at?->toISOString(),
                            'resident_name' => $residentName,
                        ];
                    })
                    ->toArray();
            }
        } catch (\Throwable $e) {
            $recentAlerts = [];
        }

        // Active outings
        $activeOutings = [];
        try {
            if (Schema::hasTable('fleet_outings')) {
                $activeOutings = FleetOuting::where('status', 'active')
                    ->with(['clients', 'asset'])
                    ->latest()
                    ->limit(10)
                    ->get()
                    ->map(function ($outing) {
                        return [
                            'id' => $outing->id,
                            'title' => $outing->title ?? 'Outing',
                            'destination' => $outing->destination ?? 'Unknown',
                            'resident_count' => $outing->clients->count(),
                            'departed_at' => $outing->actual_departure?->toISOString() ?? $outing->planned_departure?->toISOString(),
                            'vehicle_name' => $outing->asset?->name ?? null,
                        ];
                    })
                    ->toArray();
            }
        } catch (\Throwable $e) {
            $activeOutings = [];
        }

        // Geofences for map overlay
        $mapGeofences = [];
        try {
            $mapGeofences = $geofences->map(function ($gf) {
                $shape = $gf->shape ?? [];
                $result = [
                    'id' => (string) $gf->id,
                    'name' => $gf->name,
                    'type' => $gf->type ?? 'circle',
                    'color' => $shape['color'] ?? '#8b5cf6',
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
        } catch (\Throwable $e) {
            $mapGeofences = [];
        }

        return Inertia::render('fleet-assets/resident-tracking/index', [
            'residents' => $residents,
            'stats' => [
                'tracked' => $totalTracked,
                'online' => $online,
                'offline' => $offline,
                'untracked' => $untracked,
                'online_percent' => $totalTracked > 0 ? round(($online / $totalTracked) * 100, 1) : 0,
                'in_geofence' => $inGeofence,
                'outside_geofence' => $outsideGeofence,
                'low_battery' => $lowBattery,
                'safety_score' => $safetyScore,
                'avg_battery' => $avgBattery,
            ],
            'recent_alerts' => $recentAlerts,
            'active_outings' => $activeOutings,
            'geofences' => $mapGeofences,
        ]);
    }

    public function assignPage(Request $request)
    {
        $user = $request->user();
        $authorizedClientIds = $this->getAuthorizedClientIds($user);

        // Available clients (not already tracked)
        $trackedClientIds = LocationHardware::query()
            ->where('category', LocationHardware::CATEGORY_TRACKER)
            ->where('linked_person_type', 'client')
            ->whereNotNull('linked_person_id')
            ->pluck('linked_person_id')
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

        // Available tracker devices (unassigned)
        $availableTrackers = LocationHardware::query()
            ->where('category', LocationHardware::CATEGORY_TRACKER)
            ->where(function ($q) {
                $q->whereNull('linked_person_type')
                    ->orWhereNull('linked_person_id');
            })
            ->orderBy('name')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'serial' => $t->serial,
                'mac' => $t->mac,
                'provider' => $t->provider,
                'status' => $t->status,
                'meta' => $t->meta,
            ]);

        // Currently assigned trackers
        $assignedTrackers = LocationHardware::query()
            ->where('category', LocationHardware::CATEGORY_TRACKER)
            ->where('linked_person_type', 'client')
            ->whereNotNull('linked_person_id')
            ->orderBy('name')
            ->get()
            ->map(function ($t) {
                $client = Client::find($t->linked_person_id);
                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'serial' => $t->serial,
                    'provider' => $t->provider,
                    'status' => $t->status,
                    'client_id' => $t->linked_person_id,
                    'client_name' => $client ? trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')) : 'Unknown',
                    'client_house' => $client?->site?->name ?? 'Unknown',
                    'battery' => ($t->meta ?? [])['battery'] ?? null,
                ];
            });

        return Inertia::render('fleet-assets/resident-tracking/assign', [
            'clients' => $availableClients,
            'available_trackers' => $availableTrackers,
            'assigned_trackers' => $assignedTrackers,
        ]);
    }

    public function assign(Request $request)
    {
        $data = $request->validate([
            'tracker_id' => ['required', 'integer', 'exists:location_hardware,id'],
            'client_id' => ['required', 'integer', 'exists:clients,id'],
        ]);

        $tracker = LocationHardware::findOrFail($data['tracker_id']);
        $tracker->update([
            'linked_person_type' => 'client',
            'linked_person_id' => $data['client_id'],
        ]);

        return redirect()->route('fleet-assets.resident-tracking.assign')
            ->with('success', 'Tracker assigned to resident.');
    }

    public function unassign(LocationHardware $tracker)
    {
        $tracker->update([
            'linked_person_type' => null,
            'linked_person_id' => null,
        ]);

        return redirect()->route('fleet-assets.resident-tracking.assign')
            ->with('success', 'Tracker unassigned from resident.');
    }

    public function history(Request $request, Client $client)
    {
        // Find the tracker linked to this client
        $tracker = LocationHardware::query()
            ->where('category', LocationHardware::CATEGORY_TRACKER)
            ->where('linked_person_type', 'client')
            ->where('linked_person_id', $client->id)
            ->first();

        $locations = [];

        if ($tracker && Schema::hasTable('integration_events')) {
            $query = DB::table('integration_events')
                ->where('hardware_id', $tracker->id)
                ->whereNotNull('payload');

            if ($request->filled('date_from')) {
                $query->where('created_at', '>=', $request->input('date_from'));
            }
            if ($request->filled('date_to')) {
                $query->where('created_at', '<=', $request->input('date_to') . ' 23:59:59');
            }

            $events = $query->orderBy('created_at', 'desc')
                ->limit(500)
                ->get();

            $locations = $events->map(function ($event) {
                $payload = is_string($event->payload) ? json_decode($event->payload, true) : (array) $event->payload;
                $lat = $payload['lat'] ?? $payload['latitude'] ?? null;
                $lng = $payload['lng'] ?? $payload['longitude'] ?? null;

                if ($lat === null || $lng === null) {
                    return null;
                }

                return [
                    'lat' => (float) $lat,
                    'lng' => (float) $lng,
                    'timestamp' => $event->created_at,
                    'speed' => $payload['speed'] ?? null,
                    'battery' => $payload['battery'] ?? null,
                    'event_type' => $event->event_type ?? null,
                ];
            })->filter()->values();
        }

        return Inertia::render('fleet-assets/resident-tracking/history', [
            'client' => [
                'id' => $client->id,
                'name' => trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')),
                'house' => $client->site?->name ?? 'Unknown',
                'photo' => $client->profile_photo_url,
            ],
            'tracker' => $tracker ? [
                'id' => $tracker->id,
                'name' => $tracker->name,
                'serial' => $tracker->serial,
                'status' => $tracker->status,
            ] : null,
            'locations' => $locations,
            'filters' => $request->only(['date_from', 'date_to']),
        ]);
    }

    /**
     * Return authorized client IDs for the current user, or null if all clients are accessible.
     */
    private function getAuthorizedClientIds($user): ?array
    {
        // Admins/managers can see all
        if (in_array($user->role, ['admin', 'super-admin', 'manager'])) {
            return null;
        }

        $clientIds = [];

        // Clients assigned via client_user pivot
        if (Schema::hasTable('client_user')) {
            $pivotIds = DB::table('client_user')
                ->where('user_id', $user->id)
                ->pluck('client_id')
                ->toArray();
            $clientIds = array_merge($clientIds, $pivotIds);
        }

        // Clients at same site as user
        if ($user->site_id) {
            $siteClientIds = Client::where('site_id', $user->site_id)
                ->where('status', 'active')
                ->pluck('id')
                ->toArray();
            $clientIds = array_merge($clientIds, $siteClientIds);
        }

        return array_unique($clientIds);
    }
}
