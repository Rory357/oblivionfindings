<?php

namespace App\Services\Fleet;

use App\Events\FleetVehiclePositionUpdated;
use App\Models\Asset;
use App\Models\AssetTelemetrySnapshot;
use App\Models\AssetTracker;
use App\Models\FleetTelemetryEvent;
use App\Models\FleetVehicleStateSnapshot;
use App\Services\Fleet\FleetDrivingMetricsService;
use App\Services\Fleet\Telemetry\AdapterRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FleetTelemetryIngestService
{
    public function __construct(
        protected AdapterRegistry $adapters,
        protected FleetGeofenceService $geofences,
        protected FleetSignalService $signals,
        protected FleetTripService $trips,
        protected FleetDrivingMetricsService $metrics,
        protected FleetDeviceRuntimeService $deviceRuntime
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
        $device = $this->deviceRuntime->resolveCanonicalDevice($vendor, $normalized, $tracker);
        $consentContext = $device
            ? $this->deviceRuntime->resolveConsentContext($device)
            : null;

        $consent = $consentContext['consent'] ?? $tracker->consent;
        $consentValid = $consent ? $consent->isValid() : false;
        $consentBlocked = !$consentValid && !$this->isFleetOwnedVehicle($asset);

        $idempotencyKey = $this->buildIdempotencyKey($vendor, $normalized, $payload);

        return DB::transaction(function () use ($asset, $tracker, $device, $vendor, $normalized, $payload, $consentBlocked, $idempotencyKey) {
            $existing = FleetTelemetryEvent::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return ['ok' => true, 'id' => $existing->id, 'duplicate' => true];
            }
            $occurredAt = $normalized['occurred_at'] ?? now();

            $event = FleetTelemetryEvent::create([
                'asset_id' => $asset->id,
                'asset_tracker_id' => $tracker->id,
                'device_id' => $device?->id,
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
                    'device_id' => $device?->id,
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

            if ($device) {
                $deviceUpdates = [
                    'last_seen_at' => now(),
                ];

                $meta = $device->meta ?? [];
                $raw = $normalized['raw_payload'] ?? [];

                if ($normalized['battery_pct'] !== null) {
                    $deviceUpdates['battery_level'] = $normalized['battery_pct'];
                    $deviceUpdates['battery_updated_at'] = now();

                    $meta['battery'] = $normalized['battery_pct'];
                    $meta['battery_level'] = $normalized['battery_pct'];
                }

                foreach (['charging_status', 'battery_voltage_mv', 'power_event', 'external_power'] as $key) {
                    if (array_key_exists($key, $raw)) {
                        $meta[$key] = $raw[$key];
                    }
                }

                $threshold = (int) ($meta['battery_low_threshold'] ?? data_get($raw, 'battery_low_threshold', 20));
                $meta['battery_status'] = $normalized['battery_pct'] === null
                    ? ($meta['battery_status'] ?? 'unknown')
                    : ((int) $normalized['battery_pct'] <= $threshold ? 'low' : 'normal');

                if (($meta['charging_status'] ?? null) === 'charging' || ($meta['external_power'] ?? false)) {
                    $meta['battery_status_label'] = 'Charging';
                } elseif ($normalized['battery_pct'] !== null) {
                    unset($meta['battery_status_label']);
                }

                if (!$consentBlocked && $normalized['latitude'] !== null && $normalized['longitude'] !== null) {
                    $deviceUpdates['latitude'] = $normalized['latitude'];
                    $deviceUpdates['longitude'] = $normalized['longitude'];
                    $deviceUpdates['last_signal_at'] = $occurredAt ?? now();

                    $meta['lat'] = $normalized['latitude'];
                    $meta['latitude'] = $normalized['latitude'];
                    $meta['lng'] = $normalized['longitude'];
                    $meta['longitude'] = $normalized['longitude'];
                    $meta['speed'] = $normalized['speed_kph'];
                    $meta['heading'] = $normalized['heading_deg'];
                    $meta['accuracy'] = $normalized['accuracy_m'];
                    $meta['altitude'] = $normalized['altitude_m'];
                    $meta['motion'] = $normalized['motion_status'];
                    $meta['last_location_at'] = $occurredAt instanceof Carbon
                        ? $occurredAt->toISOString()
                        : now()->toISOString();
                }

                $deviceUpdates['meta'] = $meta;

                $device->forceFill($deviceUpdates)->save();
            }

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

            // Broadcast real-time position update via WebSocket (requires Reverb/Pusher)
            if (!$consentBlocked && $normalized['latitude'] !== null) {
                broadcast(new FleetVehiclePositionUpdated(
                    assetId: $asset->id,
                    latitude: (float) $normalized['latitude'],
                    longitude: (float) $normalized['longitude'],
                    speed_kph: $normalized['speed_kph'] ? (float) $normalized['speed_kph'] : null,
                    heading_deg: $normalized['heading_deg'] ? (int) $normalized['heading_deg'] : null,
                    status: 'online',
                    motion_status: $normalized['motion_status'],
                ))->toOthers();
            }

            if (!empty($normalized['sos_flag'])) {
                $this->signals->emit([
                    'asset_id' => $asset->id,
                    'asset_tracker_id' => $tracker->id,
                    'device_id' => $device?->id,
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
                    'device_id' => $device?->id,
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

    // Vehicle trackers on fleet-owned (non-client) assets have legitimate basis
    // under the employment relationship and don't require per-person consent.
    // Client-linked or non-vehicle assets remain default-deny (Privacy Act 2020).
    protected function isFleetOwnedVehicle(Asset $asset): bool
    {
        if ($asset->client_id) {
            return false;
        }

        if ($asset->category === 'vehicle') {
            return true;
        }

        return $asset->categoryRef?->slug === 'vehicle';
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
