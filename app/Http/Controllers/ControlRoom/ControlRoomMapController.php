<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\AssetGeofence;
use App\Models\ControlRoom\Device;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ControlRoomMapController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        // Build device query - only those with coordinates
        $deviceQuery = Device::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        // Filter by site
        if ($request->filled('site_id') && $request->input('site_id') !== 'all') {
            $deviceQuery->where('site_id', (int) $request->input('site_id'));
        }

        // Filter by device type (vehicle_tracker or personal_tracker)
        if ($request->filled('type') && $request->input('type') !== 'all') {
            $deviceQuery->where('type', $request->input('type'));
        } else {
            // Default to only tracker types for the map view
            $deviceQuery->whereIn('type', [
                Device::TYPE_VEHICLE_TRACKER,
                Device::TYPE_PERSONAL_TRACKER,
            ]);
        }

        // Filter by status
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $deviceQuery->where('status', $request->input('status'));
        }

        // Alert-only filter: only show devices that have unresolved alerts
        if ($request->boolean('alert_only')) {
            $deviceQuery->whereIn('id', function ($q) {
                $q->select('device_id')
                    ->from('control_room_alerts')
                    ->whereNotNull('device_id')
                    ->whereNotIn('status', ['resolved', 'closed']);
            });
        }

        $devices = $deviceQuery->get();

        // Sites with coordinates for site markers
        $siteQuery = Site::query()
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        if ($request->filled('site_id') && $request->input('site_id') !== 'all') {
            $siteQuery->where('id', (int) $request->input('site_id'));
        }

        $sites = $siteQuery->get();

        // Active geofences
        $geofenceQuery = AssetGeofence::query()
            ->where('is_active', true);

        if ($request->filled('site_id') && $request->input('site_id') !== 'all') {
            $geofenceQuery->where('site_id', (int) $request->input('site_id'));
        }

        $geofences = $geofenceQuery->get();

        // Unresolved alerts with location context
        $alertQuery = ControlRoomAlert::query()
            ->unresolved()
            ->where(function ($q) {
                $q->whereNotNull('device_id')
                    ->orWhereNotNull('site_id');
            })
            ->with(['device:id,latitude,longitude,name', 'asset:id,name']);

        if ($request->filled('site_id') && $request->input('site_id') !== 'all') {
            $alertQuery->where('site_id', (int) $request->input('site_id'));
        }

        $alerts = $alertQuery->latest('triggered_at')->limit(200)->get();

        // All sites for filter dropdown
        $allSites = Site::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Stats
        $stats = [
            'total_devices' => Device::whereIn('type', [Device::TYPE_VEHICLE_TRACKER, Device::TYPE_PERSONAL_TRACKER])->count(),
            'online' => Device::whereIn('type', [Device::TYPE_VEHICLE_TRACKER, Device::TYPE_PERSONAL_TRACKER])->where('status', 'online')->count(),
            'offline' => Device::whereIn('type', [Device::TYPE_VEHICLE_TRACKER, Device::TYPE_PERSONAL_TRACKER])->where('status', 'offline')->count(),
            'active_alerts' => ControlRoomAlert::unresolved()->count(),
        ];

        return Inertia::render('control-room/map', [
            'devices' => $devices->map(fn (Device $d) => [
                'id' => $d->id,
                'device_uid' => $d->device_uid,
                'name' => $d->name,
                'type' => $d->type,
                'status' => $d->status,
                'latitude' => (float) $d->latitude,
                'longitude' => (float) $d->longitude,
                'location_description' => $d->location_description,
                'battery_level' => $d->battery_level,
                'last_seen_at' => optional($d->last_seen_at)->toISOString(),
                'vendor' => $d->vendor,
                'model' => $d->getAttribute('model'),
                'site_id' => $d->site_id,
                'client_id' => $d->client_id,
                'asset_id' => $d->asset_id,
            ])->values(),
            'sites' => $sites->map(fn (Site $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'address' => trim(implode(', ', array_filter([
                    $s->address_line_1,
                    $s->suburb,
                    $s->city,
                ]))),
                'latitude' => (float) $s->latitude,
                'longitude' => (float) $s->longitude,
            ])->values(),
            'geofences' => $geofences->map(fn (AssetGeofence $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'type' => $g->type,
                'shape' => $g->shape,
                'breach_type' => $g->breach_type,
                'site_id' => $g->site_id,
            ])->values(),
            'alerts' => $alerts->map(fn (ControlRoomAlert $a) => [
                'id' => $a->id,
                'alert_type' => $a->alert_type,
                'severity' => $a->severity,
                'status' => $a->status,
                'triggered_at' => optional($a->triggered_at)->toISOString(),
                'device_id' => $a->device_id,
                'site_id' => $a->site_id,
                'latitude' => $a->device ? (float) $a->device->latitude : null,
                'longitude' => $a->device ? (float) $a->device->longitude : null,
                'asset_name' => $a->asset?->name,
                'notes' => $a->notes ? substr($a->notes, 0, 120) : null,
            ])->values(),
            'all_sites' => $allSites->map(fn (Site $s) => [
                'id' => $s->id,
                'name' => $s->name,
            ])->values(),
            'stats' => $stats,
            'filters' => $request->only(['site_id', 'type', 'status', 'alert_only']),
        ]);
    }
}
