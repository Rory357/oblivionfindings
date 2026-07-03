<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WanderingAlertController extends Controller
{
    /**
     * The standalone wandering-alerts page is retired — it now lives as the
     * "Wandering alerts" tab on the resident-tracking index. The redirect
     * preserves the status filter / pagination query so context isn't lost.
     */
    public function index(Request $request)
    {
        return redirect()->route(
            'fleet-assets.resident-tracking.index',
            array_merge($request->query(), ['tab' => 'wandering']),
        );
    }
}
