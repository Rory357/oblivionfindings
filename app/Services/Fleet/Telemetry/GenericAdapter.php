<?php

namespace App\Services\Fleet\Telemetry;

use App\Services\TelemetryNormalizer;

class GenericAdapter implements TelemetryAdapterInterface
{
    public function __construct(
        protected TelemetryNormalizer $normalizer,
        protected string $vendor
    ) {
    }

    public function normalize(array $payload): array
    {
        $normalized = $this->normalizer->normalize($this->vendor, $payload);

        return [
            'device_uid' => $normalized['device_uid'],
            'vendor_message_id' => $payload['message_id'] ?? $payload['msg_id'] ?? null,
            'occurred_at' => $normalized['occurred_at'],
            'latitude' => $normalized['latitude'],
            'longitude' => $normalized['longitude'],
            'accuracy_m' => $normalized['accuracy_m'],
            'speed_kph' => $normalized['speed_kph'],
            'heading_deg' => $payload['heading'] ?? $payload['course'] ?? null,
            'altitude_m' => $payload['altitude'] ?? null,
            'ignition' => $payload['ignition'] ?? null,
            'motion_status' => $normalized['movement_status'],
            'battery_pct' => $normalized['battery_pct'],
            'external_power' => $payload['external_power'] ?? null,
            'odometer_km' => $payload['odometer_km'] ?? $payload['odometer'] ?? null,
            'event_type' => $payload['event_type'] ?? null,
            'raw_payload' => $normalized['vendor_metadata'] ?? $payload,
            'sos_flag' => (bool) ($payload['sos'] ?? $payload['sos_flag'] ?? false),
            'tamper_flag' => (bool) ($payload['tamper'] ?? $payload['tamper_flag'] ?? false),
        ];
    }
}
