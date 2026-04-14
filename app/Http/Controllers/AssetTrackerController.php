<?php

namespace App\Http\Controllers;

use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Services\AuditLogger;
use App\Services\Fleet\FleetDeviceRuntimeService;
use Illuminate\Http\Request;

/**
 * @deprecated Device pairing now uses Security & Devices module:
 * - Fleet pair/unpair: FleetAssets\DeviceController (uses DeviceLinkService)
 * - Resident assign/unassign: FleetAssets\ResidentTrackingController (uses DeviceAssignmentService)
 *
 * This controller still serves the legacy tracker pair/unpair UI on the asset
 * detail page while fleet telemetry and consent-linked AssetTracker paths
 * remain in place. It must keep the canonical Device + DeviceAssetLink view in
 * sync for compatibility, but it should not become a new source of truth.
 */
class AssetTrackerController extends Controller
{
    public function __construct(
        private readonly FleetDeviceRuntimeService $deviceRuntime,
    ) {}

    public function store(Request $request, Asset $asset)
    {
        $this->authorize('view', $asset);
        abort_unless($request->user()?->canDo('assets.trackers.manage'), 403);

        $data = $request->validate([
            'vendor' => ['required', 'string', 'max:80'],
            'device_uid' => ['required', 'string', 'max:120'],
            'imei' => ['nullable', 'string', 'max:120'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'consent_id' => ['nullable', 'integer', 'exists:client_consents,id'],
            'vendor_metadata' => ['nullable', 'array'],
        ]);

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
        $device = $this->deviceRuntime->ensureCanonicalDeviceForTracker($tracker);

        $activeLink = DeviceAssetLink::query()
            ->where('device_id', $device->id)
            ->whereNull('unlinked_at')
            ->first();

        if ($activeLink && $activeLink->asset_id !== $asset->id) {
            $activeLink->update(['unlinked_at' => now()]);
            $activeLink = null;
        }

        if (!$activeLink) {
            DeviceAssetLink::create([
                'device_id' => $device->id,
                'asset_id' => $asset->id,
                'link_type' => LinkType::InstalledIn,
                'linked_at' => $tracker->paired_at ?? now(),
            ]);
        }

        AuditLogger::log('assets.tracker.paired', $asset, [
            'tracker_id' => $tracker->id,
            'device_id' => $device->id,
            'vendor' => $tracker->vendor,
            'device_uid' => $tracker->device_uid,
        ]);

        return back()->with('success', 'Tracker paired.');
    }

    public function unpair(Request $request, Asset $asset, AssetTracker $tracker)
    {
        $this->authorize('view', $asset);
        abort_unless($request->user()?->canDo('assets.trackers.manage'), 403);

        if ($tracker->asset_id !== $asset->id) {
            abort(404);
        }

        $tracker->update([
            'status' => 'unpaired',
            'unpaired_at' => now(),
        ]);

        $deviceLink = DeviceAssetLink::query()
            ->whereHas('device', fn ($query) => $query->where('legacy_asset_tracker_id', $tracker->id))
            ->where('asset_id', $asset->id)
            ->whereNull('unlinked_at')
            ->latest('linked_at')
            ->first();

        if ($deviceLink) {
            $deviceLink->update(['unlinked_at' => now()]);
        }

        AuditLogger::log('assets.tracker.unpaired', $asset, [
            'tracker_id' => $tracker->id,
        ]);

        return back()->with('success', 'Tracker unpaired.');
    }
}
