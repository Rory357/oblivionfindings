<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetSignal;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
use App\Models\FleetVehicleStateSnapshot;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FleetVehicleController extends Controller
{
    public function show(Request $request, Asset $asset)
    {
        $asset->load([
            'trackers' => fn($q) => $q->where('status', 'paired'),
            'geofences',
        ]);

        $state = FleetVehicleStateSnapshot::query()
            ->where('asset_id', $asset->id)
            ->first();

        $telemetry = FleetTelemetryEvent::query()
            ->where('asset_id', $asset->id)
            ->latest('occurred_at')
            ->limit(100)
            ->get();

        $signals = FleetSignal::query()
            ->where('asset_id', $asset->id)
            ->latest('occurred_at')
            ->limit(50)
            ->get();

        $trips = FleetTrip::query()
            ->where('asset_id', $asset->id)
            ->latest('started_at')
            ->limit(20)
            ->get();

        return Inertia::render('fleet-management/vehicle', [
            'asset' => [
                'id' => $asset->id,
                'name' => $asset->name,
                'asset_tag' => $asset->asset_tag,
                'category' => $asset->category,
                'status' => $asset->status,
                'trackers' => $asset->trackers->map(fn($t) => [
                    'id' => $t->id,
                    'vendor' => $t->vendor,
                    'device_uid' => $t->device_uid,
                    'last_seen_at' => optional($t->last_seen_at)->toISOString(),
                ])->values(),
            ],
            'state' => $state ? [
                'status' => $state->status,
                'last_seen_at' => optional($state->last_seen_at)->toISOString(),
                'lat' => $state->latitude,
                'lng' => $state->longitude,
                'speed_kph' => $state->speed_kph,
                'heading_deg' => $state->heading_deg,
                'battery_pct' => $state->battery_pct,
                'consent_blocked' => $state->consent_blocked,
            ] : null,
            'telemetry' => $telemetry->map(fn($t) => [
                'id' => $t->id,
                'occurred_at' => optional($t->occurred_at)->toISOString(),
                'lat' => $t->latitude,
                'lng' => $t->longitude,
                'speed_kph' => $t->speed_kph,
                'event_type' => $t->event_type,
                'motion_status' => $t->motion_status,
            ])->values(),
            'signals' => $signals->map(fn($s) => [
                'id' => $s->id,
                'signal_type' => $s->signal_type,
                'severity' => $s->severity_hint,
                'occurred_at' => optional($s->occurred_at)->toISOString(),
                'payload' => $s->payload,
            ])->values(),
            'trips' => $trips->map(fn($trip) => [
                'id' => $trip->id,
                'started_at' => optional($trip->started_at)->toISOString(),
                'ended_at' => optional($trip->ended_at)->toISOString(),
                'distance_km' => $trip->distance_km,
                'status' => $trip->status,
            ])->values(),
            'geofences' => $asset->geofences->map(fn($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'type' => $g->type,
                'breach_type' => $g->breach_type,
                'is_active' => $g->is_active,
                'shape' => $g->shape,
            ])->values(),
        ]);
    }
}
