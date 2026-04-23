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
        abort_unless($auth && $this->canAccessGeofences($auth), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));

        $zones = GeofenceZone::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name'])
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->paginate(20)
            ->through(fn (GeofenceZone $zone) => [
                'id' => $zone->id,
                'name' => $zone->name,
                'radius_meters' => (float) ($zone->radius_meters ?? $zone->radius ?? 0),
                'latitude' => (float) ($zone->latitude ?? 0),
                'longitude' => (float) ($zone->longitude ?? 0),
                'is_active' => (bool) $zone->is_active,
                'client' => $zone->client ? [
                    'id' => $zone->client->id,
                    'first_name' => $zone->client->first_name,
                    'last_name' => $zone->client->last_name,
                ] : null,
                'site_name' => $zone->site_name ?? null,
            ])
            ->withQueryString();

        return inertia('operations/geofences/Index', [
            'geofences' => $zones,
            'filters' => [
                'q' => $filters['q'] ?? null,
            ],
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessGeofences($auth), 403);

        $clients = \App\Models\Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->select('id', 'first_name', 'last_name')
            ->orderBy('last_name')
            ->get();

        return inertia('operations/geofences/Create', [
            'clients' => $clients,
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessGeofences($auth), 403);

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
        abort_unless($auth && $this->canAccessGeofences($auth), 403);

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
        abort_unless($auth && $this->canAccessGeofences($auth), 403);

        $zone = GeofenceZone::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($zone);

        $zone->delete();

        return redirect()->back()->with('success', 'Geofence zone deleted.');
    }

    private function canAccessGeofences($auth): bool
    {
        return $auth->canDo('geofences.viewAny')
            || $auth->canDo('geofences.create')
            || $auth->canDo('geofences.edit')
            || $auth->canDo('geofences.delete')
            || $auth->canDo('evv.viewAny');
    }
}
