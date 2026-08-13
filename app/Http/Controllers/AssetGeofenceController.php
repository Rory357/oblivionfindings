<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Rules\GeofenceShape;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class AssetGeofenceController extends Controller
{
    public function store(Request $request, Asset $asset)
    {
        $this->authorize('manageGeofences', $asset);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', 'in:circle,polygon'],
            'shape' => ['required', 'array', new GeofenceShape],
            'breach_type' => ['required', 'in:soft,hard'],
            'time_rules' => ['nullable', 'array'],
            'is_active' => ['boolean'],
        ]);

        $geofence = AssetGeofence::create([
            'asset_id' => $asset->id,
            'name' => $data['name'],
            'type' => $data['type'],
            'shape' => $data['shape'],
            'breach_type' => $data['breach_type'],
            'time_rules' => $data['time_rules'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);

        AuditLogger::log('assets.geofence.created', $asset, [
            'geofence_id' => $geofence->id,
        ]);

        return back()->with('success', 'Geofence created.');
    }

    public function destroy(Request $request, Asset $asset, AssetGeofence $geofence)
    {
        $this->authorize('manageGeofences', $asset);

        if ($geofence->asset_id !== $asset->id) {
            abort(404);
        }

        $geofence->delete();

        AuditLogger::log('assets.geofence.deleted', $asset, [
            'geofence_id' => $geofence->id,
        ]);

        return back()->with('success', 'Geofence removed.');
    }
}
