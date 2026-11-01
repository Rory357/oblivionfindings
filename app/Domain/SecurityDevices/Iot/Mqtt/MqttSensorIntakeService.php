<?php

namespace App\Domain\SecurityDevices\Iot\Mqtt;

use App\Domain\Monitoring\Enums\RuntimeMessageType;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\MonitoringOutboxPublisher;
use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Alerts\SafetyEventObserver;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\ControlRoom\SignalSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ingests MQTT sensor messages for a single authorised Site into canonical `Device` readings & `DeviceEvent`s.
 */
final class MqttSensorIntakeService
{
    /**
     * When a reading is out of its usual range (S&D Phase E): at or over `warning_at` / `critical_at`,
     * or under `below` / over `above`. The sensor page draws these same numbers as its alert lines.
     */
    public const array READING_LIMITS = [
        'co2' => ['warning_at' => 1000, 'critical_at' => 1500],
        'temperature' => ['below' => 12, 'above' => 32],
        'humidity' => ['below' => 25, 'above' => 75],
    ];

    public function __construct(
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly MonitoringOutboxPublisher $outbox,
    ) {}

    /**
     * @return array{
     *     accepted: bool,
     *     reason_code: string,
     *     device_id: int|null,
     *     readings_updated: int,
     *     events_created: int
     * }
     */
    public function ingest(
        int $siteId,
        string $topic,
        string $rawPayload,
        ?CarbonImmutable $receivedAt = null,
    ): array {
        if ($siteId < 1 || trim($topic) === '' || strlen($rawPayload) > MqttV311Client::MAX_PAYLOAD_BYTES) {
            return [
                'accepted' => false,
                'reason_code' => 'invalid_input',
                'device_id' => null,
                'readings_updated' => 0,
                'events_created' => 0,
            ];
        }

        $at = ($receivedAt ?? CarbonImmutable::now('UTC'))->utc();
        $data = $this->parsePayload($topic, $rawPayload);
        if ($data === []) {
            return [
                'accepted' => false,
                'reason_code' => 'unparseable_payload',
                'device_id' => null,
                'readings_updated' => 0,
                'events_created' => 0,
            ];
        }

        $device = $this->resolveSensorDevice($siteId, $topic, $data);
        if (! $device instanceof Device) {
            return [
                'accepted' => false,
                'reason_code' => 'unmatched_sensor',
                'device_id' => null,
                'readings_updated' => 0,
                'events_created' => 0,
            ];
        }

        return $this->applyTelemetry($device, $siteId, 'mqtt', $topic, $data, $at);
    }

    /**
     * Shared telemetry writer used by both MQTT sensor intake and Milesight LoRaWAN gateway intake.
     *
     * @param  array<string, mixed>  $data
     * @return array{
     *     accepted: bool,
     *     reason_code: string,
     *     device_id: int,
     *     readings_updated: int,
     *     events_created: int
     * }
     */
    public function applyTelemetry(
        Device $device,
        int $siteId,
        string $sourceProtocol,
        string $channelRef,
        array $data,
        CarbonImmutable $at,
    ): array {
        $readingDefs = [
            'temperature' => ['label' => 'Temperature', 'unit' => 'C'],
            'humidity' => ['label' => 'Humidity', 'unit' => '%'],
            'co2' => ['label' => 'CO2', 'unit' => 'ppm'],
            'tvoc' => ['label' => 'TVOC', 'unit' => 'ppb'],
            'pressure' => ['label' => 'Barometric pressure', 'unit' => 'hPa'],
            'illuminance' => ['label' => 'Light level', 'unit' => 'lx'],
            'pm25' => ['label' => 'PM2.5', 'unit' => 'ug/m3'],
            'people_count' => ['label' => 'Occupancy count', 'unit' => 'people'],
            'rssi' => ['label' => 'Radio signal (RSSI)', 'unit' => 'dBm'],
            'snr' => ['label' => 'Signal-to-noise (SNR)', 'unit' => 'dB'],
        ];

        $newReadings = [];
        foreach ($readingDefs as $key => $spec) {
            $rawVal = $data[$key] ?? null;
            if (is_int($rawVal) || is_float($rawVal) || (is_string($rawVal) && is_numeric($rawVal))) {
                $num = round((float) $rawVal, 2);
                if (! is_finite($num)) {
                    continue;
                }
                $newReadings[$key] = [
                    'label' => $spec['label'],
                    'value' => $num,
                    'unit' => $spec['unit'],
                    'state' => $this->evaluateReadingState($key, $num),
                    'observed_at' => $at->toISOString(),
                ];
            }
        }

        // S&D Phase F: a bed sensor's in or out of bed, kept as 1 (in bed) or 0 (out of bed) so a
        // linked falls plan can tell night staff (Care\CareRules). No alert limits of its own.
        $inBed = self::bedOccupancy($data);
        if ($inBed !== null) {
            $newReadings['occupancy'] = [
                'label' => 'In bed',
                'value' => $inBed ? 1.0 : 0.0,
                'unit' => '',
                'state' => 'healthy',
                'observed_at' => $at->toISOString(),
            ];
        }

        $battery = null;
        $rawBattery = $data['battery'] ?? $data['battery_level'] ?? null;
        if (is_int($rawBattery) || is_float($rawBattery) || (is_string($rawBattery) && is_numeric($rawBattery))) {
            $battery = max(0, min(100, (int) round((float) $rawBattery)));
        }

        $eventsToCreate = $this->extractDiscreteEvents($data);

        $readingChanges = [];
        $eventsCreated = DB::transaction(function () use (
            &$readingChanges,
            $device,
            $siteId,
            $sourceProtocol,
            $channelRef,
            $newReadings,
            $battery,
            $eventsToCreate,
            $at,
        ): int {
            /** @var Device $locked */
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
            $meta = is_array($locked->meta) ? $locked->meta : [];
            $observed = is_array($meta['observed'] ?? null) ? $meta['observed'] : [];
            $existingReadings = is_array($observed['sensor_readings'] ?? null) ? $observed['sensor_readings'] : [];

            // S&D Phase E: a reading that changes state opens or closes its alert (after this commit).
            foreach ($newReadings as $key => $reading) {
                $before = is_array($existingReadings[$key] ?? null) ? (string) ($existingReadings[$key]['state'] ?? 'healthy') : 'healthy';
                if ($before !== $reading['state']) {
                    $readingChanges[$key] = $reading;
                }
            }
            $mergedReadings = array_replace($existingReadings, $newReadings);
            $observed['sensor_readings'] = $mergedReadings;
            $observed['last_telemetry_protocol'] = $sourceProtocol;
            $observed['last_telemetry_channel'] = substr($channelRef, 0, 180);
            $observed['last_telemetry_at'] = $at->toISOString();
            $meta['observed'] = $observed;

            $updates = [
                'status' => DeviceStatus::Active,
                'health_status' => ($battery !== null && $battery <= 15) ? HealthStatus::Warning : HealthStatus::Healthy,
                'last_seen_at' => $at,
                'meta' => $meta,
            ];
            if ($battery !== null) {
                $updates['battery_level'] = $battery;
                $updates['battery_updated_at'] = $at;
            }

            $locked->forceFill($updates)->save();

            $createdCount = 0;
            $hasSignalSource = SignalSource::query()->where('slug', 'security_devices')->exists();

            foreach ($eventsToCreate as $evt) {
                $eventAttributes = [
                    'device_id' => $locked->id,
                    'event_type' => $evt['event_type'],
                    'severity' => $evt['severity'],
                    'source' => $sourceProtocol,
                    'occurred_at' => $at,
                    'payload' => $evt['payload'],
                ];

                $deviceEvent = $hasSignalSource
                    ? DeviceEvent::query()->create($eventAttributes)
                    : DeviceEvent::withoutEvents(fn () => DeviceEvent::query()->create($eventAttributes));
                if (! $hasSignalSource) {
                    // Model events were off for Control Room's bridge; the S&D safety alert still opens.
                    app(SafetyEventObserver::class)->created($deviceEvent);
                }

                try {
                    $this->outbox->stage(
                        type: RuntimeMessageType::Event,
                        stream: (string) config('monitoring.queues.events', 'monitoring-events'),
                        source: "central:{$sourceProtocol}:site:{$siteId}:device:{$locked->id}",
                        idempotencyKey: 'iot:'.hash('sha256', implode('|', [
                            'iot_event',
                            $sourceProtocol,
                            $locked->id,
                            $deviceEvent->id,
                            $evt['event_type'],
                        ])),
                        payload: [
                            'event_family' => 'iot_sensor',
                            'site_id' => $siteId,
                            'device_id' => (int) $locked->id,
                            'device_event_id' => $deviceEvent->id,
                            'event_type' => $evt['event_type'],
                            'severity' => $evt['severity'],
                            'protocol' => $sourceProtocol,
                        ],
                    );
                } catch (\RuntimeException) {
                    // Local dev environments without a Redis sequence lock store still persist the canonical DeviceEvent.
                }
                $createdCount++;
            }

            return $createdCount;
        });

        // S&D Phase E: readings over time, for the sensor page graphs and the battery forecast.
        app(\App\Domain\SecurityDevices\Iot\Readings\SensorReadingHistory::class)->record(
            (int) $device->id,
            [...array_map(fn (array $reading): float => (float) $reading['value'], $newReadings), ...($battery !== null ? ['battery' => $battery] : [])],
            $at,
        );
        $alerts = app(DeviceAlertEngine::class);
        foreach ($readingChanges as $key => $reading) {
            $alerts->onReading($device, (string) $key, (string) $reading['label'], (string) $reading['state'], (float) $reading['value'], (string) $reading['unit'], $at);
        }
        if ($battery !== null) {
            $alerts->onBattery($device, $battery, $at);
        }

        return [
            'accepted' => true,
            'reason_code' => 'ingested',
            'device_id' => (int) $device->id,
            'readings_updated' => count($newReadings),
            'events_created' => $eventsCreated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parsePayload(string $topic, string $rawPayload): array
    {
        $trimmed = trim($rawPayload);
        if ($trimmed === '') {
            return [];
        }

        if (str_starts_with($trimmed, '{')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded) && ! array_is_list($decoded)) {
                return $decoded;
            }
        }

        if (is_numeric($trimmed)) {
            $leaf = strtolower((string) last(explode('/', trim($topic, '/'))));
            if (in_array($leaf, ['temperature', 'humidity', 'co2', 'pressure', 'illuminance', 'battery'], true)) {
                return [$leaf => (float) $trimmed];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveSensorDevice(int $siteId, string $topic, array $data): ?Device
    {
        $candidates = Device::query()
            ->whereIn('domain', ['iot_healthcare', 'security', 'facilities'])
            ->get()
            ->filter(fn (Device $device): bool => $this->sites->resolve((int) $device->id) === $siteId);

        $payloadId = strtolower(trim((string) ($data['device_uid'] ?? $data['devEUI'] ?? $data['dev_eui'] ?? $data['sensor_id'] ?? '')));

        foreach ($candidates as $device) {
            $configuredTopic = trim((string) (
                data_get($device->config, 'mqtt.topic')
                ?? data_get($device->meta, 'mqtt_topic')
                ?? ''
            ));
            if ($configuredTopic !== '' && MqttV311Client::topicMatchesFilter($topic, $configuredTopic)) {
                return $device;
            }

            if ($payloadId !== '') {
                $uid = strtolower(trim((string) $device->device_uid));
                $extId = strtolower(trim((string) (data_get($device->external_ref, 'dev_eui') ?? data_get($device->external_ref, 'mqtt_client_id') ?? '')));
                if ($payloadId === $uid || ($extId !== '' && $payloadId === $extId)) {
                    return $device;
                }
            }

            $topicParts = array_map('strtolower', explode('/', trim($topic, '/')));
            $uid = strtolower(trim((string) $device->device_uid));
            if ($uid !== '' && in_array($uid, $topicParts, true)) {
                return $device;
            }
        }

        return null;
    }

    private function evaluateReadingState(string $metric, float $value): string
    {
        $limits = self::READING_LIMITS[$metric] ?? [];

        return match (true) {
            isset($limits['critical_at']) && $value >= $limits['critical_at'] => 'critical',
            isset($limits['warning_at']) && $value >= $limits['warning_at'] => 'warning',
            isset($limits['below']) && $value < $limits['below'] => 'warning',
            isset($limits['above']) && $value > $limits['above'] => 'warning',
            default => 'healthy',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{event_type: string, severity: string, payload: array<string, scalar|null>}>
     */
    /**
     * In bed (true), out of bed (false), or null when the message says nothing about a bed. Reads
     * `in_bed`, `bed_status` and `occupancy` (occupied / vacant), as bed sensors send them.
     *
     * @param  array<string, mixed>  $data
     */
    public static function bedOccupancy(array $data): ?bool
    {
        foreach (['in_bed', 'bed_status', 'occupancy'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $raw = $data[$key];
            if (is_bool($raw)) {
                return $raw;
            }
            if (is_int($raw) || (is_string($raw) && in_array($raw, ['0', '1'], true))) {
                return (int) $raw === 1;
            }
            $word = is_string($raw) ? strtolower(trim($raw)) : '';

            return match ($word) {
                'in_bed', 'in bed', 'occupied', 'on', 'true', 'present' => true,
                'out_of_bed', 'out of bed', 'leave', 'left', 'vacant', 'off', 'false', 'absent', 'empty' => false,
                default => null,
            };
        }

        return null;
    }

    private function extractDiscreteEvents(array $data): array
    {
        $events = [];

        if (array_key_exists('door_contact', $data) || array_key_exists('contact', $data)) {
            $raw = $data['door_contact'] ?? $data['contact'];
            $isOpen = $raw === 'open' || $raw === 1 || $raw === false && array_key_exists('contact', $data);
            $events[] = [
                'event_type' => $isOpen ? 'door_opened' : 'door_closed',
                'severity' => 'info',
                'payload' => ['contact_state' => $isOpen ? 'open' : 'closed'],
            ];
        }

        if (($data['water_leak'] ?? $data['leak'] ?? null) === true
            || ($data['water_leak'] ?? $data['leak'] ?? null) === 'leak'
            || ($data['water_leak'] ?? $data['leak'] ?? null) === 1) {
            $events[] = [
                'event_type' => 'water_leak_detected',
                'severity' => 'high',
                'payload' => ['leak_state' => 'leak'],
            ];
        }

        if (($data['tamper'] ?? null) === true || ($data['tamper'] ?? null) === 'tamper' || ($data['tamper'] ?? null) === 1) {
            $events[] = [
                'event_type' => 'tamper',
                'severity' => 'high',
                'payload' => ['tamper_state' => 'tamper'],
            ];
        }

        $button = strtolower(trim((string) ($data['button_action'] ?? $data['action'] ?? '')));
        if (in_array($button, ['single', 'single_press', 'double', 'double_press', 'long', 'long_press', 'pressed', 'panic'], true)) {
            $isEmergency = in_array($button, ['long', 'long_press', 'panic'], true);
            $events[] = [
                'event_type' => $isEmergency ? 'panic' : 'button_pressed',
                'severity' => $isEmergency ? 'critical' : 'warning',
                'payload' => ['button_action' => $button],
            ];
        }

        return $events;
    }
}
