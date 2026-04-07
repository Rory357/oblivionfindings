<?php

namespace App\Services\Fleet;

use App\Events\FleetSignalEmitted;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Jobs\DispatchFleetSignalOutbox;
use Illuminate\Support\Carbon;

class FleetSignalService
{
    public function emit(array $payload): FleetSignal
    {
        $idempotencyKey = $payload['idempotency_key'] ?? $this->buildIdempotencyKey($payload);

        $signal = FleetSignal::query()
            ->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'asset_id' => $payload['asset_id'],
                    'asset_tracker_id' => $payload['asset_tracker_id'] ?? null,
                    'geofence_id' => $payload['geofence_id'] ?? null,
                    'trip_id' => $payload['trip_id'] ?? null,
                    'driver_session_id' => $payload['driver_session_id'] ?? null,
                    'signal_type' => $payload['signal_type'],
                    'severity_hint' => $payload['severity_hint'] ?? 'low',
                    'occurred_at' => $payload['occurred_at'] ?? now(),
                    'payload' => $payload['payload'] ?? null,
                ]
            );

        if (!$signal->wasRecentlyCreated) {
            return $signal;
        }

        $outbox = FleetSignalOutbox::create([
            'fleet_signal_id' => $signal->id,
            'status' => 'pending',
        ]);

        DispatchFleetSignalOutbox::dispatch($outbox->id);

        event(new FleetSignalEmitted($signal));

        return $signal;
    }

    protected function buildIdempotencyKey(array $payload): string
    {
        $occurredAt = $payload['occurred_at'] ?? now();
        if ($occurredAt instanceof Carbon) {
            $occurredAt = $occurredAt->toISOString();
        }

        $base = implode('|', [
            $payload['asset_id'] ?? '',
            $payload['asset_tracker_id'] ?? '',
            $payload['signal_type'] ?? '',
            $payload['geofence_id'] ?? '',
            $occurredAt,
            json_encode($payload['payload'] ?? []),
        ]);

        return hash('sha256', $base);
    }
}
