<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LiveMapController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canDo('fleet.viewAny') || $request->user()->canDo('assets.geofences.manage'), 403);

        return redirect('/fleet-assets/geofences?tab=map');
    }
}
