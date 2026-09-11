<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Events\FleetSignalEmitted;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Models\Asset;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetVehicleStateSnapshot;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FleetSignalService
{
    public function offlineEpisodeKey(FleetVehicleStateSnapshot $state): string
    {
        return hash('sha256', implode('|', [
            'fleet-offline', $state->asset_id, $state->last_event_id,
            $state->last_seen_at?->toISOString(),
        ]));
    }

    public function emitOffline(FleetVehicleStateSnapshot $state): FleetSignal
    {
        $key = $this->offlineEpisodeKey($state);
        $event = $state->last_event_id ? FleetTelemetryEvent::query()->find($state->last_event_id) : null;
        $scope = $event && (int) $event->asset_id === (int) $state->asset_id
            ? $this->availabilityScope($event) : null;

        return $this->emit([
            'asset_id' => $state->asset_id, 'device_id' => $scope['device_id'] ?? null,
            'signal_type' => 'device.offline', 'severity_hint' => 'medium',
            'occurred_at' => now(), 'idempotency_key' => $key,
            'payload' => [
                'last_seen_at' => $state->last_seen_at?->toISOString(),
                'availability' => ['version' => 1, 'episode_key' => $key,
                    'source_event_id' => $event?->id, 'scope' => $scope],
            ],
        ]);
    }

    /** Called inside ingestion's transaction, using the locked pre-heartbeat state. */
    public function emitRecovery(FleetVehicleStateSnapshot $previous, FleetTelemetryEvent $event): ?FleetSignal
    {
        if ($previous->status !== 'offline') {
            return null;
        }
        $key = $this->offlineEpisodeKey($previous);
        $offline = FleetSignal::query()->where('idempotency_key', $key)->first();
        $scope = $this->availabilityScope($event);
        $availability = [
            'version' => 1, 'episode_key' => $key, 'source_event_id' => $previous->last_event_id === null ? null : (int) $previous->last_event_id,
            'scope' => $scope, 'offline_signal_id' => $offline?->id,
            'recovery_event_id' => $event->id,
        ];

        return $this->emit([
            'asset_id' => $event->asset_id, 'device_id' => $event->device_id,
            'signal_type' => 'device.online', 'severity_hint' => 'low',
            'occurred_at' => $event->received_at, 'idempotency_key' => hash('sha256', 'fleet-recovery|'.$key.'|'.$event->id),
            'payload' => ['availability' => $availability],
        ]);
    }

    /** Minimal hardware/Site evidence; never includes location, person or trip data. */
    public function availabilityScope(FleetTelemetryEvent $event, bool $lock = false): ?array
    {
        $device = Device::query()->whereKey($event->device_id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if ($device === null || strtolower((string) $device->provider) !== strtolower((string) $event->vendor)) {
            return null;
        }
        $links = DeviceAssetLink::query()->where('device_id', $device->id)->active()->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())->limit(2)->get();
        if ($links->count() !== 1 || (int) $links->first()->asset_id !== (int) $event->asset_id
            || $links->first()->linked_at?->isFuture()) {
            return null;
        }
        $asset = Asset::query()->whereKey($event->asset_id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $site = $asset ? Site::query()->whereKey($asset->home_site_id ?: $asset->site_id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first() : null;
        if ($site === null || ! $site->is_active || $site->archived || $site->archived_at !== null) {
            return null;
        }

        return ['device_id' => (int) $device->id, 'asset_link_id' => (int) $links->first()->id, 'site_id' => (int) $site->id];
    }

    /** Recheck source episode and current canonical scope at delayed publication. */
    public function offlineScopeIsCurrent(FleetSignal $offline): bool
    {
        $data = $offline->payload['availability'] ?? null;
        if ($offline->signal_type !== 'device.offline' || ! is_array($data) || ($data['version'] ?? null) !== 1
            || ! is_int($data['source_event_id'] ?? null) || ($data['episode_key'] ?? null) !== $offline->idempotency_key
            || ! $this->validOfflineEpisodeKey($offline, $data)) {
            return false;
        }
        $event = FleetTelemetryEvent::query()->find($data['source_event_id']);

        return $event !== null && (int) $event->asset_id === (int) $offline->asset_id
            && (int) $event->device_id === (int) $offline->device_id
            && $this->sameAvailabilityScope($this->availabilityScope($event, lock: true), $data['scope'] ?? null);
    }

    public function matchedOfflineForRecovery(FleetSignal $recovery): ?FleetSignal
    {
        $data = $recovery->payload['availability'] ?? null;
        if ($recovery->signal_type !== 'device.online' || ! is_array($data) || ($data['version'] ?? null) !== 1
            || ! is_int($data['offline_signal_id'] ?? null) || ! is_int($data['recovery_event_id'] ?? null)
            || ! is_int($data['source_event_id'] ?? null)
            || ! is_array($data['scope'] ?? null)) {
            return null;
        }
        $offline = FleetSignal::query()->whereKey($data['offline_signal_id'])->lockForUpdate()->first();
        $original = $offline?->payload['availability'] ?? null;
        if ($offline === null || $offline->signal_type !== 'device.offline'
            || (int) $offline->asset_id !== (int) $recovery->asset_id || ! is_array($original)
            || ($original['version'] ?? null) !== 1 || ! $this->sameAvailabilityScope($original['scope'] ?? null, $data['scope'])
            || ($original['source_event_id'] ?? null) !== ($data['source_event_id'] ?? null)
            || ($original['episode_key'] ?? null) !== $offline->idempotency_key
            || ($data['episode_key'] ?? null) !== $offline->idempotency_key
            || ! $this->validOfflineEpisodeKey($offline, $original)
            || $recovery->idempotency_key !== hash('sha256', 'fleet-recovery|'.$offline->idempotency_key.'|'.$data['recovery_event_id'])) {
            return null;
        }
        $event = FleetTelemetryEvent::query()->find($data['recovery_event_id']);
        $priorEvent = FleetTelemetryEvent::query()->find($data['source_event_id']);
        if ($event === null || $priorEvent === null || (int) $event->asset_id !== (int) $offline->asset_id
            || (int) $priorEvent->asset_id !== (int) $offline->asset_id
            || (int) $event->device_id !== (int) $priorEvent->device_id
            || (int) $offline->device_id !== (int) $priorEvent->device_id
            || (int) $recovery->device_id !== (int) $event->device_id
            || $event->received_at === null || $event->received_at->lt($offline->occurred_at)
            || $recovery->occurred_at === null || ! $event->received_at->equalTo($recovery->occurred_at)
            || ! $this->sameAvailabilityScope($this->availabilityScope($event, lock: true), $data['scope'])) {
            return null;
        }

        return $offline;
    }

    public function recoveryFor(FleetSignal $offline): ?FleetSignal
    {
        $recoveries = FleetSignal::query()->where('asset_id', $offline->asset_id)->where('signal_type', 'device.online')
            ->where('payload->availability->offline_signal_id', $offline->id)->orderBy('occurred_at')->orderBy('id')->get();

        return $recoveries->first(fn (FleetSignal $recovery): bool => $this->matchedOfflineForRecovery($recovery)?->id === $offline->id);
    }

    private function validOfflineEpisodeKey(FleetSignal $offline, array $data): bool
    {
        $lastSeen = $offline->payload['last_seen_at'] ?? null;
        if (! is_string($lastSeen) || ! is_int($data['source_event_id'] ?? null)) {
            return false;
        }
        try {
            $seen = Carbon::parse($lastSeen);
        } catch (Throwable) {
            return false;
        }

        return $seen->lt($offline->occurred_at)
            && $offline->idempotency_key === hash('sha256', implode('|', [
                'fleet-offline', $offline->asset_id, $data['source_event_id'], $seen->toISOString(),
            ]));
    }

    private function sameAvailabilityScope(mixed $left, mixed $right): bool
    {
        if (! is_array($left) || ! is_array($right)) {
            return false;
        }
        ksort($left);
        ksort($right);

        return $left === $right;
    }

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

        $dispatch = function () use ($outboxId, $signal): void {
            try {
                DispatchFleetSignalOutbox::dispatch($outboxId);
            } catch (Throwable $exception) {
                // The source and outbox are durable; the recovery sweep can retry.
                Log::error('Fleet safety signal queue dispatch failed', [
                    'fleet_signal_id' => $signal->id,
                    'outbox_id' => $outboxId,
                    'error' => $exception->getMessage(),
                ]);
            }
        };
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
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
