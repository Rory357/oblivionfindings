<?php

namespace Tests\Feature\Integrations;

use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Services\MonitoringEnvelopeConsumer;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Services\Integration\AlertRoutingService;
use App\Services\Integration\Contracts\WebhookVerificationCapability;
use App\Services\Integration\IntegrationAdapterRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
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
            ->assertUnprocessable();
        $this->assertDatabaseCount('monitoring_outbox', 0);
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
