<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Services\Fleet\BoundaryService;
use Illuminate\Http\Request;

final class AssetGeofenceController extends Controller
{
    public function store(Request $request, Asset $asset, BoundaryService $boundaries)
    {
        $this->authorize('manageGeofences', $asset);
        $site = $asset->site_id ?? $asset->home_site_id;
        abort_unless($site, 422, 'Assign an approved owning site before creating a shared boundary.');
        $b = $boundaries->save($request->user(), array_replace($request->all(), ['site_id' => $site]));

        return redirect('/fleet-assets/geofences?tab=boundaries&selected='.$b->id)
            ->with('success', 'Shared boundary saved. Add a purpose rule to link this asset; monitoring remains inactive.');
    }

    public function destroy(Request $request, Asset $asset, AssetGeofence $geofence, BoundaryService $boundaries)
    {
        $this->authorize('manageGeofences', $asset);
        abort_unless((int) $geofence->asset_id === (int) $asset->id, 404);
        $data = $request->validate(['expected_revision' => ['required', 'integer'], 'reason' => ['required', 'string']]);
        $boundaries->retire($request->user(), $geofence->id, $data['expected_revision'], $data['reason']);

        return back()->with('success', 'Shared boundary retired. Prior evidence retained.');
    }
}
