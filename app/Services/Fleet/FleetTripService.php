<?php

namespace App\Services\Fleet;

use App\Models\FleetTelemetryEvent;
use App\Models\FleetTrip;
use App\Models\FleetTripSegment;
use App\Models\FleetVehicleStateSnapshot;
use App\Jobs\ReverseGeocodeFleetTrip;
use Illuminate\Support\Carbon;

class FleetTripService
{
    public function __construct(protected FleetDistance $distance)
    {
    }

    public function handleTelemetry(
        FleetTelemetryEvent $event,
        FleetVehicleStateSnapshot $state,
        ?FleetTelemetryEvent $previousEvent = null
    ): void
    {
        $startSpeed = (float) config('fleet.trip.start_speed_kph', 5);
        $stopSpeed = (float) config('fleet.trip.stop_speed_kph', 2);
        $stopAfterMinutes = (int) config('fleet.trip.stop_after_minutes', 5);
        $idleAfterMinutes = (int) config('fleet.behaviour.idle_after_minutes', 2);

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

            $this->startSegment($openTrip, $event);
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
                    $this->endOpenSegment($openTrip, $event);
                    if (config('fleet.maps.reverse_geocode_enabled')) {
                        ReverseGeocodeFleetTrip::dispatch($openTrip->id);
                    }
                    $openTrip = null;
                }
            }

            if ($openTrip) {
                $this->updateSegments($openTrip, $event, $previousEvent, $moving, $stopped, $idleAfterMinutes);
                $openTrip->save();
                $state->last_trip_id = $openTrip->id;
            }
        }
    }

    protected function startSegment(FleetTrip $trip, FleetTelemetryEvent $event): void
    {
        $seq = 1;

        FleetTripSegment::create([
            'fleet_trip_id' => $trip->id,
            'seq' => $seq,
            'started_at' => $event->occurred_at ?? now(),
            'distance_km' => 0,
            'duration_s' => 0,
            'polyline' => $this->encodePolylinePoint($event),
        ]);
    }

    protected function updateSegments(
        FleetTrip $trip,
        FleetTelemetryEvent $event,
        ?FleetTelemetryEvent $previousEvent,
        bool $moving,
        bool $stopped,
        int $idleAfterMinutes
    ): void {
        $currentSegment = FleetTripSegment::query()
            ->where('fleet_trip_id', $trip->id)
            ->orderByDesc('seq')
            ->first();

        if ($moving) {
            if (!$currentSegment || $currentSegment->ended_at) {
                $nextSeq = $currentSegment ? $currentSegment->seq + 1 : 1;
                $currentSegment = FleetTripSegment::create([
                    'fleet_trip_id' => $trip->id,
                    'seq' => $nextSeq,
                    'started_at' => $event->occurred_at ?? now(),
                    'distance_km' => 0,
                    'duration_s' => 0,
                    'polyline' => $this->encodePolylinePoint($event),
                ]);
            } else {
                if ($event->latitude !== null && $event->longitude !== null) {
                    $points = $this->decodePolylinePoints($currentSegment->polyline);
                    $points[] = $this->buildPoint($event);
                    $currentSegment->polyline = json_encode($points);
                }
            }

            if (
                $previousEvent &&
                $previousEvent->latitude !== null &&
                $previousEvent->longitude !== null &&
                $event->latitude !== null &&
                $event->longitude !== null
            ) {
                $segmentDistance = $this->distance->kmBetween(
                    $previousEvent->latitude,
                    $previousEvent->longitude,
                    $event->latitude,
                    $event->longitude
                );

                if ($segmentDistance > 0) {
                    $currentSegment->distance_km += $segmentDistance;
                }
            }

            if ($event->occurred_at && $currentSegment->started_at) {
                $currentSegment->duration_s = max(
                    0,
                    $event->occurred_at->diffInSeconds($currentSegment->started_at)
                );
            }

            $currentSegment->save();
        }

        if ($stopped && $currentSegment && !$currentSegment->ended_at && $event->occurred_at) {
            $lastMovingAt = $trip->started_at;
            if ($event->occurred_at && $previousEvent?->occurred_at) {
                $lastMovingAt = $previousEvent->occurred_at;
            }

            if ($event->occurred_at->diffInMinutes($lastMovingAt) >= $idleAfterMinutes) {
                $currentSegment->update([
                    'ended_at' => $event->occurred_at,
                    'duration_s' => $event->occurred_at->diffInSeconds($currentSegment->started_at),
                ]);
            }
        }
    }

    protected function endOpenSegment(FleetTrip $trip, FleetTelemetryEvent $event): void
    {
        $segment = FleetTripSegment::query()
            ->where('fleet_trip_id', $trip->id)
            ->whereNull('ended_at')
            ->orderByDesc('seq')
            ->first();

        if ($segment && $event->occurred_at) {
            $segment->update([
                'ended_at' => $event->occurred_at,
                'duration_s' => $event->occurred_at->diffInSeconds($segment->started_at),
            ]);
        }
    }

    protected function buildPoint(FleetTelemetryEvent $event): array
    {
        return [
            'lat' => (float) $event->latitude,
            'lng' => (float) $event->longitude,
            't' => $event->occurred_at?->toISOString(),
        ];
    }

    protected function encodePolylinePoint(FleetTelemetryEvent $event): ?string
    {
        if ($event->latitude === null || $event->longitude === null) {
            return null;
        }

        return json_encode([$this->buildPoint($event)]);
    }

    protected function decodePolylinePoints(?string $polyline): array
    {
        if (!$polyline) {
            return [];
        }

        $decoded = json_decode($polyline, true);
        return is_array($decoded) ? $decoded : [];
    }
}
