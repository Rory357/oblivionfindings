<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FleetTripController extends Controller
{
    public function show(Request $request, FleetTrip $trip)
    {
        return Inertia::render('fleet-management/trip', [
            'trip' => [
                'id' => $trip->id,
                'asset_id' => $trip->asset_id,
                'started_at' => optional($trip->started_at)->toISOString(),
                'ended_at' => optional($trip->ended_at)->toISOString(),
                'distance_km' => $trip->distance_km,
                'status' => $trip->status,
            ],
        ]);
    }

    public function playback(Request $request, FleetTrip $trip)
    {
        $query = FleetTelemetryEvent::query()
            ->where('asset_id', $trip->asset_id)
            ->where('consent_blocked', false)
            ->orderBy('occurred_at');

        if ($trip->started_at) {
            $query->where('occurred_at', '>=', $trip->started_at);
        }
        if ($trip->ended_at) {
            $query->where('occurred_at', '<=', $trip->ended_at);
        }

        $points = $query->limit(2000)->get(['occurred_at', 'latitude', 'longitude', 'speed_kph']);

        return response()->json([
            'trip_id' => $trip->id,
            'points' => $points->map(fn($p) => [
                'occurred_at' => optional($p->occurred_at)->toISOString(),
                'lat' => $p->latitude,
                'lng' => $p->longitude,
                'speed_kph' => $p->speed_kph,
            ])->values(),
        ]);
    }
}
