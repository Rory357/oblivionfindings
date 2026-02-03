<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetSignal;
use App\Models\FleetTrip;
use App\Models\FleetVehicleStateSnapshot;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FleetDashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $vehicles = Asset::query()
            ->where('category', 'vehicle')
            ->orWhereHas('categoryRef', fn($q) => $q->where('slug', 'vehicle'))
            ->with(['trackers' => fn($q) => $q->where('status', 'paired')])
            ->get(['id', 'name', 'asset_tag', 'category', 'status', 'site_id']);

        $vehicleIds = $vehicles->pluck('id')->all();

        $states = FleetVehicleStateSnapshot::query()
            ->whereIn('asset_id', $vehicleIds)
            ->get()
            ->keyBy('asset_id');

        $openTrips = FleetTrip::query()
            ->whereIn('asset_id', $vehicleIds)
            ->where('status', 'open')
            ->get()
            ->groupBy('asset_id');

        $recentSignals = FleetSignal::query()
            ->whereIn('asset_id', $vehicleIds)
            ->latest('occurred_at')
            ->limit(100)
            ->get()
            ->groupBy('asset_id');

        $rows = $vehicles->map(function ($vehicle) use ($states, $openTrips, $recentSignals) {
            $state = $states->get($vehicle->id);
            $signals = $recentSignals->get($vehicle->id, collect());
            $lastSignal = $signals->first();

            return [
                'id' => $vehicle->id,
                'name' => $vehicle->name,
                'asset_tag' => $vehicle->asset_tag,
                'status' => $vehicle->status,
                'trackers' => $vehicle->trackers->map(fn($t) => [
                    'id' => $t->id,
                    'vendor' => $t->vendor,
                    'device_uid' => $t->device_uid,
                ])->values(),
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
                'open_trip_count' => $openTrips->get($vehicle->id, collect())->count(),
                'last_signal' => $lastSignal ? [
                    'type' => $lastSignal->signal_type,
                    'severity' => $lastSignal->severity_hint,
                    'occurred_at' => optional($lastSignal->occurred_at)->toISOString(),
                ] : null,
            ];
        })->values();

        return Inertia::render('fleet-management/index', [
            'vehicles' => $rows,
        ]);
    }
}
