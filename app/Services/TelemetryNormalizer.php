<?php

namespace App\Services;

use Carbon\Carbon;

class TelemetryNormalizer
{
    public function normalize(string $vendor, array $payload): array
    {
        $deviceUid = $payload['device_uid']
            ?? $payload['device_id']
            ?? $payload['imei']
            ?? $payload['id']
            ?? null;

        $timestamp = $payload['occurred_at']
            ?? $payload['timestamp']
            ?? $payload['gps_time']
            ?? $payload['time']
            ?? null;

        $occurredAt = $this->parseTimestamp($timestamp);

        $lat = $this->firstValue($payload, ['lat', 'latitude', 'gps.lat', 'location.lat']);
        $lon = $this->firstValue($payload, ['lon', 'lng', 'longitude', 'gps.lon', 'gps.lng', 'location.lon']);
        $accuracy = $this->firstValue($payload, ['accuracy', 'acc', 'hdop', 'gps.accuracy']);

        $speed = $this->firstValue($payload, ['speed', 'speed_kph', 'velocity']);
        $battery = $this->firstValue($payload, ['battery', 'battery_pct', 'battery_level']);
        $powerSource = $payload['power_source'] ?? ($payload['external_power'] ?? null);

        $tamper = $payload['tamper'] ?? $payload['tamper_flag'] ?? false;
        $sos = $payload['sos'] ?? $payload['sos_flag'] ?? false;

        $movementStatus = $payload['movement_status'] ?? null;
        if (!$movementStatus && is_numeric($speed)) {
            $movementStatus = ((float) $speed) > 1.0 ? 'moving' : 'stationary';
        }

        return [
            'vendor' => $vendor,
            'device_uid' => $deviceUid,
            'occurred_at' => $occurredAt,
            'latitude' => $lat,
            'longitude' => $lon,
            'accuracy_m' => $accuracy,
            'speed_kph' => $speed,
            'movement_status' => $movementStatus,
            'battery_pct' => $battery,
            'power_source' => $powerSource,
            'tamper_flag' => (bool) $tamper,
            'sos_flag' => (bool) $sos,
            'vendor_metadata' => $payload,
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

        return Carbon::parse($value);
    }

    protected function firstValue(array $payload, array $keys)
    {
        foreach ($keys as $key) {
            $value = data_get($payload, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
