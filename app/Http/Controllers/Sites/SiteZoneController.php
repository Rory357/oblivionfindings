<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteFacilityZone;
use Illuminate\Http\Request;

class SiteZoneController extends Controller
{
    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $zones = SiteFacilityZone::where('site_id', $site->id)
            ->orderBy('name')
            ->get();

        return inertia('sites/zones/index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
            ],
            'zones' => $zones->map(fn($z) => [
                'id' => $z->id,
                'name' => $z->name,
                'description' => $z->description,
                'zone_type' => $z->zone_type,
                'is_active' => $z->is_active,
            ]),
        ]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'zone_type' => 'nullable|string|max:100',
        ]);

        SiteFacilityZone::create([
            ...$validated,
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Zone added.');
    }

    public function update(Request $request, Site $site, SiteFacilityZone $zone)
    {
        $this->authorize('update', $site);
        abort_unless($zone->site_id === $site->id, 404);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'zone_type' => 'nullable|string|max:100',
        ]);

        $zone->update($validated);

        return redirect()->back()->with('success', 'Zone updated.');
    }

    public function destroy(Request $request, Site $site, SiteFacilityZone $zone)
    {
        $this->authorize('update', $site);
        abort_unless($zone->site_id === $site->id, 404);

        $zone->update(['is_active' => false]);

        return redirect()->back()->with('success', 'Zone deactivated.');
    }
}
