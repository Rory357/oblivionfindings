<?php

namespace App\Services\Fleet;

use App\Models\FleetDrivingMetric;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetVehicleStateSnapshot;
use App\Support\SchemaCache;

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

        $hasOtherHarsh = SchemaCache::hasColumn('fleet_driving_metrics', 'harsh_other_count');
        $periodDate = $event->occurred_at?->copy()->startOfDay() ?? now()->startOfDay();
        $metric = FleetDrivingMetric::firstOrCreate(
            [
                'asset_id' => $event->asset_id,
                'period_start' => $periodDate,
                'period_end' => $periodDate,
            ],
            [
                'harsh_brake_count' => 0,
                'accel_count' => 0,
                ...($hasOtherHarsh ? ['harsh_other_count' => 0] : []),
                'speeding_events' => 0,
                'idle_minutes' => 0,
                'score' => 100,
            ]
        );

        $speedingKph = (float) config('fleet.behaviour.speeding_kph', 100);
        $idleSpeedKph = (float) config('fleet.behaviour.idle_speed_kph', 3);
        $idleAfterMinutes = (int) config('fleet.behaviour.idle_after_minutes', 2);
        $maxIdleIncrement = (int) config('fleet.behaviour.max_idle_increment_minutes', 15);

        // Queclink reports harsh driving as `harsh_behaviour` and speed alarms
        // as `speed_alarm`; both carry their kind in the report type.
        $eventType = $event->event_type ?? '';
        $reportType = in_array($eventType, FleetDrivingEventClassifier::REPORT_TYPED_EVENTS, true)
            ? FleetDrivingEventClassifier::reportType($event->raw_payload)
            : null;
        $harshKind = FleetDrivingEventClassifier::harshKind($eventType, $reportType);

        $harshBrakeCount = $harshKind === FleetDrivingEventClassifier::BRAKING ? 1 : 0;
        $accelCount = $harshKind === FleetDrivingEventClassifier::ACCELERATION ? 1 : 0;
        // Cornering, or a harsh report whose type was not recorded.
        $harshOtherCount = in_array($harshKind, [FleetDrivingEventClassifier::CORNERING, FleetDrivingEventClassifier::UNCLASSIFIED], true) ? 1 : 0;

        // Speeding counts episodes, not packets: a tracker speed alarm (unless
        // it reports the speed coming back into range), or a sample that
        // crosses the fleet threshold from below.
        $alarmPhase = FleetDrivingEventClassifier::speedAlarmPhase($eventType, $reportType);
        if ($alarmPhase !== null) {
            $speedingCount = $alarmPhase === 'end' ? 0 : 1;
        } else {
            $speedingCount = $event->speed_kph !== null
                && (float) $event->speed_kph >= $speedingKph
                && ($previousEvent?->speed_kph === null || (float) $previousEvent->speed_kph < $speedingKph)
                ? 1 : 0;
        }

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

        if ($harshBrakeCount) $metric->increment('harsh_brake_count', $harshBrakeCount);
        if ($accelCount) $metric->increment('accel_count', $accelCount);
        if ($harshOtherCount && $hasOtherHarsh) $metric->increment('harsh_other_count', $harshOtherCount);
        if ($speedingCount) $metric->increment('speeding_events', $speedingCount);
        if ($idleIncrement) $metric->increment('idle_minutes', $idleIncrement);

        $metric->refresh();

        $this->updateScore($metric);
    }

    protected function updateScore(FleetDrivingMetric $metric): void
    {
        $weights = config('fleet.behaviour.score_weights', []);
        $harshBrakeWeight = (int) ($weights['harsh_brake'] ?? 5);
        $accelWeight = (int) ($weights['accel'] ?? 3);
        $speedingWeight = (int) ($weights['speeding'] ?? 4);
        $idleWeight = (float) ($weights['idle'] ?? 0.5);
        // No weight is configured for cornering or unclassified harsh reports;
        // they take the lower acceleration weight rather than an invented one.
        $otherHarshWeight = (int) ($weights['harsh_other'] ?? $accelWeight);

        $score = 100
            - ($metric->harsh_brake_count * $harshBrakeWeight)
            - ($metric->accel_count * $accelWeight)
            - ((int) ($metric->harsh_other_count ?? 0) * $otherHarshWeight)
            - ($metric->speeding_events * $speedingWeight)
            - (int) round($metric->idle_minutes * $idleWeight);

        $metric->update([
            'score' => max(0, min(100, $score)),
        ]);
    }
}
