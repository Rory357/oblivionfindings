<?php

namespace Tests\Feature\Integrations;

use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Services\MonitoringEnvelopeConsumer;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Exceptions\SafetySignalUnroutable;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalRule;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\SensorIncidentBridgeService;
use App\Services\Integration\AlertRoutingService;
use App\Services\Integration\Contracts\WebhookVerificationCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use App\Services\Integration\IntegrationSignalNormaliser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

final class MilesightWebhookContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set('cache.default', 'array');
        config()->set('integration-capabilities.webhook.replay_store', 'array');
        config()->set('integration-capabilities.webhook.allow_local_replay_store_for_tests', true);
        config()->set('monitoring.delivery.sequence_lock_store', 'array');
        config()->set('monitoring.delivery.allow_local_sequence_lock_for_tests', true);
        config()->set('monitoring.signing', [
            'active_key_id' => 'milesight-webhook-test-key',
            'keys' => [
                'milesight-webhook-test-key' => base64_encode(str_repeat("\x4d", SODIUM_CRYPTO_AUTH_KEYBYTES)),
            ],
        ]);
    }

    public function test_official_signed_batch_is_staged_and_projected_through_the_common_runtime(): void
    {
        [$site, $device, $secret] = $this->mappedDevice();
        $this->mock(AlertRoutingService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('processEvent')
                ->twice()
                ->withArgs(fn (IntegrationEvent $event): bool => $event->exists)
                ->andReturnNull();
        });

        $this->assertTrue(app(IntegrationAdapterRegistry::class)
            ->hasCapability('milesight', WebhookVerificationCapability::class));

        $payload = $this->payload((string) $device->external_ref['provider_entity_id']);
        $response = $this->postJson('/webhooks/milesight', $payload, $this->headers($secret));

        $response->assertOk()->assertJson([
            'status' => 'accepted',
            'accepted' => 2,
            'duplicates' => 0,
        ]);
        $this->assertDatabaseCount('monitoring_outbox', 2);
        $this->assertDatabaseCount('integration_events', 0);

        foreach (MonitoringOutbox::query()->orderBy('id')->get() as $outbox) {
            app(MonitoringEnvelopeConsumer::class)->consume(
                'event-projector',
                $outbox->envelope_bytes,
                $site->id,
            );
        }

        $this->assertDatabaseHas('integration_events', [
            'site_id' => $site->id,
            'provider' => 'milesight',
            'source_event_id' => 'msc-event-offline-1001',
            'event_type' => 'device_offline',
            'severity' => IntegrationEvent::SEVERITY_WARN,
            'raw_payload' => null,
        ]);
        $this->assertDatabaseHas('integration_events', [
            'site_id' => $site->id,
            'provider' => 'milesight',
            'source_event_id' => 'msc-event-fall-1002',
            'event_type' => 'fall_detected',
            'severity' => IntegrationEvent::SEVERITY_CRITICAL,
            'raw_payload' => null,
        ]);

        $encoded = MonitoringOutbox::query()->pluck('envelope_bytes')->implode(' ')
            .IntegrationEvent::query()->get()->toJson();
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString('24D124707E04SECRET', $encoded);
    }

    public function test_invalid_signature_replay_and_unmapped_device_fail_before_any_staging(): void
    {
        [, $device, $secret] = $this->mappedDevice();
        $payload = $this->payload((string) $device->external_ref['provider_entity_id']);

        $invalid = $this->headers($secret);
        $invalid['X-Msc-Request-Signature'] = str_repeat('0', 64);
        $this->postJson('/webhooks/milesight', $payload, $invalid)->assertUnauthorized();
        $this->assertDatabaseCount('monitoring_outbox', 0);

        $headers = $this->headers($secret, 'nonce-for-milesight-replay-1001');
        $this->postJson('/webhooks/milesight', $payload, $headers)->assertOk();
        $this->postJson('/webhooks/milesight', $payload, $headers)->assertUnauthorized();
        $this->assertDatabaseCount('monitoring_outbox', 2);

        MonitoringOutbox::query()->delete();
        $unmapped = $this->payload('9999999999999999999');
        $this->postJson('/webhooks/milesight', $unmapped, $this->headers($secret, 'nonce-for-unmapped-device-1001'))
            ->assertNotFound()
            ->assertJson(['error' => 'Webhook endpoint not found']);
        $this->assertDatabaseCount('monitoring_outbox', 0);
    }

    public function test_verified_falls_keep_device_identity_and_evidence_through_real_routing_and_confirmation(): void
    {
        [$site, $firstDevice, $secret] = $this->mappedDevice();
        $secondDevice = Device::factory()->iotHealthcare()->create([
            'provider' => 'milesight',
            'name' => 'Room 5 resident sensor',
            'external_ref' => [
                'provider' => 'milesight',
                'provider_entity_id' => '1904371063669395458',
                'application_id' => 'application-a',
            ],
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $secondDevice->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
        $fallType = SignalType::query()->where('code', 'fall_detected')->sole();
        SignalRule::query()->create([
            'name' => 'Care fall correlation regression',
            'signal_type_id' => $fallType->id,
            'signal_type_code' => 'fall_detected',
            'conditions' => [],
            'deduplicate' => true,
            'dedup_window_minutes' => 30,
            'priority' => 0,
            'is_active' => true,
        ]);

        foreach ([$firstDevice, $secondDevice] as $index => $device) {
            $fall = $this->payload((string) $device->external_ref['provider_entity_id'])[1];
            $fall['eventID'] = 'msc-distinct-fall-'.$index;
            $this->postJson('/webhooks/milesight', [$fall], $this->headers($secret, 'nonce-distinct-fall-'.$index))
                ->assertOk()->assertJson(['accepted' => 1]);
        }

        // Real provider projection, normalisation, ingestion and processing.
        // Replaying a delivery must not create another alert or source record.
        foreach (MonitoringOutbox::query()->orderBy('id')->get() as $outbox) {
            foreach ([1, 2] as $attempt) {
                app(MonitoringEnvelopeConsumer::class)->consume('event-projector', $outbox->envelope_bytes, $site->id);
            }
        }

        $this->assertDatabaseCount('integration_events', 2);
        $this->assertDatabaseCount('control_room_signals', 2);
        $this->assertDatabaseCount('control_room_alerts', 2);
        $signals = Signal::query()->orderBy('id')->get();
        $this->assertNotSame($signals[0]->alert_id, $signals[1]->alert_id);
        foreach ([$firstDevice, $secondDevice] as $index => $device) {
            $signal = $signals[$index];
            $this->assertSame('fall_detected', $signal->signal_type_code);
            $this->assertSame('critical', $signal->severity_hint);
            $this->assertNull($signal->device_id);
            $this->assertSame($device->id, data_get($signal->normalized_data, 'canonical_device_id'));
            $this->assertSame('fall_detected', data_get($signal->payload, 'reported_values.alarm'));
            $this->assertFalse(data_get($signal->payload, 'reported_values.occupied'));
            $this->assertNotEmpty(data_get($signal->normalized_data, 'source_received_at'));
            $this->assertNotEmpty(data_get($signal->normalized_data, 'evidence_hash'));
        }

        // A person is explicitly identified by the operator, never inferred
        // merely from the device being assigned to the same site.
        $client = Client::factory()->create(['site_id' => $site->id]);
        $operator = User::factory()->create();
        $alert = ControlRoomAlert::query()->findOrFail($signals[0]->alert_id);
        $alert->forceFill(['client_id' => $client->id])->save();
        $this->assertSame('critical', $alert->severity);
        $bridge = app(SensorIncidentBridgeService::class);
        $incident = $bridge->confirm($alert, $operator, ['immediate_action_taken' => 'Person checked and support arranged.']);
        $again = $bridge->confirm($alert->fresh(), $operator);

        $this->assertTrue($again->is($incident));
        $this->assertSame('fall', $incident->type);
        $this->assertSame($firstDevice->id, data_get($incident->metadata, 'sensor_evidence.canonical_device_id'));
        $this->assertSame('fall_detected', data_get($incident->metadata, 'sensor_evidence.payload.reported_values.alarm'));
        $this->assertSame(data_get($signals[0]->normalized_data, 'evidence_hash'), data_get($incident->metadata, 'sensor_evidence.evidence_hash'));
        $this->assertNotNull($incident->hs_event_id);
        $this->assertDatabaseCount((new ClientIncident)->getTable(), 1);
        $this->assertDatabaseCount((new HsEvent)->getTable(), 1);
        $encoded = $signals->toJson().$incident->toJson();
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString('24D124707E04SECRET', $encoded);
    }

    public function test_canonical_fall_severity_is_applied_before_quiet_hours(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        [$site, $device] = $this->mappedDevice();
        $connection = IntegrationProviderConnection::query()->where('provider', 'milesight')->sole();
        $connection->update(['config' => [
            ...$connection->config,
            'quiet_hours_start' => '00:00',
            'quiet_hours_end' => '23:59',
        ]]);
        $event = IntegrationEvent::query()->create([
            'site_id' => $site->id,
            'canonical_device_id' => $device->id,
            'provider' => 'milesight',
            'source_app' => 'milesight-development-platform',
            'source_event_id' => 'quiet-hours-fall',
            'event_type' => 'fall_detected',
            'severity' => IntegrationEvent::SEVERITY_INFO,
            'occurred_at' => now(),
            'received_at' => now(),
            'normalized_payload' => ['summary' => 'Possible fall'],
            'raw_payload' => null,
        ]);

        $alert = app(AlertRoutingService::class)->processEvent($event);

        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('fall_detected', Signal::query()->sole()->signal_type_code);
    }

    public function test_disabled_care_fall_classification_fails_for_repair_instead_of_falling_back_to_unknown(): void
    {
        SignalType::query()->where('code', 'fall_detected')->update(['is_active' => false]);
        $event = new IntegrationEvent(['provider' => 'milesight', 'event_type' => 'fall_detected']);

        $this->expectException(SafetySignalUnroutable::class);
        $this->expectExceptionMessage('The care fall signal type is unavailable.');

        app(IntegrationSignalNormaliser::class)->resolveSignalTypeCode($event);
    }

    public function test_signed_webhook_cannot_reactivate_an_archived_site_mapping(): void
    {
        [$site, $device, $secret] = $this->mappedDevice();
        $site->forceFill(['archived_at' => now()])->save();

        $this->postJson(
            '/webhooks/milesight',
            $this->payload((string) $device->external_ref['provider_entity_id']),
            $this->headers($secret),
        )->assertNotFound()->assertJson(['error' => 'Webhook endpoint not found']);

        $this->assertDatabaseCount('monitoring_outbox', 0);
        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_no_id_batch_replay_uses_stable_per_event_fallback_identities(): void
    {
        [, $device, $secret] = $this->mappedDevice();
        $payload = array_map(static function (array $item): array {
            unset($item['eventID']);

            return $item;
        }, $this->payload((string) $device->external_ref['provider_entity_id']));
        $firstBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $secondBody = json_encode(
            $this->reverseObjectKeys($payload),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
        );

        $this->postRawJson(
            '/webhooks/milesight',
            $firstBody,
            $this->headers($secret, 'nonce-for-milesight-fallback-one'),
        )->assertOk()->assertJson([
            'status' => 'accepted',
            'accepted' => 2,
            'duplicates' => 0,
        ]);
        $this->postRawJson(
            '/webhooks/milesight',
            $secondBody,
            $this->headers($secret, 'nonce-for-milesight-fallback-two'),
        )->assertOk()->assertJson([
            'status' => 'duplicate',
            'accepted' => 0,
            'duplicates' => 2,
        ]);

        $this->assertDatabaseCount('monitoring_outbox', 2);
        $this->assertTrue(MonitoringOutbox::query()->get()->every(
            fn (MonitoringOutbox $outbox): bool => $outbox->source === 'provider:milesight:webhooks',
        ));
    }

    /** @return array{Site, Device, string} */
    private function mappedDevice(): array
    {
        $secret = 'MSC-WEBHOOK-SECRET-9876';
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        IntegrationProviderConnection::query()->create([
            'provider' => 'milesight',
            'secret_encrypted' => Crypt::encryptString('oauth-client-secret'),
            'secret_last4' => 'cret',
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
            'config' => [
                'client_id' => 'client-123',
                'webhook_secret_encrypted' => Crypt::encryptString($secret),
                'webhook_secret_last4' => substr($secret, -4),
            ],
        ]);
        IntegrationSiteConfig::query()->create([
            'site_id' => $site->id,
            'provider' => 'milesight',
            'mapped_external_site_id' => 'application-a',
            'mapped_external_site_name' => 'Care sensors',
            'status' => IntegrationSiteConfig::STATUS_HYBRID,
            'is_active' => true,
        ]);
        $device = Device::factory()->iotHealthcare()->create([
            'provider' => 'milesight',
            'name' => 'Room 4 resident sensor',
            'external_ref' => [
                'provider' => 'milesight',
                'provider_entity_id' => '1904371063669395457',
                'application_id' => 'application-a',
            ],
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        return [$site, $device, $secret];
    }

    /** @return list<array<string, mixed>> */
    private function payload(string $providerDeviceId): array
    {
        $profile = [
            'deviceId' => $providerDeviceId,
            'sn' => '6707E04610160001',
            'devEUI' => '24D124707E04SECRET',
            'name' => 'Room 4 resident sensor',
            'model' => 'Resident Support Sensor',
        ];

        return [
            [
                'eventID' => 'msc-event-offline-1001',
                'eventCreatedTime' => (string) now()->timestamp,
                'eventVersion' => '1.0',
                'eventType' => 'DEVICE_DATA',
                'data' => [
                    'deviceProfile' => $profile,
                    'type' => 'OFFLINE',
                    'tslID' => 'connectivity',
                    'payload' => [],
                ],
            ],
            [
                'eventID' => 'msc-event-fall-1002',
                'eventCreatedTime' => (string) now()->timestamp,
                'eventVersion' => '1.0',
                'eventType' => 'DEVICE_DATA',
                'data' => [
                    'deviceProfile' => $profile,
                    'type' => 'EVENT',
                    'tslID' => 'fall_detected',
                    'payload' => [
                        'alarm' => 'fall_detected',
                        'occupied' => false,
                    ],
                ],
            ],
        ];
    }

    private function reverseObjectKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $reordered = array_map(fn (mixed $item): mixed => $this->reverseObjectKeys($item), $value);

        return array_is_list($reordered) ? $reordered : array_reverse($reordered, true);
    }

    /** @param array<string, string> $headers */
    private function postRawJson(string $uri, string $body, array $headers): TestResponse
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $uri, server: $server, content: $body);
    }

    /** @return array<string, string> */
    private function headers(string $secret, string $nonce = 'nonce-for-milesight-event-1001'): array
    {
        $timestamp = (string) now()->timestamp;

        return [
            'X-Msc-Request-Signature' => hash_hmac('sha256', $timestamp.$nonce, $secret),
            'X-Msc-Webhook-Uuid' => 'webhook-uuid-for-care-sensors-1001',
            'X-Msc-Request-Timestamp' => $timestamp,
            'X-Msc-Request-Nonce' => $nonce,
        ];
    }
}
