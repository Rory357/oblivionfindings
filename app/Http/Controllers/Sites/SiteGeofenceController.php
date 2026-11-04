<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\AssetGeofence;
use App\Models\Site;
use App\Services\Fleet\BoundaryService;
use App\Support\SiteGeofenceLimits;
use Illuminate\Http\Request;

/** Site entry point delegates to the canonical versioned commands; never selects the first area. */
final class SiteGeofenceController extends Controller
{
    public function __construct(private readonly BoundaryService $boundaries) {}

    public function store(Request $r, Site $site)
    {
        $this->site($r, $site);
        $this->siteRadius($r);
        $b = $this->boundaries->save($r->user(), array_replace($r->all(), ['site_id' => $site->id]));

        return back()->with('success', 'Shared boundary created. Monitoring remains inactive.');
    }

    public function update(Request $r, Site $site, AssetGeofence $geofence)
    {
        $this->site($r, $site);
        abort_unless((int) $geofence->site_id === (int) $site->id && $geofence->asset_id === null, 404);
        $this->siteRadius($r);
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

    /** A Site's own circle is 10 m to 2,000 m, the same as Add site and Edit site. */
    private function siteRadius(Request $r): void
    {
        if ($r->input('geometry.type') !== 'circle') {
            return;
        }

        $r->validate([
            'geometry.radius_m' => ['required', 'numeric', 'min:'.SiteGeofenceLimits::MIN_RADIUS_M, 'max:'.SiteGeofenceLimits::MAX_RADIUS_M],
        ], [
            'geometry.radius_m.min' => 'Make the Site geofence radius at least '.SiteGeofenceLimits::MIN_RADIUS_M.' m.',
            'geometry.radius_m.max' => 'Make the Site geofence radius '.number_format(SiteGeofenceLimits::MAX_RADIUS_M).' m or less.',
        ]);
    }
}
