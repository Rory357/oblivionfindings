<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
use App\Models\FleetDriverSession;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FleetTripController extends Controller
{
    public function show(Request $request, FleetTrip $trip)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('fleet.viewAny'), 403);

        $trip->load(['asset:id,name,asset_tag', 'driverSession.user:id,name']);

        AuditLogger::log('fleet.trip.view', $trip, [
            'trip_id' => $trip->id,
        ]);

        $drivers = FleetDriverSession::query()
            ->where('asset_id', $trip->asset_id)
            ->with('user:id,name')
            ->orderByDesc('started_at')
            ->limit(10)
            ->get();

        return Inertia::render('fleet-management/trip', [
            'trip' => [
                'id' => $trip->id,
                'asset_id' => $trip->asset_id,
                'asset' => $trip->asset ? [
                    'id' => $trip->asset->id,
                    'name' => $trip->asset->name,
                    'asset_tag' => $trip->asset->asset_tag,
                ] : null,
                'driver_session_id' => $trip->driver_session_id,
                'driver' => $trip->driverSession?->user ? [
                    'id' => $trip->driverSession->user->id,
                    'name' => $trip->driverSession->user->name,
                ] : null,
                'started_at' => optional($trip->started_at)->toISOString(),
                'ended_at' => optional($trip->ended_at)->toISOString(),
                'start_latitude' => $trip->start_latitude,
                'start_longitude' => $trip->start_longitude,
                'end_latitude' => $trip->end_latitude,
                'end_longitude' => $trip->end_longitude,
                'distance_km' => $trip->distance_km,
                'duration_s' => $trip->duration_s,
                'status' => $trip->status,
                'consent_blocked' => $trip->consent_blocked,
            ],
            'driver_sessions' => $drivers->map(fn($d) => [
                'id' => $d->id,
                'user' => $d->user ? [
                    'id' => $d->user->id,
                    'name' => $d->user->name,
                ] : null,
                'started_at' => optional($d->started_at)->toISOString(),
                'ended_at' => optional($d->ended_at)->toISOString(),
            ])->values(),
            'can' => [
                'manage' => $user->canDo('fleet.trips.manage'),
            ],
        ]);
    }

    public function playback(Request $request, FleetTrip $trip)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('fleet.viewAny'), 403);

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

    public function update(Request $request, FleetTrip $trip)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('fleet.trips.manage'), 403);

        $data = $request->validate([
            'driver_session_id' => ['nullable', 'integer', 'exists:fleet_driver_sessions,id'],
            'status' => ['nullable', 'string', 'in:open,closed,cancelled'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $trip->update($data);

        AuditLogger::log('fleet.trip.update', $trip, [
            'trip_id' => $trip->id,
            'changes' => $data,
        ]);

        return back()->with('success', 'Trip updated.');
    }

    public function close(Request $request, FleetTrip $trip)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('fleet.trips.manage'), 403);

        if ($trip->status === 'closed') {
            return back()->withErrors(['trip' => 'Trip is already closed.']);
        }

        $trip->update([
            'status' => 'closed',
            'ended_at' => $trip->ended_at ?? now(),
        ]);

        AuditLogger::log('fleet.trip.close', $trip, [
            'trip_id' => $trip->id,
        ]);

        return back()->with('success', 'Trip closed.');
    }

    public function destroy(Request $request, FleetTrip $trip)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('fleet.trips.manage'), 403);

        AuditLogger::log('fleet.trip.delete', $trip, [
            'trip_id' => $trip->id,
            'asset_id' => $trip->asset_id,
        ]);

        $trip->delete();

        return redirect()->route('fleet.index')->with('success', 'Trip deleted.');
    }
}
