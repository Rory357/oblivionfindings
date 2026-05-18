<?php

namespace App\Services\Fleet\Telemetry;

use Carbon\Carbon;

class QueclinkAdapter implements TelemetryAdapterInterface
{
    public function normalize(array $payload): array
    {
        $deviceUid = $payload['imei']
            ?? $payload['device_id']
            ?? $payload['device_uid']
            ?? $payload['id']
            ?? null;

        $occurredAt = $this->parseTimestamp(
            $payload['gps_time'] ?? $payload['time'] ?? $payload['timestamp'] ?? null
        );

        $lat = $payload['lat'] ?? $payload['latitude'] ?? data_get($payload, 'gps.lat');
        $lng = $payload['lng'] ?? $payload['lon'] ?? $payload['longitude'] ?? data_get($payload, 'gps.lng');

        $speed = $payload['speed_kph'] ?? $payload['speed'] ?? null;
        if (isset($payload['speed_kn'])) {
            $speed = (float) $payload['speed_kn'] * 1.852;
        }

        $alarm = strtolower((string) ($payload['alarm'] ?? $payload['event'] ?? ''));
        $eventType = $this->mapEventType($alarm, $payload);

        $sosFlag = (bool) ($payload['sos_flag'] ?? false)
            || in_array($alarm, ['sos', 'panic', 'emergency'], true);
        $tamperFlag = in_array($alarm, ['tamper', 'power_cut', 'powercut', 'cut'], true);

        return [
            'device_uid' => $deviceUid,
            'vendor_message_id' => $payload['message_id'] ?? $payload['msg_id'] ?? null,
            'occurred_at' => $occurredAt,
            'latitude' => $lat,
            'longitude' => $lng,
            'accuracy_m' => $payload['accuracy'] ?? $payload['hdop'] ?? null,
            'speed_kph' => $speed,
            'heading_deg' => $payload['course'] ?? $payload['heading'] ?? null,
            'altitude_m' => $payload['altitude'] ?? null,
            'ignition' => $payload['ignition'] ?? null,
            'motion_status' => $payload['motion'] ?? $payload['movement'] ?? null,
            'battery_pct' => $payload['battery'] ?? $payload['battery_pct'] ?? null,
            'external_power' => $payload['external_power'] ?? null,
            'odometer_km' => $payload['odometer'] ?? $payload['odometer_km'] ?? null,
            'event_type' => $eventType,
            'raw_payload' => $payload,
            'sos_flag' => $sosFlag,
            'tamper_flag' => $tamperFlag,
        ];
    }

    protected function parseTimestamp($value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        try {
            return Carbon::parse($value);
        } catch (\Carbon\Exceptions\InvalidFormatException) {
            return null;
        }
    }

    protected function mapEventType(string $alarm, array $payload): ?string
    {
        if (in_array($alarm, ['sos', 'panic', 'emergency'], true)) {
            return 'vehicle_sos';
        }

        if (in_array($alarm, ['tamper', 'power_cut', 'powercut', 'cut'], true)) {
            return 'tamper';
        }

        if (in_array($alarm, ['motion', 'move'], true)) {
            return 'motion_start';
        }

        if (in_array($alarm, ['stop', 'park'], true)) {
            return 'motion_stop';
        }

        if ($payload['heartbeat'] ?? false) {
            return 'heartbeat';
        }

        return $payload['event_type'] ?? null;
    }
}
