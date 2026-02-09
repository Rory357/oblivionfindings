<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteHoResource;
use Illuminate\Http\Request;

class SiteResourceController extends Controller
{
    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $resources = SiteHoResource::where('site_id', $site->id)
            ->orderBy('name')
            ->get();

        return inertia('sites/resources/index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
            ],
            'resources' => $resources->map(fn($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'resource_type' => $r->resource_type,
                'capacity' => $r->capacity,
                'amenities' => $r->amenities,
                'calendar_email' => $r->calendar_email,
                'is_bookable' => $r->is_bookable,
                'is_active' => $r->is_active,
            ]),
        ]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'resource_type' => 'required|in:boardroom,training_room,meeting_room,other',
            'capacity' => 'nullable|integer|min:1',
            'amenities' => 'nullable|array',
            'calendar_email' => 'nullable|email',
        ]);

        SiteHoResource::create([
            ...$validated,
            'site_id' => $site->id,
            'is_active' => true,
            'is_bookable' => true,
        ]);

        return redirect()->back()->with('success', 'Resource added.');
    }

    public function update(Request $request, Site $site, SiteHoResource $resource)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'resource_type' => 'required|in:boardroom,training_room,meeting_room,other',
            'capacity' => 'nullable|integer|min:1',
            'amenities' => 'nullable|array',
            'calendar_email' => 'nullable|email',
        ]);

        $resource->update($validated);

        return redirect()->back()->with('success', 'Resource updated.');
    }

    public function destroy(Request $request, Site $site, SiteHoResource $resource)
    {
        $this->authorize('update', $site);

        $resource->update(['is_active' => false]);

        return redirect()->back()->with('success', 'Resource deactivated.');
    }
}
