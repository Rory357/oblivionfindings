<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\GeofenceZone;
use Illuminate\Http\Request;

class GeofenceController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('geofences.viewAny'), 403);

        $zones = GeofenceZone::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/geofences/Index', [
            'geofences' => $zones,
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('geofences.create'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['required', 'numeric', 'min:1'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        GeofenceZone::create([
            'organization_id' => $auth->organization_id,
            'name' => $data['name'],
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'radius' => $data['radius'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $auth->id,
        ]);

        return redirect()->back()->with('success', 'Geofence zone created.');
    }

    public function update(Request $request, $zone)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('geofences.edit'), 403);

        $zone = GeofenceZone::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($zone);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
            'radius' => ['sometimes', 'required', 'numeric', 'min:1'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $zone->update($data);

        return redirect()->back()->with('success', 'Geofence zone updated.');
    }

    public function destroy(Request $request, $zone)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('geofences.delete'), 403);

        $zone = GeofenceZone::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($zone);

        $zone->delete();

        return redirect()->back()->with('success', 'Geofence zone deleted.');
    }
}
