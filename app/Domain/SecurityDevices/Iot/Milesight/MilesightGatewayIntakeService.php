<?php

namespace App\Domain\SecurityDevices\Iot\Milesight;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Iot\Mqtt\MqttSensorIntakeService;
use App\Domain\SecurityDevices\Models\Device;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Ingests Milesight UG56 / UG65 / UG67 LoRaWAN gateway HTTP(S) & MQTT uplink JSON envelopes.
 *
 * Official Milesight gateway uplink guide:
 * - https://support.milesight-iot.com/kb/guide-1065.html
 */
final class MilesightGatewayIntakeService
{
    public function __construct(
        private readonly CanonicalDeviceSiteResolver $sites,
        private readonly MilesightLorawanPayloadDecoder $decoder,
        private readonly MqttSensorIntakeService $telemetryWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $envelope
     * @return array{
     *     accepted: bool,
     *     reason_code: string,
     *     device_id: int|null,
     *     dev_eui: string|null,
     *     readings_updated: int,
     *     events_created: int
     * }
     */
    public function ingestUplink(int $siteId, array $envelope, ?CarbonImmutable $receivedAt = null): array
    {
        if ($siteId < 1) {
            return $this->rejected('invalid_site', null);
        }

        $devEui = $this->extractDevEui($envelope);
        if ($devEui === null) {
            return $this->rejected('missing_dev_eui', null);
        }

        $device = $this->resolveLorawanDevice($siteId, $devEui);
        if (! $device instanceof Device) {
            return $this->rejected('unmatched_dev_eui', $devEui);
        }

        $decoded = [];
        $preDecoded = $envelope['object'] ?? $envelope['decoded'] ?? null;
        if (is_array($preDecoded) && ! array_is_list($preDecoded)) {
            $decoded = $preDecoded;
        }

        $rawData = $envelope['data'] ?? $envelope['payload'] ?? null;
        if (is_string($rawData) && trim($rawData) !== '') {
            try {
                $tlvFields = $this->decoder->decodeEncoded($rawData, $device->model);
                $decoded = array_replace($decoded, $tlvFields);
            } catch (Throwable) {
                if ($decoded === []) {
                    return $this->rejected('invalid_payload_encoding', $devEui);
                }
            }
        }

        $radio = $this->extractRadioMetrics($envelope);
        $merged = array_replace($radio, $decoded);

        if ($merged === []) {
            return $this->rejected('empty_telemetry', $devEui);
        }

        $at = ($receivedAt ?? CarbonImmutable::now('UTC'))->utc();
        $applied = $this->telemetryWriter->applyTelemetry(
            device: $device,
            siteId: $siteId,
            sourceProtocol: 'milesight_lorawan',
            channelRef: 'deveui:'.$devEui,
            data: $merged,
            at: $at,
        );

        return [
            'accepted' => $applied['accepted'],
            'reason_code' => $applied['reason_code'],
            'device_id' => $applied['device_id'],
            'dev_eui' => $devEui,
            'readings_updated' => $applied['readings_updated'],
            'events_created' => $applied['events_created'],
        ];
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function extractDevEui(array $envelope): ?string
    {
        $raw = $envelope['devEUI']
            ?? $envelope['devEui']
            ?? $envelope['dev_eui']
            ?? data_get($envelope, 'deviceInfo.devEui')
            ?? data_get($envelope, 'end_device_ids.dev_eui')
            ?? null;

        if (! is_string($raw)) {
            return null;
        }

        $normalised = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $raw));

        return strlen($normalised) === 16 ? $normalised : null;
    }

    private function resolveLorawanDevice(int $siteId, string $devEui): ?Device
    {
        $candidates = Device::query()
            ->whereIn('domain', ['iot_healthcare', 'security', 'facilities'])
            ->get()
            ->filter(fn (Device $device): bool => $this->sites->resolve((int) $device->id) === $siteId);

        foreach ($candidates as $device) {
            $possible = [
                data_get($device->config, 'lorawan.dev_eui'),
                data_get($device->external_ref, 'dev_eui'),
                data_get($device->meta, 'dev_eui'),
                $device->device_uid,
            ];

            foreach ($possible as $candidate) {
                if (! is_string($candidate) || trim($candidate) === '') {
                    continue;
                }
                $clean = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $candidate));
                if ($clean === $devEui) {
                    return $device;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return array<string, float>
     */
    private function extractRadioMetrics(array $envelope): array
    {
        $metrics = [];

        $rssi = $envelope['rssi'] ?? data_get($envelope, 'rxInfo.0.rssi') ?? null;
        if (is_int($rssi) || is_float($rssi) || (is_string($rssi) && is_numeric($rssi))) {
            $metrics['rssi'] = round((float) $rssi, 1);
        }

        $snr = $envelope['snr'] ?? $envelope['loRaSNR'] ?? data_get($envelope, 'rxInfo.0.loRaSNR') ?? data_get($envelope, 'rxInfo.0.snr') ?? null;
        if (is_int($snr) || is_float($snr) || (is_string($snr) && is_numeric($snr))) {
            $metrics['snr'] = round((float) $snr, 1);
        }

        return $metrics;
    }

    /**
     * @return array{
     *     accepted: false,
     *     reason_code: string,
     *     device_id: null,
     *     dev_eui: string|null,
     *     readings_updated: 0,
     *     events_created: 0
     * }
     */
    private function rejected(string $reasonCode, ?string $devEui): array
    {
        return [
            'accepted' => false,
            'reason_code' => $reasonCode,
            'device_id' => null,
            'dev_eui' => $devEui,
            'readings_updated' => 0,
            'events_created' => 0,
        ];
    }
}
