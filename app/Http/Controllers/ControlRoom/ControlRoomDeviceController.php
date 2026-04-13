<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\ControlRoom\Device;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ControlRoomDeviceController extends Controller
{
    /**
     * List all devices with filtering and stats.
     * Enriches each device with canonical Security & Devices data where linked.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $query = Device::query()
            ->with([
                'signalSource:id,name,status',
                'canonicalDevice:id,device_uid,name,domain,category,subcategory,status,health_status,provider,manufacturer,model,serial_number,mac_address,battery_level,last_seen_at',
            ]);

        // Join site for site name.
        $query->leftJoin('sites', 'control_room_devices.site_id', '=', 'sites.id')
            ->select('control_room_devices.*', 'sites.name as site_name');

        if ($request->filled('type')) {
            $query->where('control_room_devices.type', $request->input('type'));
        }
        if ($request->filled('status')) {
            $query->where('control_room_devices.status', $request->input('status'));
        }
        if ($request->filled('site_id')) {
            $query->where('control_room_devices.site_id', $request->input('site_id'));
        }
        if ($request->boolean('low_battery')) {
            $query->lowBattery(20);
        }

        $query->orderByDesc('control_room_devices.last_seen_at');

        $devices = $query->paginate(48)->through(fn (Device $device) => $this->mapDeviceForList($device));

        // Stats.
        $total = Device::count();
        $online = Device::where('status', 'online')->count();
        $offline = Device::where('status', 'offline')->count();
        $lowBattery = Device::lowBattery(20)->count();

        $sites = Site::orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name]);

        return Inertia::render('control-room/devices/index', [
            'devices' => $devices,
            'stats' => [
                'total' => $total,
                'online' => $online,
                'offline' => $offline,
                'low_battery' => $lowBattery,
            ],
            'filters' => [
                'type' => $request->input('type', ''),
                'status' => $request->input('status', ''),
                'site_id' => $request->input('site_id', ''),
                'low_battery' => $request->boolean('low_battery'),
            ],
            'sites' => $sites,
            'device_types' => Device::types(),
        ]);
    }

    /**
     * Device detail with recent signals and linked alerts.
     * Enriches with canonical Security & Devices data where linked.
     */
    public function show(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);

        $device->load([
            'signalSource:id,name,status,vendor',
            'canonicalDevice:id,device_uid,name,domain,category,subcategory,status,health_status,provider,manufacturer,model,serial_number,mac_address,imei,ip_address,firmware_version,battery_level,last_seen_at,notes',
        ]);

        $site = $device->site_id ? Site::find($device->site_id, ['id', 'name']) : null;

        $client = null;
        if ($device->client_id) {
            $client = \App\Models\Client::find($device->client_id, ['id', 'first_name', 'last_name']);
        }

        $asset = null;
        if ($device->asset_id) {
            $asset = \App\Models\Asset::find($device->asset_id, ['id', 'name', 'asset_tag']);
        }

        // Recent signals (last 50).
        $signals = $device->signals()
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'signal_type_code' => $s->signal_type_code,
                'severity_hint' => $s->severity_hint,
                'occurred_at' => $s->occurred_at?->toISOString(),
                'status' => $s->status,
                'payload' => $s->payload ? array_slice($s->payload, 0, 5) : null,
            ]);

        // Linked alerts (last 20).
        $alerts = ControlRoomAlert::where('device_id', $device->id)
            ->orderByDesc('triggered_at')
            ->limit(20)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'alert_type' => $a->alert_type,
                'severity' => $a->severity,
                'status' => $a->status,
                'triggered_at' => $a->triggered_at?->toISOString(),
            ]);

        // Build canonical device context (additive — safe fallback if not linked).
        $canonical = $device->canonicalDevice;

        return Inertia::render('control-room/devices/show', [
            'device' => [
                'id' => $device->id,
                'name' => $device->name,
                'device_uid' => $device->device_uid,
                'type' => $device->type,
                'type_label' => Device::types()[$device->type] ?? ucfirst(str_replace('_', ' ', $device->type)),
                'vendor' => $device->vendor,
                'model' => $device->model,
                'status' => $device->status,
                'battery_level' => $device->battery_level,
                'last_seen_at' => $device->last_seen_at?->toISOString(),
                'last_signal_at' => $device->last_signal_at?->toISOString(),
                'is_stale' => $device->isOnline() && $device->isStale(10),
                'latitude' => $device->latitude ? (float) $device->latitude : null,
                'longitude' => $device->longitude ? (float) $device->longitude : null,
                'location_description' => $device->location_description,
                'config' => $device->config,
                'signal_source' => $device->signalSource ? [
                    'id' => $device->signalSource->id,
                    'name' => $device->signalSource->name,
                    'status' => $device->signalSource->status,
                    'vendor' => $device->signalSource->vendor,
                ] : null,
                'site' => $site ? ['id' => $site->id, 'name' => $site->name] : null,
                'client' => $client ? [
                    'id' => $client->id,
                    'name' => trim($client->first_name . ' ' . $client->last_name),
                ] : null,
                'asset' => $asset ? [
                    'id' => $asset->id,
                    'name' => $asset->name,
                    'asset_tag' => $asset->asset_tag,
                ] : null,
                // Canonical device data (additive — null if not linked).
                'canonical' => $canonical ? [
                    'id' => $canonical->id,
                    'device_uid' => $canonical->device_uid,
                    'name' => $canonical->name,
                    'domain' => $canonical->domain,
                    'category' => $canonical->category,
                    'subcategory' => $canonical->subcategory,
                    'status' => $canonical->status?->value,
                    'health_status' => $canonical->health_status?->value,
                    'provider' => $canonical->provider,
                    'manufacturer' => $canonical->manufacturer,
                    'model' => $canonical->model,
                    'serial_number' => $canonical->serial_number,
                    'mac_address' => $canonical->mac_address,
                    'imei' => $canonical->imei,
                    'ip_address' => $canonical->ip_address,
                    'firmware_version' => $canonical->firmware_version,
                    'battery_level' => $canonical->battery_level,
                    'last_seen_at' => $canonical->last_seen_at?->toISOString(),
                    'detail_url' => "/security-devices/devices/{$canonical->id}",
                ] : null,
            ],
            'signals' => $signals,
            'alerts' => $alerts,
        ]);
    }

    private function mapDeviceForList(Device $device): array
    {
        $canonical = $device->canonicalDevice;

        return [
            'id' => $device->id,
            'name' => $device->name,
            'device_uid' => $device->device_uid,
            'type' => $device->type,
            'type_label' => Device::types()[$device->type] ?? ucfirst(str_replace('_', ' ', $device->type)),
            'vendor' => $device->vendor,
            'model' => $device->model,
            'status' => $device->status,
            'battery_level' => $device->battery_level,
            'last_seen_at' => $device->last_seen_at?->toISOString(),
            'last_signal_at' => $device->last_signal_at?->toISOString(),
            'is_stale' => $device->isOnline() && $device->isStale(10),
            'location_description' => $device->location_description,
            'site_id' => $device->site_id,
            'site_name' => $device->site_name,
            'signal_source_name' => $device->signalSource?->name,
            // Canonical enrichment (safe fallback to null).
            'canonical_id' => $canonical?->id,
            'canonical_device_uid' => $canonical?->device_uid,
            'canonical_domain' => $canonical?->domain,
            'canonical_category' => $canonical?->category,
            'canonical_health_status' => $canonical?->health_status?->value,
            'canonical_detail_url' => $canonical ? "/security-devices/devices/{$canonical->id}" : null,
        ];
    }
}
