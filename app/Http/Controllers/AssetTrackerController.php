<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetTracker;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * @deprecated Device pairing now uses Security & Devices module:
 * - Fleet pair/unpair: FleetAssets\DeviceController (uses DeviceLinkService)
 * - Resident assign/unassign: FleetAssets\ResidentTrackingController (uses DeviceAssignmentService)
 *
 * This controller operates on legacy AssetTracker and should not receive new traffic.
 * Routes in routes/assets.php that reference this controller should be retired.
 */
class AssetTrackerController extends Controller
{
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

        AuditLogger::log('assets.tracker.paired', $asset, [
            'tracker_id' => $tracker->id,
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

        AuditLogger::log('assets.tracker.unpaired', $asset, [
            'tracker_id' => $tracker->id,
        ]);

        return back()->with('success', 'Tracker unpaired.');
    }
}
