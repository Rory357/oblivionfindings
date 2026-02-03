<?php

namespace App\Services\Fleet;

use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
use App\Models\FleetVehicleStateSnapshot;
use Illuminate\Support\Carbon;

class FleetTripService
{
    public function __construct(protected FleetDistance $distance)
    {
    }

    public function handleTelemetry(FleetTelemetryEvent $event, FleetVehicleStateSnapshot $state): void
    {
        $startSpeed = (float) config('fleet.trip.start_speed_kph', 5);
        $stopSpeed = (float) config('fleet.trip.stop_speed_kph', 2);
        $stopAfterMinutes = (int) config('fleet.trip.stop_after_minutes', 5);

        $speed = $event->speed_kph ?? 0;
        $moving = $speed >= $startSpeed;
        $stopped = $speed <= $stopSpeed;

        if ($moving) {
            $state->last_moving_at = $event->occurred_at;
        }

        $openTrip = FleetTrip::query()
            ->where('asset_id', $event->asset_id)
            ->where('status', 'open')
            ->latest('started_at')
            ->first();

        if ($moving && !$openTrip) {
            $openTrip = FleetTrip::create([
                'asset_id' => $event->asset_id,
                'driver_session_id' => null,
                'started_at' => $event->occurred_at ?? now(),
                'start_latitude' => $event->latitude,
                'start_longitude' => $event->longitude,
                'status' => 'open',
                'consent_blocked' => $event->consent_blocked,
            ]);
        }

        if ($openTrip) {
            $distanceKm = $this->distance->kmBetween(
                $state->latitude,
                $state->longitude,
                $event->latitude,
                $event->longitude
            );

            if ($distanceKm > 0) {
                $openTrip->distance_km += $distanceKm;
            }

            if ($event->occurred_at && $openTrip->started_at) {
                $openTrip->duration_s = max(
                    0,
                    $event->occurred_at->diffInSeconds($openTrip->started_at)
                );
            }

            if ($stopped && $state->last_moving_at instanceof Carbon) {
                if ($state->last_moving_at->diffInMinutes($event->occurred_at) >= $stopAfterMinutes) {
                    $openTrip->update([
                        'ended_at' => $event->occurred_at,
                        'end_latitude' => $event->latitude,
                        'end_longitude' => $event->longitude,
                        'status' => 'closed',
                    ]);
                    $state->last_trip_id = $openTrip->id;
                    $openTrip = null;
                }
            }

            if ($openTrip) {
                $openTrip->save();
                $state->last_trip_id = $openTrip->id;
            }
        }
    }
}
