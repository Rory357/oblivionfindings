<?php

namespace App\Services\Fleet;

use App\Events\FleetSignalEmitted;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FleetSignalService
{
    public function emit(array $payload): FleetSignal
    {
        $idempotencyKey = $payload['idempotency_key'] ?? $this->buildIdempotencyKey($payload);

        [$signal, $outboxId, $created] = DB::transaction(function () use ($payload, $idempotencyKey): array {
            $signal = FleetSignal::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'asset_id' => $payload['asset_id'],
                    'asset_tracker_id' => $payload['asset_tracker_id'] ?? null,
                    'device_id' => $payload['device_id'] ?? null,
                    'geofence_id' => $payload['geofence_id'] ?? null,
                    'trip_id' => $payload['trip_id'] ?? null,
                    'driver_session_id' => $payload['driver_session_id'] ?? null,
                    'signal_type' => $payload['signal_type'],
                    'severity_hint' => $payload['severity_hint'] ?? 'low',
                    'occurred_at' => $payload['occurred_at'] ?? now(),
                    'payload' => $payload['payload'] ?? null,
                ]
            );

            $created = $signal->wasRecentlyCreated;
            $outbox = FleetSignalOutbox::query()->firstOrCreate(
                ['fleet_signal_id' => $signal->id],
                ['status' => 'pending'],
            );

            return [$signal, $outbox->id, $created];
        }, 3);

        try {
            DispatchFleetSignalOutbox::dispatch($outboxId);
        } catch (Throwable $exception) {
            // The source and outbox are already durable. The scheduled recovery
            // sweep will re-dispatch this intent without duplicating the alert.
            Log::error('Fleet safety signal queue dispatch failed', [
                'fleet_signal_id' => $signal->id,
                'outbox_id' => $outboxId,
                'error' => $exception->getMessage(),
            ]);
        }

        if ($created) {
            event(new FleetSignalEmitted($signal));
        }

        return $signal;
    }

    protected function buildIdempotencyKey(array $payload): string
    {
        $occurredAt = $payload['occurred_at'] ?? now();
        if ($occurredAt instanceof Carbon) {
            $occurredAt = $occurredAt->toISOString();
        }

        $deviceIdentity = $payload['asset_tracker_id']
            ?? $payload['device_id']
            ?? '';

        $base = implode('|', [
            $payload['asset_id'] ?? '',
            $deviceIdentity,
            $payload['signal_type'] ?? '',
            $payload['geofence_id'] ?? '',
            $occurredAt,
            json_encode($payload['payload'] ?? []),
        ]);

        return hash('sha256', $base);
    }
}
