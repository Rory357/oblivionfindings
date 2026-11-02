<?php

namespace App\Domain\SecurityDevices\Iot\Relay\Services;

use App\Domain\Monitoring\Exceptions\RuntimePayloadInvalid;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Iot\Mqtt\MqttSensorIntakeService;
use App\Domain\SecurityDevices\Iot\Mqtt\MqttV311Client;
use App\Domain\SecurityDevices\Iot\Relay\Models\SensorGatewayLogin;
use Carbon\CarbonImmutable;

/**
 * S&D Phase B (D21): a sensor reading a Site hub relayed. It goes into the same intake as a reading
 * from the sensor broker, with the time the hub received it, so readings held while the link was
 * down keep their real times.
 *
 * The Site comes from the gateway's login, never from the message, and the login may only use its
 * own topic.
 */
final class SensorRelayIntakeService
{
    /** How old a held reading may be: the hub keeps at least 24 hours, plus margin. */
    public const int MAX_AGE_SECONDS = 172_800;

    public function __construct(private readonly MqttSensorIntakeService $intake) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{accepted: bool, reason_code: string}
     */
    public function record(MonitoringCollector $collector, array $payload): array
    {
        if (array_keys($payload) !== ['item_type', 'username', 'topic', 'payload_base64', 'received_at']
            || $payload['item_type'] !== 'sensor_reading'
            || ! is_string($payload['username']) || preg_match('/\Agw-[a-z0-9]{12}\z/', $payload['username']) !== 1
            || ! is_string($payload['topic']) || $payload['topic'] === '' || strlen($payload['topic']) > 255
            || preg_match('/[#+\x00]/', $payload['topic']) === 1
            || ! is_string($payload['payload_base64']) || ! is_string($payload['received_at'])) {
            throw new RuntimePayloadInvalid('Relayed sensor reading fields are invalid.');
        }
        $raw = base64_decode($payload['payload_base64'], true);
        if (! is_string($raw) || strlen($raw) > MqttV311Client::MAX_PAYLOAD_BYTES) {
            throw new RuntimePayloadInvalid('Relayed sensor reading payload is invalid.');
        }
        try {
            $receivedAt = CarbonImmutable::parse($payload['received_at'])->utc();
        } catch (\Throwable) {
            throw new RuntimePayloadInvalid('Relayed sensor reading time is invalid.');
        }
        $now = CarbonImmutable::now('UTC');
        if ($receivedAt->gt($now->addMinutes(5)) || $receivedAt->lt($now->subSeconds(self::MAX_AGE_SECONDS))) {
            return ['accepted' => false, 'reason_code' => 'outside_time_window'];
        }

        $login = SensorGatewayLogin::query()->where('username', $payload['username'])->first();
        if (! $login instanceof SensorGatewayLogin
            // A revoked login's readings received before it was revoked still count.
            || ($login->revoked_at !== null && $receivedAt->gte($login->revoked_at))
            || ! in_array((int) $login->site_id, $collector->authorisedSiteIds(), true)
            || ! str_starts_with($payload['topic'], $login->topic_prefix)) {
            return ['accepted' => false, 'reason_code' => 'login_scope'];
        }

        $result = $this->intake->ingest((int) $login->site_id, $payload['topic'], $raw, $receivedAt);
        if ($result['accepted'] && ($login->last_reading_at === null || $receivedAt->gt($login->last_reading_at))) {
            $login->forceFill(['last_reading_at' => $receivedAt])->saveQuietly();
        }

        return ['accepted' => $result['accepted'], 'reason_code' => $result['reason_code'] ?? 'accepted'];
    }
}
