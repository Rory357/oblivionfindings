<?php

namespace App\Services\Fleet;

use App\Models\AssetTelemetrySnapshot;
use App\Models\AssetTracker;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetVehicleStateSnapshot;
use App\Services\Fleet\Telemetry\AdapterRegistry;
use App\Services\Fleet\FleetDrivingMetricsService;
use Illuminate\Support\Facades\DB;

class FleetTelemetryIngestService
{
    public function __construct(
        protected AdapterRegistry $adapters,
        protected FleetGeofenceService $geofences,
        protected FleetSignalService $signals,
        protected FleetTripService $trips,
        protected FleetDrivingMetricsService $metrics
    ) {
    }

    public function ingest(string $vendor, array $payload): array
    {
        $adapter = $this->adapters->adapterFor($vendor);
        $normalized = $adapter->normalize($payload);

        if (!$normalized['device_uid']) {
            return ['ok' => false, 'error' => 'device_uid missing', 'status' => 422];
        }

        $tracker = AssetTracker::query()
            ->where('vendor', $vendor)
            ->where('device_uid', $normalized['device_uid'])
            ->where('status', 'paired')
            ->first();

        if (!$tracker) {
            return ['ok' => false, 'error' => 'tracker not found', 'status' => 404];
        }

        $asset = $tracker->asset;

        $consentValid = $tracker->consent ? $tracker->consent->isValid() : false;
        $consentBlocked = !$consentValid;

        $idempotencyKey = $this->buildIdempotencyKey($vendor, $normalized, $payload);

        $existing = FleetTelemetryEvent::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return ['ok' => true, 'id' => $existing->id, 'duplicate' => true];
        }

        return DB::transaction(function () use ($asset, $tracker, $vendor, $normalized, $payload, $consentBlocked, $idempotencyKey) {
            $occurredAt = $normalized['occurred_at'] ?? now();

            $event = FleetTelemetryEvent::create([
                'asset_id' => $asset->id,
                'asset_tracker_id' => $tracker->id,
                'vendor' => $vendor,
                'vendor_message_id' => $normalized['vendor_message_id'] ?? null,
                'occurred_at' => $occurredAt,
                'received_at' => now(),
                'latitude' => $consentBlocked ? null : $normalized['latitude'],
                'longitude' => $consentBlocked ? null : $normalized['longitude'],
                'accuracy_m' => $consentBlocked ? null : $normalized['accuracy_m'],
                'speed_kph' => $consentBlocked ? null : $normalized['speed_kph'],
                'heading_deg' => $consentBlocked ? null : $normalized['heading_deg'],
                'altitude_m' => $consentBlocked ? null : $normalized['altitude_m'],
                'ignition' => $normalized['ignition'],
                'motion_status' => $normalized['motion_status'],
                'battery_pct' => $normalized['battery_pct'],
                'external_power' => $normalized['external_power'],
                'odometer_km' => $normalized['odometer_km'],
                'event_type' => $normalized['event_type'],
                'idempotency_key' => $idempotencyKey,
                'raw_payload' => $normalized['raw_payload'] ?? $payload,
                'consent_blocked' => $consentBlocked,
            ]);

            AssetTelemetrySnapshot::updateOrCreate(
                ['vendor_payload_hash' => $idempotencyKey],
                [
                    'asset_id' => $asset->id,
                    'asset_tracker_id' => $tracker->id,
                    'occurred_at' => $occurredAt,
                    'received_at' => now(),
                    'latitude' => $consentBlocked ? null : $normalized['latitude'],
                    'longitude' => $consentBlocked ? null : $normalized['longitude'],
                    'accuracy_m' => $consentBlocked ? null : $normalized['accuracy_m'],
                    'speed_kph' => $consentBlocked ? null : $normalized['speed_kph'],
                    'movement_status' => $normalized['motion_status'],
                    'battery_pct' => $normalized['battery_pct'],
                    'power_source' => $normalized['external_power'] ? 'external' : ($normalized['battery_pct'] !== null ? 'battery' : null),
                    'tamper_flag' => (bool) ($normalized['tamper_flag'] ?? false),
                    'sos_flag' => (bool) ($normalized['sos_flag'] ?? false),
                    'vendor_payload_hash' => $idempotencyKey,
                    'vendor_metadata' => $normalized['raw_payload'] ?? $payload,
                    'consent_blocked' => $consentBlocked,
                ]
            );

            $tracker->update(['last_seen_at' => now()]);

            $state = FleetVehicleStateSnapshot::query()
                ->firstOrNew(['asset_id' => $asset->id]);

            $previousEvent = $state->last_event_id
                ? FleetTelemetryEvent::query()->find($state->last_event_id)
                : null;

            $state->fill([
                'last_event_id' => $event->id,
                'last_seen_at' => now(),
                'latitude' => $consentBlocked ? null : $normalized['latitude'],
                'longitude' => $consentBlocked ? null : $normalized['longitude'],
                'speed_kph' => $consentBlocked ? null : $normalized['speed_kph'],
                'heading_deg' => $consentBlocked ? null : $normalized['heading_deg'],
                'ignition' => $normalized['ignition'],
                'motion_status' => $normalized['motion_status'],
                'battery_pct' => $normalized['battery_pct'],
                'status' => 'online',
                'consent_blocked' => $consentBlocked,
            ]);

            $this->trips->handleTelemetry($event, $state, $previousEvent);
            $this->metrics->handleTelemetry($event, $previousEvent, $state);
            $state->save();

            if (!empty($normalized['sos_flag'])) {
                $this->signals->emit([
                    'asset_id' => $asset->id,
                    'asset_tracker_id' => $tracker->id,
                    'signal_type' => 'vehicle.sos',
                    'severity_hint' => 'critical',
                    'occurred_at' => $occurredAt,
                    'payload' => [
                        'event_id' => $event->id,
                        'vendor' => $vendor,
                    ],
                ]);
            }

            if (!empty($normalized['tamper_flag'])) {
                $this->signals->emit([
                    'asset_id' => $asset->id,
                    'asset_tracker_id' => $tracker->id,
                    'signal_type' => 'device.tamper',
                    'severity_hint' => 'high',
                    'occurred_at' => $occurredAt,
                    'payload' => [
                        'event_id' => $event->id,
                        'vendor' => $vendor,
                    ],
                ]);
            }

            if (!$consentBlocked && $normalized['latitude'] !== null && $normalized['longitude'] !== null) {
                $this->geofences->evaluate(
                    $asset,
                    (float) $normalized['latitude'],
                    (float) $normalized['longitude'],
                    $occurredAt
                );
            }

            return ['ok' => true, 'id' => $event->id];
        });
    }

    protected function buildIdempotencyKey(string $vendor, array $normalized, array $payload): string
    {
        $occurredAt = $normalized['occurred_at'];
        if ($occurredAt instanceof Carbon) {
            $occurredAt = $occurredAt->toISOString();
        }

        $base = implode('|', [
            $vendor,
            $normalized['device_uid'] ?? '',
            $normalized['vendor_message_id'] ?? '',
            $normalized['event_type'] ?? '',
            $occurredAt ?? '',
            $normalized['latitude'] ?? '',
            $normalized['longitude'] ?? '',
            json_encode($payload),
        ]);

        return hash('sha256', $base);
    }

    // geofence debounce moved into FleetGeofenceService
}
