<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\AssetGeofence;
use App\Models\Site;
use App\Services\Fleet\BoundaryService;
use Illuminate\Http\Request;

/** Site entry point delegates to the canonical versioned commands; never selects the first area. */
final class SiteGeofenceController extends Controller
{
    public function __construct(private readonly BoundaryService $boundaries) {}

    public function store(Request $r, Site $site)
    {
        $this->site($r, $site);
        $b = $this->boundaries->save($r->user(), array_replace($r->all(), ['site_id' => $site->id]));

        return back()->with('success', 'Shared boundary created. Monitoring remains inactive.');
    }

    public function update(Request $r, Site $site, AssetGeofence $geofence)
    {
        $this->site($r, $site);
        abort_unless((int) $geofence->site_id === (int) $site->id && $geofence->asset_id === null, 404);
        $this->boundaries->save($r->user(), array_replace($r->all(), ['site_id' => $site->id]), $geofence->id);

        return back()->with('success', 'Shared boundary updated. Existing purpose settings retained.');
    }

    public function destroy(Request $r, Site $site, AssetGeofence $geofence)
    {
        $this->site($r, $site);
        abort_unless((int) $geofence->site_id === (int) $site->id && $geofence->asset_id === null, 404);
        $data = $r->validate(['expected_revision' => ['required', 'integer'], 'reason' => ['required', 'string']]);
        $this->boundaries->retire($r->user(), $geofence->id, $data['expected_revision'], $data['reason']);

        return back()->with('success', 'Boundary retired. Geometry and evidence retained.');
    }

    private function site(Request $r, Site $site): void
    {
        $actor = $this->boundaries->actor($r->user(), true);
        abort_unless($this->boundaries->access->accessibleSites($actor)->whereKey($site->id)->exists(), 404);
    }
}
