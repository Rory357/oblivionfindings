<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\LocationHardware;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeviceController extends Controller
{
    public function index(Request $request)
    {
        // CSV export
        if ($request->input('export') === 'csv') {
            $allTrackers = AssetTracker::query()->with('asset:id,name,asset_tag')->orderByDesc('last_seen_at')->get();
            return response()->streamDownload(function () use ($allTrackers) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Vendor', 'Device UID', 'IMEI', 'Serial Number', 'Paired Asset', 'Status', 'Last Seen']);
                foreach ($allTrackers as $t) {
                    fputcsv($handle, [
                        $t->vendor, $t->device_uid, $t->imei, $t->serial_number,
                        $t->asset?->name ?? '', $t->status,
                        optional($t->last_seen_at)->format('Y-m-d H:i:s') ?? '',
                    ]);
                }
                fclose($handle);
            }, 'devices-export.csv');
        }

        // Sorting
        $allowedSorts = ['vendor', 'device_uid', 'status', 'last_seen_at'];
        $sort = $request->input('sort', 'last_seen_at');
        $direction = $request->input('direction', 'desc');
        if (!in_array($sort, $allowedSorts)) $sort = 'last_seen_at';
        if (!in_array($direction, ['asc', 'desc'])) $direction = 'desc';

        // Asset trackers
        $trackers = AssetTracker::query()
            ->with('asset:id,name,asset_tag')
            ->orderBy($sort, $direction)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'source' => 'asset_tracker',
                'vendor' => $t->vendor,
                'device_uid' => $t->device_uid,
                'imei' => $t->imei,
                'serial_number' => $t->serial_number,
                'status' => $t->status,
                'last_seen_at' => optional($t->last_seen_at)->toISOString(),
                'paired_at' => optional($t->paired_at)->toISOString(),
                'asset' => $t->asset ? [
                    'id' => $t->asset->id,
                    'name' => $t->asset->name,
                    'asset_tag' => $t->asset->asset_tag,
                ] : null,
            ]);

        // Location hardware trackers
        $hardwareTrackers = LocationHardware::query()
            ->where('category', 'tracker')
            ->with('linkedAsset:id,name,asset_tag')
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'source' => 'location_hardware',
                'vendor' => $h->provider,
                'device_uid' => $h->serial,
                'imei' => $h->mac,
                'serial_number' => $h->serial,
                'status' => $h->status,
                'last_seen_at' => optional($h->last_seen_at)->toISOString(),
                'paired_at' => null,
                'asset' => $h->linkedAsset ? [
                    'id' => $h->linkedAsset->id,
                    'name' => $h->linkedAsset->name,
                    'asset_tag' => $h->linkedAsset->asset_tag,
                ] : null,
            ]);

        $allDevices = $trackers->concat($hardwareTrackers)->values();

        return Inertia::render('fleet-assets/devices/index', [
            'devices' => $allDevices,
        ]);
    }

    public function show(Request $request, AssetTracker $tracker)
    {
        $tracker->load([
            'asset:id,name,asset_tag,category,status',
            'telemetrySnapshots' => fn ($q) => $q->latest()->limit(20),
        ]);

        return Inertia::render('fleet-assets/devices/show', [
            'tracker' => [
                'id' => $tracker->id,
                'vendor' => $tracker->vendor,
                'device_uid' => $tracker->device_uid,
                'imei' => $tracker->imei,
                'serial_number' => $tracker->serial_number,
                'status' => $tracker->status,
                'paired_at' => optional($tracker->paired_at)->toISOString(),
                'unpaired_at' => optional($tracker->unpaired_at)->toISOString(),
                'last_seen_at' => optional($tracker->last_seen_at)->toISOString(),
                'vendor_metadata' => $tracker->vendor_metadata,
                'asset' => $tracker->asset ? [
                    'id' => $tracker->asset->id,
                    'name' => $tracker->asset->name,
                    'asset_tag' => $tracker->asset->asset_tag,
                    'category' => $tracker->asset->category,
                    'status' => $tracker->asset->status,
                ] : null,
                'telemetry_snapshots' => $tracker->telemetrySnapshots->map(fn ($s) => [
                    'id' => $s->id,
                    'created_at' => optional($s->created_at)->toISOString(),
                    'data' => $s->data ?? null,
                ])->values(),
            ],
        ]);
    }

    public function pair(Request $request)
    {
        $data = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
            'vendor' => ['required', 'string', 'max:80'],
            'device_uid' => ['required', 'string', 'max:120'],
            'imei' => ['nullable', 'string', 'max:120'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'consent_id' => ['nullable', 'integer', 'exists:client_consents,id'],
            'vendor_metadata' => ['nullable', 'array'],
        ]);

        $asset = Asset::findOrFail($data['asset_id']);

        $existing = AssetTracker::query()
            ->where('vendor', $data['vendor'])
            ->where('device_uid', $data['device_uid'])
            ->first();

        if ($existing && $existing->asset_id !== $asset->id && $existing->status === 'paired') {
            return back()->withErrors(['device_uid' => 'Tracker is already paired to another asset.']);
        }

        $tracker = $existing ?? new AssetTracker();
        $tracker->fill([
            'asset_id' => $asset->id,
            'vendor' => $data['vendor'],
            'device_uid' => $data['device_uid'],
            'imei' => $data['imei'] ?? null,
            'serial_number' => $data['serial_number'] ?? null,
            'consent_id' => $data['consent_id'] ?? null,
            'vendor_metadata' => $data['vendor_metadata'] ?? null,
            'status' => 'paired',
            'paired_at' => now(),
            'unpaired_at' => null,
        ]);
        $tracker->save();

        AuditLogger::log('assets.tracker.paired', $asset, [
            'tracker_id' => $tracker->id,
            'vendor' => $tracker->vendor,
            'device_uid' => $tracker->device_uid,
        ]);

        return back()->with('success', 'Tracker paired successfully.');
    }

    public function unpair(Request $request, AssetTracker $tracker)
    {
        $tracker->update([
            'status' => 'unpaired',
            'unpaired_at' => now(),
        ]);

        AuditLogger::log('assets.tracker.unpaired', $tracker->asset, [
            'tracker_id' => $tracker->id,
        ]);

        return back()->with('success', 'Tracker unpaired successfully.');
    }
}
