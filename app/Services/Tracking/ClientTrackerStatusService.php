<?php

namespace App\Services\Tracking;

use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Client;
use App\Models\FleetTelemetryEvent;
use App\Models\User;
use Carbon\CarbonImmutable;

final class ClientTrackerStatusService
{
    public function __construct(private ClientLocationAccessService $access) {}

    /** Reported evidence only: an event is not a current alert or an enabled sensor. */
    public function read(User $actor, Client $client): array
    {
        $assignment = $this->access->resolve($actor, $client);
        $fingerprint = $this->access->fingerprint($assignment);
        $to = CarbonImmutable::now();
        $from = collect([
            $to->subDays((int) $assignment->retention_days),
            CarbonImmutable::parse($assignment->assigned_at),
            CarbonImmutable::parse($assignment->collection_started_at),
            CarbonImmutable::parse($assignment->consent->given_at),
        ])->max();
        $telemetry = FleetTelemetryEvent::query()
            ->where('device_id', $assignment->device_id)->where('consent_blocked', false)
            ->whereBetween('occurred_at', [$from, $to]);
        $motion = (clone $telemetry)->whereNotNull('motion_status')
            ->orderByDesc('occurred_at')->orderByDesc('id')->first(['motion_status', 'occurred_at']);
        $motionState = $this->motionState($motion?->motion_status);
        $motionTime = $motionState ? $motion->occurred_at->toISOString() : null;

        // The existing ingestion writer updates motion and this timestamp together.
        // Never substitute contact time or reuse evidence from an earlier assignment.
        $meta = $assignment->device->meta ?? [];
        $metaMotion = $this->motionState($meta['motion'] ?? null);
        $metaTime = $this->timestamp($meta['last_location_at'] ?? null);
        if ($metaMotion && $metaTime?->betweenIncluded($from, $to)
            && (! $motion || $metaTime->greaterThan($motion->occurred_at))) {
            $motionState = $metaMotion;
            $motionTime = $metaTime->toISOString();
        }

        // Keep actual fall reports separate from the existing man-down alarm.
        $fallTypes = ['fall_detected', 'man_down'];
        $fleetFall = (clone $telemetry)->whereIn('event_type', $fallTypes)
            ->orderByDesc('occurred_at')->orderByDesc('id')->first(['event_type', 'occurred_at']);
        $deviceFall = DeviceEvent::query()->where('device_id', $assignment->device_id)
            ->whereIn('event_type', $fallTypes)->whereBetween('occurred_at', [$from, $to])
            ->orderByDesc('occurred_at')->orderByDesc('id')->first(['event_type', 'occurred_at']);
        $fall = $deviceFall && (! $fleetFall || $deviceFall->occurred_at->greaterThan($fleetFall->occurred_at))
            ? $deviceFall : $fleetFall;
        $this->access->recheck($actor, $client, $fingerprint);

        return [
            'motion_status' => $motionState,
            'motion_reported_at' => $motionTime,
            'fall_report_type' => $fall?->event_type,
            'fall_reported_at' => $fall?->occurred_at->toISOString(),
        ];
    }

    private function motionState(mixed $value): ?string
    {
        return match ($value) {
            'moving', 'motion' => 'moving',
            'stationary', 'rest' => 'stationary',
            default => null,
        };
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
