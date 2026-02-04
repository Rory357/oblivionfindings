<?php

namespace App\Services\Fleet;

use App\Models\FleetDrivingMetric;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetVehicleStateSnapshot;

class FleetDrivingMetricsService
{
    public function handleTelemetry(
        FleetTelemetryEvent $event,
        ?FleetTelemetryEvent $previousEvent,
        FleetVehicleStateSnapshot $state
    ): void {
        if ($event->consent_blocked) {
            return;
        }

        $date = $event->occurred_at?->toDateString() ?? now()->toDateString();
        $metric = FleetDrivingMetric::firstOrCreate(
            [
                'asset_id' => $event->asset_id,
                'period_start' => $date,
                'period_end' => $date,
            ],
            [
                'harsh_brake_count' => 0,
                'accel_count' => 0,
                'speeding_events' => 0,
                'idle_minutes' => 0,
                'score' => 100,
            ]
        );

        $speedingKph = (float) config('fleet.behaviour.speeding_kph', 100);
        $idleSpeedKph = (float) config('fleet.behaviour.idle_speed_kph', 3);
        $idleAfterMinutes = (int) config('fleet.behaviour.idle_after_minutes', 2);
        $maxIdleIncrement = (int) config('fleet.behaviour.max_idle_increment_minutes', 15);

        $eventType = $event->event_type ?? '';
        $harshBrakingTypes = ['harsh_braking', 'harsh_brake', 'brake_hard'];
        $harshAccelTypes = ['harsh_acceleration', 'rapid_acceleration', 'accel_hard'];

        $harshBrakeCount = in_array($eventType, $harshBrakingTypes, true) ? 1 : 0;
        $accelCount = in_array($eventType, $harshAccelTypes, true) ? 1 : 0;
        $speedingCount = ($event->speed_kph !== null && $event->speed_kph >= $speedingKph) ? 1 : 0;

        $idleIncrement = 0;
        if (
            $event->speed_kph !== null &&
            $event->speed_kph <= $idleSpeedKph &&
            $event->ignition &&
            $previousEvent &&
            $previousEvent->speed_kph !== null &&
            $previousEvent->speed_kph <= $idleSpeedKph &&
            $previousEvent->ignition &&
            $previousEvent->occurred_at
        ) {
            $idleMinutes = $previousEvent->occurred_at->diffInMinutes($event->occurred_at);
            if ($idleMinutes >= $idleAfterMinutes) {
                $idleIncrement = min($idleMinutes, $maxIdleIncrement);
            }
        }

        $metric->update([
            'harsh_brake_count' => $metric->harsh_brake_count + $harshBrakeCount,
            'accel_count' => $metric->accel_count + $accelCount,
            'speeding_events' => $metric->speeding_events + $speedingCount,
            'idle_minutes' => $metric->idle_minutes + $idleIncrement,
        ]);

        $this->updateScore($metric);
    }

    protected function updateScore(FleetDrivingMetric $metric): void
    {
        $weights = config('fleet.behaviour.score_weights', []);
        $harshBrakeWeight = (int) ($weights['harsh_brake'] ?? 5);
        $accelWeight = (int) ($weights['accel'] ?? 3);
        $speedingWeight = (int) ($weights['speeding'] ?? 4);
        $idleWeight = (float) ($weights['idle'] ?? 0.5);

        $score = 100
            - ($metric->harsh_brake_count * $harshBrakeWeight)
            - ($metric->accel_count * $accelWeight)
            - ($metric->speeding_events * $speedingWeight)
            - (int) round($metric->idle_minutes * $idleWeight);

        $metric->update([
            'score' => max(0, min(100, $score)),
        ]);
    }
}
