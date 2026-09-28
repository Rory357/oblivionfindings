<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Services\Fleet\GoogleMapCapabilities;
use Illuminate\Http\Request;

class MapCapabilityController extends Controller
{
    public function __invoke(Request $request, string $capability, GoogleMapCapabilities $google)
    {
        abort_unless(in_array($capability, ['places', 'geocoding', 'routes'], true), 404);
        $rules = match ($capability) {
            'places' => ['q' => 'required|string|min:3|max:200'],
            'geocoding' => ['lat' => 'required|numeric|between:-90,90', 'lng' => 'required|numeric|between:-180,180'],
            'routes' => ['origin' => 'required|array:lat,lng', 'destination' => 'required|array:lat,lng', 'origin.lat' => 'required|numeric|between:-90,90', 'origin.lng' => 'required|numeric|between:-180,180', 'destination.lat' => 'required|numeric|between:-90,90', 'destination.lng' => 'required|numeric|between:-180,180'],
        };
        $data = $request->validate($rules);

        // The caller must explicitly supply a query or coordinates. Never look up personal records here.
        return response()->json($google->request($capability, $data, (int) $request->user()->id))->header('Cache-Control', 'private, no-store');
    }
}
