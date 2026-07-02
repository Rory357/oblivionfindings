<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class LiveMapController extends Controller
{
    public function __invoke(Request $request)
    {
        $hasFleetFields = Schema::hasColumn('assets', 'home_site_id');

        $eagerLoads = ['fleetState'];
        if ($hasFleetFields) {
            $eagerLoads[] = 'homeSite';
        }

        $selectColumns = ['id', 'name', 'asset_tag', 'category', 'status'];
        if ($hasFleetFields) {
            $selectColumns[] = 'home_site_id';
        }

        $vehicles = Asset::vehicles()
            ->with($eagerLoads)
            ->get($selectColumns);

        $vehicleMarkers = $vehicles->filter(fn ($v) => $v->fleetState)
            ->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'asset_tag' => $v->asset_tag,
                'type' => 'vehicle',
                'lat' => $v->fleetState->latitude,
                'lng' => $v->fleetState->longitude,
                'status' => $v->fleetState->status,
                'speed_kph' => $v->fleetState->speed_kph,
                'heading_deg' => $v->fleetState->heading_deg,
                'last_seen_at' => optional($v->fleetState->last_seen_at)->toISOString(),
                'consent_blocked' => (bool) $v->fleetState->consent_blocked,
                'home_site' => $hasFleetFields && $v->homeSite ? [
                    'id' => $v->homeSite->id,
                    'name' => $v->homeSite->name,
                ] : null,
            ])->values();

        $houses = Site::query()
            ->where('type', 'house')
            ->whereNotNull('latitude')
            ->get(['id', 'name', 'address_line_1', 'latitude', 'longitude'])
            ->map(fn ($h) => [
                'id' => $h->id,
                'name' => $h->name,
                'type' => 'house',
                'address' => $h->address_line_1,
                'lat' => $h->latitude,
                'lng' => $h->longitude,
            ])->values();

        $geofences = AssetGeofence::query()
            ->with('asset:id,name')
            ->where('is_active', true)
            ->get()
            ->map(fn ($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'type' => $g->type,
                'breach_type' => $g->breach_type,
                'shape' => $g->shape,
                'asset' => $g->asset ? [
                    'id' => $g->asset->id,
                    'name' => $g->asset->name,
                ] : null,
            ])->values();

        // Open alerts for the hero band — single COUNT query.
        $openAlerts = ControlRoomAlert::query()
            ->whereNotIn('status', ['closed', 'resolved'])
            ->count();

        return Inertia::render('fleet-assets/map', [
            'vehicle_markers' => $vehicleMarkers,
            'house_markers' => $houses,
            'geofences' => $geofences,
            'open_alerts' => $openAlerts,
        ]);
    }
}
