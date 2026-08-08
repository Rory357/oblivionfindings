<?php

namespace Tests\Feature\Integrations;

use App\Domain\Monitoring\Models\MonitoringOutbox;
use App\Domain\Monitoring\Services\MonitoringEnvelopeConsumer;
use App\Http\Controllers\Api\WebhookReceiverController;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\IntegrationProviderConnection;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Services\Integration\AlertRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class WebhookReceiverTest extends TestCase
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
            'active_key_id' => 'webhook-test-key',
            'keys' => [
                'webhook-test-key' => base64_encode(str_repeat("\x42", SODIUM_CRYPTO_AUTH_KEYBYTES)),
            ],
        ]);
    }

    public function test_missing_integration_key_is_rejected(): void
    {
        $this->postJson('/webhooks/unifi', $this->payload())
            ->assertUnauthorized()
            ->assertJson(['error' => 'Webhook rejected']);

        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_disabled_provider_rejects_webhook_intake_without_staging_evidence(): void
    {
        $key = 'disabled-unifi-secret-1234';
        $connection = $this->createProviderConnection(provider: 'unifi', key: $key);
        $connection->update([
            'status' => IntegrationProviderConnection::STATUS_DISABLED,
            'requires_credential_replacement' => true,
        ]);
        $payload = $this->payload();

        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key))
            ->assertUnauthorized()
            ->assertJson(['error' => 'Webhook rejected']);

        $this->assertDatabaseCount('monitoring_outbox', 0);
        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_invalid_integration_key_is_rejected(): void
    {
        $this->createProviderConnection(provider: 'unifi', key: 'correct-secret-1234');

        $payload = $this->payload();
        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, 'wrong-secret-9999'))
            ->assertUnauthorized()
            ->assertJson(['error' => 'Webhook rejected']);

        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_invalid_webhook_signature_is_rejected(): void
    {
        $key = 'unifi-secret-1234';
        $this->createProviderConnection(provider: 'unifi', key: $key);

        $payload = $this->payload();
        $headers = $this->signedHeaders($payload, $key);
        $headers['X-Webhook-Signature'] = 'sha256='.hash_hmac('sha256', 'different body', $key);

        $this->postJson('/webhooks/unifi', $payload, $headers)
            ->assertUnauthorized()
            ->assertJson(['error' => 'Webhook rejected']);

        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_valid_hardware_webhook_is_persisted_and_routed(): void
    {
        $key = 'unifi-secret-1234';
        $site = Site::factory()->create();
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $site);
        $this->mockRouting(times: 1);

        $payload = $this->payload(siteId: $site->id, eventId: 'evt-1001');

        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key))
            ->assertAccepted()
            ->assertJson(['status' => 'accepted']);

        $this->assertDatabaseCount('integration_events', 0);
        $this->consumeStagedEvent($site->id);

        $this->assertDatabaseHas('integration_events', [
            'site_id' => $site->id,
            'provider' => 'unifi',
            'source_event_id' => 'evt-1001',
            'event_type' => 'doorbell.ring',
            'raw_payload' => null,
        ]);
    }

    public function test_duplicate_source_event_id_is_idempotent_across_the_application(): void
    {
        $key = 'unifi-secret-1234';
        $site = Site::factory()->create();
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $site);
        $this->mockRouting(times: 1);

        $payload = $this->payload(siteId: $site->id, eventId: 'evt-1001');

        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key, 'nonce-for-first-event-1001'))
            ->assertAccepted()
            ->assertJson(['status' => 'accepted']);

        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key, 'nonce-for-second-event-1001'))
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        $this->consumeStagedEvent($site->id);
        $this->assertDatabaseCount('integration_events', 1);
    }

    public function test_same_provider_source_event_id_is_an_application_wide_duplicate_across_canonical_sites(): void
    {
        $providerKey = 'application-provider-key-1234';
        $firstSite = Site::factory()->create();
        $secondSite = Site::factory()->create();
        $this->createProviderConnection(provider: 'unifi', key: $providerKey);
        $this->mapProviderToSite('unifi', $firstSite);
        $this->mapProviderToSite('unifi', $secondSite);
        $this->mockRouting(times: 1);

        $firstPayload = $this->payload(siteId: $firstSite->id, eventId: 'evt-shared');
        $this->postJson('/webhooks/unifi', $firstPayload, $this->signedHeaders($firstPayload, $providerKey, 'nonce-for-first-shared-event'))
            ->assertAccepted()
            ->assertJson(['status' => 'accepted']);
        $this->consumeStagedEvent($firstSite->id);

        $secondPayload = $this->payload(siteId: $secondSite->id, eventId: 'evt-shared');
        $this->postJson('/webhooks/unifi', $secondPayload, $this->signedHeaders($secondPayload, $providerKey, 'nonce-for-second-shared-event'))
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        $this->assertDatabaseHas('integration_events', [
            'site_id' => $firstSite->id,
            'source_event_id' => 'evt-shared',
        ]);
        $this->assertDatabaseCount('integration_events', 1);
    }

    public function test_authenticated_webhook_cannot_forge_an_unmapped_site(): void
    {
        $key = 'unifi-secret-1234';
        $mappedSite = Site::factory()->create();
        $unmappedSite = Site::factory()->create();
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $mappedSite);
        $this->mockRouting(times: 0);

        $payload = $this->payload(siteId: $unmappedSite->id);
        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key))
            ->assertUnprocessable();

        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_authenticated_webhook_cannot_reactivate_an_archived_site_mapping(): void
    {
        $key = 'unifi-secret-1234';
        $site = Site::factory()->create(['archived_at' => now()]);
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $site);
        $this->mockRouting(times: 0);

        $payload = $this->payload(siteId: $site->id);
        $this->postJson('/webhooks/unifi', $payload, $this->signedHeaders($payload, $key))
            ->assertUnprocessable();

        $this->assertDatabaseCount('monitoring_outbox', 0);
        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_invalid_provider_timestamp_is_not_written_to_logs(): void
    {
        Log::spy();
        $controller = new class extends WebhookReceiverController
        {
            public function parseProviderTimestamp(mixed $value): ?Carbon
            {
                return $this->parseTimestamp($value);
            }
        };

        $this->assertNotNull($controller->parseProviderTimestamp('RAW-PROVIDER-TIMESTAMP-SECRET'));

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'unparseable timestamp')
                    && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'RAW-PROVIDER-TIMESTAMP-SECRET');
            })
            ->once();
    }

    public function test_expired_timestamp_and_replayed_nonce_are_rejected_before_persistence(): void
    {
        $key = 'unifi-secret-1234';
        $site = Site::factory()->create();
        $this->createProviderConnection(provider: 'unifi', key: $key);
        $this->mapProviderToSite('unifi', $site);
        $payload = $this->payload(siteId: $site->id);

        $expired = $this->signedHeaders($payload, $key, 'nonce-for-expired-event', now()->subMinutes(10)->timestamp);
        $this->postJson('/webhooks/unifi', $payload, $expired)->assertUnauthorized();

        $headers = $this->signedHeaders($payload, $key, 'nonce-for-replayed-event');
        $this->postJson('/webhooks/unifi', $payload, $headers)->assertAccepted();
        $this->postJson('/webhooks/unifi', $payload, $headers)->assertUnauthorized();

        $this->assertDatabaseCount('monitoring_outbox', 1);
        $this->assertDatabaseCount('integration_events', 0);
    }

    public function test_provider_without_verified_webhook_capability_is_hidden(): void
    {
        $this->postJson('/webhooks/queclink', [])->assertNotFound();
        $this->postJson('/webhooks/not-registered', [])->assertNotFound();
        $this->assertDatabaseCount('monitoring_outbox', 0);
    }

    private function createProviderConnection(string $provider, string $key): IntegrationProviderConnection
    {
        return IntegrationProviderConnection::create([
            'provider' => $provider,
            'secret_encrypted' => Crypt::encryptString($key),
            'secret_last4' => substr($key, -4),
            'status' => IntegrationProviderConnection::STATUS_CONNECTED,
        ]);
    }

    private function mapProviderToSite(string $provider, Site $site): IntegrationSiteConfig
    {
        return IntegrationSiteConfig::create([
            'site_id' => $site->id,
            'provider' => $provider,
            'mapped_external_site_id' => 'unifi-site-'.$site->id,
            'status' => IntegrationSiteConfig::STATUS_HYBRID,
            'is_active' => true,
        ]);
    }

    private function mockRouting(int $times): void
    {
        $this->mock(AlertRoutingService::class, function (MockInterface $mock) use ($times): void {
            $mock->shouldReceive('processEvent')
                ->times($times)
                ->withArgs(fn (IntegrationEvent $event): bool => $event->exists)
                ->andReturnNull();
        });
    }

    /** @param array<string, mixed> $payload @return array<string, string> */
    private function signedHeaders(
        array $payload,
        string $key,
        string $nonce = 'nonce-for-webhook-event-1001',
        ?int $timestamp = null,
    ): array {
        $timestamp ??= now()->timestamp;
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return [
            'X-Integration-Key' => $key,
            'X-Webhook-Timestamp' => (string) $timestamp,
            'X-Webhook-Nonce' => $nonce,
            'X-Webhook-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $key),
        ];
    }

    private function consumeStagedEvent(int $siteId): void
    {
        $outbox = MonitoringOutbox::query()->latest('id')->firstOrFail();
        app(MonitoringEnvelopeConsumer::class)->consume(
            'event-projector',
            $outbox->envelope_bytes,
            $siteId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?int $siteId = null, string $eventId = 'evt-1001'): array
    {
        return [
            '_id' => $eventId,
            'site_id' => 'unifi-site-'.($siteId ?? Site::factory()->create()->id),
            'time' => now()->getTimestampMs(),
            'severity' => 'warning',
            'key' => 'doorbell.ring',
            'msg' => 'Front entry doorbell rang',
            'subsystem' => 'protect',
            'mac' => 'aa:bb:cc:dd:ee:ff',
        ];
    }
}
